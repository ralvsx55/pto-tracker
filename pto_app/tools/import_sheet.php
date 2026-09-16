<?php
declare(strict_types=1);

/**
 * Sheet snapshot importer (SPEC section 9). Also used by Admin > Import and tools/dev_reset.php.
 *
 * CLI:  php pto_app/tools/import_sheet.php --group=us|manila --file=<snapshot.json> [--commit] [--replace-all]
 *                                          [--as-of=YYYY-MM-DD] [--expected=<expected.json>]
 * Without --commit it is a dry run: everything is written inside a transaction, the acceptance check runs,
 * the report is printed, and the transaction is rolled back. With --commit the transaction is committed
 * only when the acceptance check passes.
 *
 * Function: import_snapshot(int $groupId, array $json, array $opts): array   (see the docblock below)
 */

require_once __DIR__ . '/../lib/bootstrap.php';

/** Sheet-name -> destination mapping per group (SPEC section 9). */
function import_mapping(string $groupKey): array
{
    return match ($groupKey) {
        'us' => [
            'employees'   => 'Employee Start Date',
            'emp_cols'    => ['name' => 0, 'hire' => 1, 'bday' => 2],
            'requests'    => 'Time Requested Off',
            'req_cols'    => ['name' => 0, 'kind' => 1, 'start' => 2, 'end' => 3, 'e' => 4, 'f' => 5],
            'adjustments' => null,
            'events'      => ['Any Additional Events Calendar' => 'us_holidays', 'Factory Closings Calendar' => 'us_factory', 'End Of Month Sale Calendar' => 'us_sales'],
            'factory_cal' => 'us_factory',
        ],
        'manila' => [
            'employees'   => 'Employee Start Date',
            'emp_cols'    => ['name' => 0, 'hire' => 1, 'bday' => 2],
            'requests'    => 'Time Requested Off',
            'req_cols'    => ['name' => 0, 'kind' => 1, 'start' => 2, 'end' => 3, 'e' => 4, 'f' => null],
            'adjustments' => 'PTO Adjustments',
            'events'      => ['Any Additional Events Calendar' => 'mn_events', 'Factory Closings Calendar' => 'mn_factory'],
            'factory_cal' => 'mn_factory',
        ],
        default => throw new InvalidArgumentException("No import mapping for group '$groupKey'"),
    };
}

/** ISO cell ('2024-05-27T00:00:00') -> 'Y-m-d' or null. */
function import_cell_date(mixed $v): ?string
{
    if (!is_string($v) || strlen($v) < 10) {
        return null;
    }
    $d = to_date(substr($v, 0, 10));
    return $d === null ? null : ymd($d);
}

function import_kind(mixed $v): string
{
    return strtolower(trim((string) ($v ?? ''))) === 'vacation' ? 'Vacation' : 'PTO';
}

/** Rows on the factory-closing sheets that really are factory closings (the rest were filed there by mistake in 2023). */
function import_looks_like_factory_closing(string $title): bool
{
    return (bool) preg_match('/factor|chinese|festival|national day|labor day|tomb|dragon/i', $title);
}

/**
 * Import one group's snapshot.
 * $json = the openpyxl dump {"<sheet name>": {"rows": [[...], ...]}}; first row = headers.
 * $opts: 'commit' => bool (default false = dry run), 'replace_all' => bool, 'as_of' => 'Y-m-d' (default: the
 *        group's today), 'expected' => path to an expected_*.json for the balance check (default:
 *        tests/fixtures/expected_<as_of>.json when it exists).
 * Returns the report: ok, committed, counts, warnings, errors, misfiled, acceptance, lines.
 * Section 5 is retired: every event is imported with is_holiday = 0 and groups.holidays_excluded_from is left alone
 * (NULL = the engine's dormant exclusion stays off).
 */
function import_snapshot(int $groupId, array $json, array $opts = []): array
{
    $group = group_by_id($groupId);
    if ($group === null) {
        throw new InvalidArgumentException("Unknown group id $groupId");
    }
    $map = import_mapping((string) $group['group_key']);
    $policy = group_policy($group);
    $commit = (bool) ($opts['commit'] ?? false);
    $replaceAll = (bool) ($opts['replace_all'] ?? false);
    $asOf = to_date($opts['as_of'] ?? null) ?? group_today($group);
    $expectedPath = $opts['expected'] ?? (PTO_APP . '/tests/fixtures/expected_' . ymd($asOf) . '.json');
    if (!is_string($expectedPath) || !is_file($expectedPath)) {
        $expectedPath = null;
    }

    $report = [
        'ok' => true, 'committed' => false, 'group' => $group['group_key'], 'as_of' => ymd($asOf),
        'counts' => ['employees' => 0, 'time_off' => 0, 'adjustments' => 0, 'events' => [], 'misfiled' => 0],
        'warnings' => [], 'errors' => [], 'misfiled' => [],
        'acceptance' => ['rows_checked' => 0, 'row_mismatches' => [], 'balances_checked' => 0, 'balance_mismatches' => [], 'expected_file' => $expectedPath],
        'lines' => [],
    ];
    $warn = static function (string $m) use (&$report): void {
        $report['warnings'][] = $m;
    };

    // --- parse the sheets --------------------------------------------------------------------------
    $rowsOf = static function (string $sheet) use ($json): array {
        $r = $json[$sheet]['rows'] ?? null;
        return is_array($r) ? array_slice($r, 1) : [];
    };
    if (!isset($json[$map['employees']]) || !isset($json[$map['requests']])) {
        $report['ok'] = false;
        $report['errors'][] = "Snapshot is missing the '{$map['employees']}' or '{$map['requests']}' sheet.";
        return import_finish($report);
    }

    $employees = [];   // list of ['row', 'name', 'hire_date', 'birth_month', 'birth_day', 'birth_year']
    $ec = $map['emp_cols'];
    foreach ($rowsOf($map['employees']) as $i => $r) {
        $rowNo = $i + 2;
        $name = trim((string) ($r[$ec['name']] ?? ''));
        if ($name === '') {
            continue;
        }
        $hire = import_cell_date($r[$ec['hire']] ?? null);
        if ($hire === null) {
            $warn("Employee row $rowNo '$name': no hire date, skipped.");
            continue;
        }
        $bday = to_date(import_cell_date($r[$ec['bday']] ?? null));
        $employees[] = [
            'row' => $rowNo, 'name' => $name, 'hire_date' => $hire,
            'birth_month' => $bday?->format('n'),
            'birth_day'   => $bday?->format('j'),
            // placeholder years (>= 2023) mean "year unknown" in the sheets
            'birth_year'  => ($bday !== null && (int) $bday->format('Y') < 2023) ? (int) $bday->format('Y') : null,
        ];
    }

    $requests = [];
    $rc = $map['req_cols'];
    foreach ($rowsOf($map['requests']) as $i => $r) {
        $rowNo = $i + 2;
        $name = trim((string) ($r[$rc['name']] ?? ''));
        if ($name === '') {
            continue;
        }
        $start = import_cell_date($r[$rc['start']] ?? null);
        $end = import_cell_date($r[$rc['end']] ?? null);
        if ($start === null || $end === null) {
            $warn("Time-off row $rowNo '$name': missing or invalid date, skipped.");
            continue;
        }
        if ($end < $start) {
            $warn("Time-off row $rowNo '$name': end $end before start $start, skipped.");
            continue;
        }
        $kind = import_kind($r[$rc['kind']] ?? 'PTO');
        if (!in_array($kind, $policy['kinds'], true)) {
            $warn("Time-off row $rowNo '$name': kind '$kind' not used by this group, imported as {$policy['kinds'][0]}.");
            $kind = $policy['kinds'][0];
        }
        $requests[] = [
            'row' => $rowNo, 'name' => $name, 'kind' => $kind, 'start_date' => $start, 'end_date' => $end,
            'sheet_e' => $r[$rc['e']] ?? null, 'sheet_f' => $rc['f'] === null ? null : ($r[$rc['f']] ?? null),
        ];
    }

    $adjustments = [];
    if ($map['adjustments'] !== null && isset($json[$map['adjustments']])) {
        $hireByName = [];
        foreach ($employees as $e) {
            $hireByName[$e['name']] ??= $e['hire_date'];
        }
        foreach ($rowsOf($map['adjustments']) as $i => $r) {
            $rowNo = $i + 2;
            $name = trim((string) ($r[0] ?? ''));
            if ($name === '') {
                continue;
            }
            $eff = import_cell_date($r[1] ?? null);
            $days = is_numeric($r[2] ?? null) ? (float) $r[2] : 0.0;
            if ($eff === null || $days == 0.0 || !isset($hireByName[$name])) {
                $warn("Adjustment row $rowNo '$name': missing date/days or unknown employee, skipped.");
                continue;
            }
            $applyTo = strtoupper(trim((string) ($r[3] ?? 'CURRENT')));
            $anchored = $eff;
            if ($applyTo === 'NEXT') {
                // NEXT = start of the cycle after the one containing the sheet's effective date (Miguel: 2026-04-22).
                $hire = to_date($hireByName[$name]);
                $anchored = ymd(next_cycle_start($hire, cycle_start($hire, to_date($eff))));
            }
            $adjustments[] = [
                'row' => $rowNo, 'name' => $name, 'kind' => 'PTO', 'effective_date' => $anchored, 'days' => $days,
                'reason' => trim((string) ($r[5] ?? '')) ?: 'Imported adjustment',
                'note' => "sheet effective $eff, apply to $applyTo",
            ];
        }
    }

    $events = [];   // per cal_key
    foreach ($map['events'] as $sheet => $calKey) {
        $events[$calKey] = [];
        if (!isset($json[$sheet])) {
            $warn("Sheet '$sheet' not in the snapshot; nothing imported to $calKey.");
            continue;
        }
        foreach ($rowsOf($sheet) as $i => $r) {
            $rowNo = $i + 2;
            $title = trim((string) ($r[0] ?? ''));
            if ($title === '') {
                continue;
            }
            $start = import_cell_date($r[1] ?? null);
            $end = import_cell_date($r[2] ?? null) ?? $start;
            if ($start === null) {
                $warn("Event row $rowNo on '$sheet' '$title': no start date, skipped.");
                continue;
            }
            if ($end < $start) {
                $warn("Event row $rowNo on '$sheet' '$title': end before start, end set to start.");
                $end = $start;
            }
            // Rows on the factory sheet that are not factory closings were filed there by mistake in 2023.
            $misfiled = $calKey === $map['factory_cal'] && !import_looks_like_factory_closing($title);
            $events[$calKey][] = ['row' => $rowNo, 'title' => $title, 'start_date' => $start, 'end_date' => $end, 'misfiled' => $misfiled];
        }
    }

    // --- write inside one transaction ---------------------------------------------------------------
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = (int) col('SELECT COUNT(*) FROM employees WHERE group_id = ?', [$groupId]);
        if ($replaceAll) {
            // Scoped to this group: the other group's data is untouched.
            q('DELETE b FROM birthday_events b JOIN employees e ON e.id = b.employee_id WHERE e.group_id = ?', [$groupId]);
            q('DELETE a FROM adjustments a JOIN employees e ON e.id = a.employee_id WHERE e.group_id = ?', [$groupId]);
            q('DELETE t FROM time_off t JOIN employees e ON e.id = t.employee_id WHERE e.group_id = ?', [$groupId]);
            q('DELETE ev FROM events ev JOIN calendars c ON c.cal_key = ev.cal_key WHERE c.group_id = ?', [$groupId]);
            q('DELETE FROM employees WHERE group_id = ?', [$groupId]);
            if ($existing > 0) {
                $report['lines'][] = "Replace all: removed $existing existing employees of {$group['name']} and their rows.";
            }
        } elseif ($existing > 0) {
            throw new RuntimeException("{$group['name']} already has $existing employees. Use --replace-all to replace them.");
        }

        $now = now_str();
        $idByName = [];
        foreach ($employees as $e) {
            if (isset($idByName[$e['name']])) {
                $warn("Employee '{$e['name']}' appears twice (row {$e['row']}); the second row was imported as a separate person.");
            }
            $id = insert('employees', [
                'group_id' => $groupId, 'name' => $e['name'], 'hire_date' => $e['hire_date'],
                'birth_month' => $e['birth_month'], 'birth_day' => $e['birth_day'], 'birth_year' => $e['birth_year'],
                'status' => 'active', 'legacy_row' => $e['row'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $idByName[$e['name']] ??= $id;
            $report['counts']['employees']++;
        }

        $hireById = [];
        foreach ($employees as $e) {
            $hireById[$idByName[$e['name']]] = $e['hire_date'];
        }
        foreach ($requests as $r) {
            if (!isset($idByName[$r['name']])) {
                $warn("Time-off row {$r['row']}: unknown employee '{$r['name']}', skipped.");
                continue;
            }
            $empId = $idByName[$r['name']];
            if ($r['start_date'] < $hireById[$empId]) {
                $warn("Time-off row {$r['row']} {$r['name']} {$r['start_date']}..{$r['end_date']} is before the hire date {$hireById[$empId]} (earlier stint; imported as-is, allotment 0 in that cycle).");
            }
            if (working_days(to_date($r['start_date']), to_date($r['end_date']), []) === 0) {
                $warn("Time-off row {$r['row']} {$r['name']} {$r['start_date']}..{$r['end_date']} has no working days (imported as-is).");
            }
            insert('time_off', [
                'employee_id' => $empId, 'kind' => $r['kind'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'],
                'note' => null, 'legacy_row' => $r['row'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $report['counts']['time_off']++;
        }

        foreach ($adjustments as $a) {
            insert('adjustments', [
                'employee_id' => $idByName[$a['name']], 'kind' => $a['kind'], 'effective_date' => $a['effective_date'],
                'days' => $a['days'], 'reason' => mb_substr($a['reason'], 0, 255), 'legacy_row' => $a['row'], 'created_at' => $now,
            ]);
            $report['counts']['adjustments']++;
            $report['lines'][] = sprintf('Adjustment: %s %+g PTO anchored to %s (%s)', $a['name'], $a['days'], $a['effective_date'], $a['note']);
        }

        $misfiledIds = [];
        foreach ($events as $calKey => $list) {
            $report['counts']['events'][$calKey] = 0;
            foreach ($list as $ev) {
                $id = insert('events', [
                    'cal_key' => $calKey, 'title' => mb_substr($ev['title'], 0, 200), 'start_date' => $ev['start_date'],
                    'end_date' => $ev['end_date'], 'is_holiday' => 0, 'legacy_row' => $ev['row'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $report['counts']['events'][$calKey]++;
                $label = "$calKey: {$ev['title']} ({$ev['start_date']}..{$ev['end_date']})";
                if ($ev['misfiled']) {
                    $report['misfiled'][] = $label;
                    $misfiledIds[] = $id;
                    $report['counts']['misfiled']++;
                }
            }
        }
        // "possibly misfiled" flags live in settings (the events table has no flag column; schema is exact).
        $prev = json_decode(setting('misfiled_events', '[]') ?? '[]', true);
        $prev = is_array($prev) ? $prev : [];
        if ($replaceAll) {
            $prevIds = array_map('intval', $prev);
            $stillThere = $prevIds === [] ? [] : array_map('intval', array_column(rows('SELECT id FROM events WHERE id IN (' . implode(',', array_fill(0, count($prevIds), '?')) . ')', $prevIds), 'id'));
            $prev = $stillThere;
        }
        setting_set('misfiled_events', json_encode(array_values(array_unique(array_merge(array_map('intval', $prev), $misfiledIds)))));

        // --- acceptance check (SPEC section 4) -----------------------------------------------------
        import_acceptance($group, $requests, $idByName, $asOf, $expectedPath, $report);
        $mismatches = count($report['acceptance']['row_mismatches']) + count($report['acceptance']['balance_mismatches']);
        if ($mismatches > 0) {
            $report['ok'] = false;
            $report['errors'][] = "Acceptance check failed with $mismatches mismatch(es); nothing was written.";
        }

        $summaryTxt = sprintf('Imported %s snapshot: %d employees, %d time off, %d adjustments, %d events (%d possibly misfiled)%s',
            $group['group_key'], $report['counts']['employees'], $report['counts']['time_off'], $report['counts']['adjustments'],
            array_sum($report['counts']['events']), $report['counts']['misfiled'],
            $replaceAll ? ' [replace all]' : '');
        audit('import', null, null, null, $groupId, null, $report['counts'], $summaryTxt);

        if ($commit && $report['ok']) {
            $pdo->commit();
            $report['committed'] = true;
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $report['ok'] = false;
        $report['errors'][] = $e->getMessage();
    }
    return import_finish($report);
}

/** Recompute from the DB (inside the open transaction) and compare with the sheet / expected fixture. */
function import_acceptance(array $group, array $requests, array $idByName, DateTimeImmutable $asOf, ?string $expectedPath, array &$report): void
{
    $groupId = (int) $group['id'];
    $summaries = group_summaries($groupId, $asOf);
    $byName = [];
    foreach ($summaries as $id => $s) {
        $byName[$s['employee']['name']] ??= $s['summary'];
    }

    // (a) US: every time_off row's running balance must equal the sheet's stored E/F columns.
    if ($group['group_key'] === 'us') {
        $policy = group_policy($group);
        foreach ($idByName as $name => $empId) {
            $ledger = employee_ledger($empId, $asOf);
            $byLegacy = [];
            foreach ($ledger as $cycle) {
                foreach ($cycle['requests'] as $rq) {
                    $byLegacy[(int) $rq['id']] = $rq;
                }
            }
            $legacyToId = [];
            foreach (rows('SELECT id, legacy_row FROM time_off WHERE employee_id = ?', [$empId]) as $t) {
                $legacyToId[(int) $t['legacy_row']] = (int) $t['id'];
            }
            foreach ($requests as $r) {
                if ($r['name'] !== $name || !is_numeric($r['sheet_e']) || !is_numeric($r['sheet_f'])) {
                    continue;
                }
                $report['acceptance']['rows_checked']++;
                $rq = $byLegacy[$legacyToId[$r['row']] ?? -1] ?? null;
                $gotE = $rq['remaining_after']['PTO'] ?? null;
                $gotF = $rq['remaining_after']['Vacation'] ?? null;
                if ($rq === null || abs((float) $gotE - (float) $r['sheet_e']) > 1e-9 || abs((float) $gotF - (float) $r['sheet_f']) > 1e-9) {
                    $report['acceptance']['row_mismatches'][] = sprintf('row %d %s %s %s..%s: sheet (%s, %s) engine (%s, %s)',
                        $r['row'], $name, $r['kind'], $r['start_date'], $r['end_date'], $r['sheet_e'], $r['sheet_f'],
                        $gotE === null ? 'missing' : fmt_days($gotE), $gotF === null ? 'missing' : fmt_days($gotF));
                }
            }
        }
        unset($policy);
    }

    // (b) balances vs the expected fixture for this as-of date, when there is one.
    if ($expectedPath === null) {
        $report['lines'][] = 'No expected_' . ymd($asOf) . '.json fixture: balance check skipped (row check only).';
        return;
    }
    $exp = json_decode((string) file_get_contents($expectedPath), true);
    $expGroup = $exp[$group['group_key']] ?? null;
    if (!is_array($expGroup)) {
        $report['lines'][] = "Expected fixture has no '{$group['group_key']}' section: balance check skipped.";
        return;
    }
    foreach ($expGroup as $name => $e) {
        $report['acceptance']['balances_checked']++;
        $s = $byName[$name] ?? null;
        if ($s === null) {
            $report['acceptance']['balance_mismatches'][] = "$name: not imported";
            continue;
        }
        foreach (['current', 'next'] as $which) {
            $c = $s[$which];
            $diffs = [];
            if (ymd($c['start']) !== $e[$which]['cycle_start']) {
                $diffs[] = 'cycle_start ' . ymd($c['start']) . ' vs ' . $e[$which]['cycle_start'];
            }
            if (ymd($c['end']) !== $e[$which]['cycle_end']) {
                $diffs[] = 'cycle_end ' . ymd($c['end']) . ' vs ' . $e[$which]['cycle_end'];
            }
            foreach (['allotment', 'used', 'remaining'] as $field) {
                foreach ($e[$which][$field] as $kind => $val) {
                    if (abs((float) ($c[$field][$kind] ?? NAN) - (float) $val) > 1e-9) {
                        $diffs[] = "$field $kind " . fmt_days((float) ($c[$field][$kind] ?? 0)) . ' vs ' . fmt_days((float) $val);
                    }
                }
            }
            foreach ($e[$which]['adjustments'] ?? [] as $kind => $val) {
                if (abs((float) ($c['adjusted'][$kind] ?? NAN) - (float) $val) > 1e-9) {
                    $diffs[] = "adjustments $kind " . fmt_days((float) ($c['adjusted'][$kind] ?? 0)) . ' vs ' . fmt_days((float) $val);
                }
            }
            if ($diffs !== []) {
                $report['acceptance']['balance_mismatches'][] = "$name $which: " . implode('; ', $diffs);
            }
        }
        if ($s['after_date'] !== $e['after_date']) {
            $report['acceptance']['balance_mismatches'][] = "$name after_date {$s['after_date']} vs {$e['after_date']}";
        }
    }
}

/** Build the printable report lines. */
function import_finish(array $report): array
{
    $l = &$report['lines'];
    $c = $report['counts'];
    array_unshift($l, sprintf('Group %s, as of %s: %d employees, %d time-off rows, %d adjustments, events: %s; %d possibly misfiled.',
        $report['group'], $report['as_of'], $c['employees'], $c['time_off'], $c['adjustments'],
        $c['events'] === [] ? 'none' : implode(', ', array_map(static fn($k, $n) => "$k $n", array_keys($c['events']), $c['events'])),
        $c['misfiled']));
    foreach ($report['misfiled'] as $m) {
        $l[] = 'Possibly misfiled: ' . $m;
    }
    foreach ($report['warnings'] as $w) {
        $l[] = 'Warning: ' . $w;
    }
    $a = $report['acceptance'];
    $l[] = sprintf('Acceptance: %d rows checked (%d mismatches), %d balances checked (%d mismatches)%s.',
        $a['rows_checked'], count($a['row_mismatches']), $a['balances_checked'], count($a['balance_mismatches']),
        $a['expected_file'] ? ' against ' . basename($a['expected_file']) : '');
    foreach ($a['row_mismatches'] as $m) {
        $l[] = 'MISMATCH ' . $m;
    }
    foreach ($a['balance_mismatches'] as $m) {
        $l[] = 'MISMATCH ' . $m;
    }
    foreach ($report['errors'] as $e) {
        $l[] = 'ERROR: ' . $e;
    }
    $l[] = $report['committed'] ? 'COMMITTED.' : ($report['ok'] ? 'Dry run: nothing written (add --commit).' : 'ROLLED BACK.');
    unset($l);
    return $report;
}

// --- CLI entry point ----------------------------------------------------------------------------------
if (PTO_CLI && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $o = getopt('', ['group:', 'file:', 'commit', 'replace-all', 'as-of:', 'expected:']);
    $groupKey = $o['group'] ?? null;
    $file = $o['file'] ?? null;
    if (!is_string($groupKey) || !is_string($file)) {
        fwrite(STDERR, "Usage: php import_sheet.php --group=us|manila --file=<snapshot.json> [--commit] [--replace-all] [--as-of=YYYY-MM-DD] [--expected=<json>]\n");
        exit(2);
    }
    $group = group_by_key($groupKey);
    if ($group === null) {
        fwrite(STDERR, "Unknown group '$groupKey'\n");
        exit(2);
    }
    if (!is_file($file)) {
        fwrite(STDERR, "File not found: $file\n");
        exit(2);
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        fwrite(STDERR, "Not a JSON snapshot: $file\n");
        exit(2);
    }
    $report = import_snapshot((int) $group['id'], $json, [
        'commit'      => isset($o['commit']),
        'replace_all' => isset($o['replace-all']),
        'as_of'       => $o['as-of'] ?? null,
        'expected'    => $o['expected'] ?? null,
    ]);
    foreach ($report['lines'] as $line) {
        echo $line, "\n";
    }
    exit($report['ok'] ? 0 : 1);
}

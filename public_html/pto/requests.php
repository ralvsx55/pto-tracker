<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Time-off list (SPEC section 7.4): newest first for the selected group; filters employee, kind, cycle year;
 * sheet-like columns (employee, kind, start, end, working days, remaining after per kind, note, calendar state);
 * edit link, delete with confirm and the full before-image in audit_log; Export CSV in the sheet's column order
 * (calendar state, SPEC 14.5: synced = Google event id stored and no sync_error; pending = no id yet; error = sync_error;
 * a delete marks the group's PTO calendar dirty and runs the inline sync within its time budget);
 * (Employee, Kind, Start, End, PTO remaining, Vacation remaining, Notes = the "After MM/DD/YYYY" note).
 * The table sorts client-side (table.sortable, app.js); the server order stays newest first.
 *
 * "Remaining after" comes from employee_ledger(): the ledger lists every request with its running balance, so
 * each row here is looked up by id in its employee's ledger. A Manila request that spans the anniversary is
 * listed in both cycles; both balances are shown. "Cycle year" = the year the charged cycle starts in.
 *
 * Cycle-year filter: when ?year= is absent the list shows the cycles starting in the current year of the
 * group's today (group_today()); ?year=all shows every cycle; ?year=YYYY one year. Links that rebuild the
 * query (requests_query) therefore always carry the year ("all" included) so the state survives a redirect.
 */
$user = require_login();
$group = current_group();
$gid = (int) $group['id'];
$kinds = group_kinds($group);
$policy = group_policy($group);
$today = group_today($group);

// ---- private helpers --------------------------------------------------------------------------------------------

/**
 * Validated filters from the query string: ['employee'=>?int, 'kind'=>?string, 'year'=>?int].
 * year: absent (or anything unrecognised) = the current year of the group's today; "all" = null = no filter.
 */
function requests_filters(array $kinds, DateTimeImmutable $today): array
{
    $emp = get('employee');
    $kind = get('kind');
    $year = get('year');
    if ($year === 'all') {
        $yearFilter = null;
    } elseif (is_string($year) && preg_match('/^\d{4}$/', $year)) {
        $yearFilter = (int) $year;
    } else {
        $yearFilter = (int) $today->format('Y');
    }
    return [
        'employee' => is_string($emp) && ctype_digit($emp) && (int) $emp > 0 ? (int) $emp : null,
        'kind'     => is_string($kind) && in_array($kind, $kinds, true) ? $kind : null,
        'year'     => $yearFilter,
    ];
}

/**
 * Query string for links that must keep the group and the filters (leading '?', or '' when empty).
 * A null year is written as year=all: an absent year would fall back to the current year.
 */
function requests_query(array $group, array $filters, array $extra = []): string
{
    $q = ['g' => $group['group_key']];
    foreach ($filters as $k => $v) {
        if ($k === 'year' && $v === null) {
            $q[$k] = 'all';
        } elseif ($v !== null) {
            $q[$k] = (string) $v;
        }
    }
    foreach ($extra as $k => $v) {
        $q[$k] = (string) $v;
    }
    return '?' . http_build_query($q);
}

/**
 * The rows to show: time_off of the group (newest first) with the employee, plus for each row its ledger
 * entries [ ['cycle'=>'Y-m-d', 'entry'=>ledger request entry], ... ] (two for a split Manila request).
 * Ledgers are computed once per employee and only for employees that appear.
 */
function requests_rows(array $group, array $filters, DateTimeImmutable $today): array
{
    $sql = 'SELECT t.*, e.name AS employee_name, e.status AS employee_status
            FROM time_off t JOIN employees e ON e.id = t.employee_id
            WHERE e.group_id = ?';
    $p = [(int) $group['id']];
    if ($filters['employee'] !== null) {
        $sql .= ' AND t.employee_id = ?';
        $p[] = $filters['employee'];
    }
    if ($filters['kind'] !== null) {
        $sql .= ' AND t.kind = ?';
        $p[] = $filters['kind'];
    }
    $sql .= ' ORDER BY t.start_date DESC, t.id DESC';

    $ledgers = [];   // employee id => [request id => [entries]]
    $out = [];
    foreach (rows($sql, $p) as $r) {
        $eid = (int) $r['employee_id'];
        if (!isset($ledgers[$eid])) {
            $index = [];
            foreach (employee_ledger($eid, $today) as $cycleKey => $cycle) {
                foreach ($cycle['requests'] as $entry) {
                    $index[(int) $entry['id']][] = ['cycle' => $cycleKey, 'entry' => $entry];
                }
            }
            $ledgers[$eid] = $index;
        }
        $entries = $ledgers[$eid][(int) $r['id']] ?? [];
        if ($filters['year'] !== null) {
            $match = false;
            foreach ($entries as $en) {
                if ((int) substr($en['cycle'], 0, 4) === $filters['year']) {
                    $match = true;
                }
            }
            if (!$match) {
                continue;
            }
        }
        $r['entries'] = $entries;
        $r['days'] = $entries === [] ? time_off_days($group, $r) : (int) $entries[0]['entry']['days'];
        $out[] = $r;
    }
    return $out;
}

/** "After 03/08/2027: 3 PTO / 15 Vac" and/or the split explanation for the sheet's Notes column. */
function requests_note_after(array $row, array $kinds): string
{
    $parts = [];
    $entries = $row['entries'];
    if ($entries !== [] && $entries[0]['entry']['note_after'] !== null) {
        $parts[] = (string) $entries[0]['entry']['note_after'];
    }
    if (count($entries) > 1) {
        $bits = [];
        foreach ($entries as $en) {
            $e = $en['entry'];
            $left = [];
            foreach ($kinds as $k) {
                $left[] = fmt_days($e['remaining_after'][$k]) . (count($kinds) > 1 ? ' ' . policy_kind_label($k) : '');
            }
            $bits[] = sprintf('%s to cycle %s (%s left)', fmt_days($e['days_in_cycle']), fmt_date($en['cycle']), implode(' / ', $left));
        }
        $parts[] = 'Split: ' . implode(', ', $bits);
    }
    return implode('; ', $parts);
}

// ---- delete (POST from the list or from request.php's edit view) -------------------------------------------
$filters = requests_filters($kinds, $today);
if (is_post() && post('action') === 'delete') {
    $id = (int) post('id', 0);
    $before = $id > 0 ? row('SELECT * FROM time_off WHERE id = ?', [$id]) : null;
    $eg = $before === null ? null : employee_with_group((int) $before['employee_id']);
    if ($before === null || $eg === null) {
        flash('err', 'That time-off request no longer exists.');
        redirect('requests.php' . requests_query($group, $filters));
    }
    if (!user_can_group($user, (int) $eg['group']['id'])) {
        layout_error_page(403, 'Not allowed', 'This request belongs to a group your account cannot edit.', 'requests.php', 'All time off');
    }
    $emp = $eg['employee'];
    $rowGroup = $eg['group'];
    // SPEC 7.4 / 11: confirm happened in the browser (data-confirm); time_off_delete() writes the full
    // before-image to audit_log inside the same transaction as the delete, so History can restore it.
    time_off_delete($rowGroup, $emp, $before);
    $msg = sprintf('%s: %s %s - %s deleted. It can be restored from History.', $emp['name'], $before['kind'],
        fmt_date($before['start_date']), fmt_date($before['end_date']));
    // SPEC 14.3 hook: the group's PTO calendar is dirty; the inline pass syncs it now if it can (else cron does).
    foreach (group_calendars((int) $rowGroup['id'], 'pto') as $ptoCal) {
        mark_dirty((string) $ptoCal['cal_key']);
    }
    flash('ok', rtrim($msg . ' ' . sync_dirty_inline((int) $rowGroup['id'])));
    redirect('requests.php' . requests_query($rowGroup, $filters));
}

// ---- data ----------------------------------------------------------------------------------------------------
$list = requests_rows($group, $filters, $today);

// ---- CSV export in the sheet's column order ------------------------------------------------------------------
if (get('export') === 'csv') {
    // Rows in the sheet's own order (oldest first) so the file compares directly with the old workbook.
    usort($list, static fn(array $a, array $b): int => [$a['start_date'], (int) $a['id']] <=> [$b['start_date'], (int) $b['id']]);
    $filename = sprintf('timeoff-%s-%s.csv', $group['group_key'], $today->format('Y-m-d'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $fh = fopen('php://output', 'wb');
    if ($fh === false) {
        throw new RuntimeException('Cannot open output');
    }
    fwrite($fh, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 names correctly
    $head = ['Employee', count($kinds) > 1 ? 'Vacation or PTO' : 'PTO', 'Start Date', 'End Date'];
    foreach ($kinds as $k) {
        $head[] = $k . ' Remaining';
    }
    $head = array_merge($head, ['Notes', 'Working Days', 'Note', 'Cycle Start', 'Request ID']);
    csv_row($fh, $head);
    foreach ($list as $r) {
        $first = $r['entries'][0]['entry'] ?? null;
        // Free-text cells go through csv_text() so a note like "=HYPERLINK(...)" is not a formula in Excel.
        $line = [csv_text($r['employee_name']), $r['kind'], fmt_date($r['start_date']), fmt_date($r['end_date'])];
        foreach ($kinds as $k) {
            $line[] = $first === null ? '' : fmt_days($first['remaining_after'][$k]);
        }
        $line[] = requests_note_after($r, $kinds);
        $line[] = (string) $r['days'];
        $line[] = csv_text($r['note'] ?? '');
        $line[] = $r['entries'] === [] ? '' : fmt_date($r['entries'][0]['cycle']);
        $line[] = (string) $r['id'];
        csv_row($fh, $line);
    }
    fclose($fh);
    exit;
}

// ---- render ---------------------------------------------------------------------------------------------------
$employees = rows('SELECT id, name, status FROM employees WHERE group_id = ? ORDER BY status, name, id', [$gid]);
$span = row('SELECT MIN(start_date) AS lo, MAX(start_date) AS hi FROM time_off t JOIN employees e ON e.id = t.employee_id WHERE e.group_id = ?', [$gid]);
$years = [];
if ($span !== null && $span['lo'] !== null) {
    // A cycle that contains a request may start the year before the request; offer one extra year each side.
    $lo = (int) substr((string) $span['lo'], 0, 4) - 1;
    $hi = max((int) substr((string) $span['hi'], 0, 4), (int) $today->format('Y')) + 1;
    for ($y = $hi; $y >= $lo; $y--) {
        $years[] = $y;
    }
}
// The default filter is the current year, so it must always be an option (even with no time off yet).
$currentYear = (int) $today->format('Y');
if (!in_array($currentYear, $years, true)) {
    $years[] = $currentYear;
    rsort($years);
}

layout_header('Time off', ['css' => ['assets/requests.css']]);
echo '<div class="toolbar"><h1>Time off</h1><span class="muted">' . h($group['name']) . '</span><span class="spacer"></span>';
echo '<a class="btn" href="' . h(app_url('requests.php') . requests_query($group, $filters, ['export' => 'csv'])) . '">Export CSV</a> ';
echo '<a class="btn btn-primary" href="' . h(app_url('request.php') . '?g=' . rawurlencode((string) $group['group_key'])) . '">+ Time off</a></div>';

// Filters (GET form; the group travels as a hidden field so the tabs and the list agree).
echo '<form method="get" class="filters" action="' . h(app_url('requests.php')) . '">';
echo '<input type="hidden" name="g" value="' . h($group['group_key']) . '">';
echo '<div><label for="f-employee">Employee</label><select id="f-employee" name="employee"><option value="">All employees</option>';
foreach ($employees as $e) {
    $sel = $filters['employee'] === (int) $e['id'] ? ' selected' : '';
    echo '<option value="' . (int) $e['id'] . '"' . $sel . '>' . h($e['name'] . ($e['status'] !== 'active' ? ' (departed)' : '')) . '</option>';
}
echo '</select></div>';
if (count($kinds) > 1) {
    echo '<div><label for="f-kind">Kind</label><select id="f-kind" name="kind"><option value="">All kinds</option>';
    foreach ($kinds as $k) {
        echo '<option value="' . h($k) . '"' . ($filters['kind'] === $k ? ' selected' : '') . '>' . h($k) . '</option>';
    }
    echo '</select></div>';
}
echo '<div><label for="f-year">Cycle year</label><select id="f-year" name="year"><option value="all"' . ($filters['year'] === null ? ' selected' : '') . '>All cycles</option>';
foreach ($years as $y) {
    echo '<option value="' . $y . '"' . ($filters['year'] === $y ? ' selected' : '') . '>cycles starting in ' . $y . '</option>';
}
echo '</select></div>';
echo '<div><button class="btn" type="submit">Filter</button> ';
// Clear = back to the defaults (no employee, no kind, the current year).
if ($filters['employee'] !== null || $filters['kind'] !== null || $filters['year'] !== $currentYear) {
    echo '<a class="btn" href="' . h(app_url('requests.php') . requests_query($group, [])) . '">Clear</a>';
}
echo '</div>';
echo '<span class="spacer"></span><span class="count">' . h(plural(count($list), 'request')) . '</span>';
echo '</form>';

echo '<div class="table-wrap"><table class="sortable"><thead><tr>';
echo '<th data-sort="text">Employee</th><th data-sort="text">Kind</th><th data-sort="date">Start</th><th data-sort="date">End</th><th class="num" data-sort="num">Days</th>';
foreach ($kinds as $k) {
    echo '<th class="num" data-sort="num">' . h(policy_kind_label($k)) . ' after</th>';
}
echo '<th>Note</th><th data-sort="text">Calendar</th><th></th></tr></thead><tbody>';

if ($list === []) {
    echo '<tr><td colspan="' . (8 + count($kinds)) . '" class="muted">No time off matches these filters.</td></tr>';
}
foreach ($list as $r) {
    $entries = $r['entries'];
    $first = $entries[0]['entry'] ?? null;
    $confirm = sprintf('Delete %s: %s %s - %s? It can be restored from History.', $r['employee_name'], $r['kind'],
        fmt_date($r['start_date']), fmt_date($r['end_date']));
    echo '<tr>';
    echo '<td><a href="' . h(app_url('employee.php?id=' . (int) $r['employee_id'])) . '">' . h($r['employee_name']) . '</a>'
        . ($r['employee_status'] !== 'active' ? ' <span class="badge">departed</span>' : '') . '</td>';
    echo '<td>' . h($r['kind']) . '</td>';
    echo '<td data-v="' . h($r['start_date']) . '">' . h(fmt_date($r['start_date'])) . '</td>';
    echo '<td data-v="' . h($r['end_date']) . '">' . h(fmt_date($r['end_date'])) . '</td>';
    $daysCell = h((string) $r['days']);
    if ($first !== null && $first['holidays_skipped'] !== []) {
        $daysCell .= '<span class="sub">' . h(count($first['holidays_skipped']) . ' holiday' . (count($first['holidays_skipped']) === 1 ? '' : 's') . ' skipped') . '</span>';
    }
    echo '<td class="num" data-v="' . h((string) $r['days']) . '">' . $daysCell . '</td>';
    // Remaining after, per kind: the running balance of the charged cycle (both cycles for a split request);
    // requests in a future cycle carry the sheet's "After MM/DD/YYYY" marker on the first kind column.
    foreach ($kinds as $i => $k) {
        if ($first === null) {
            echo '<td class="num muted">-</td>';
            continue;
        }
        $cell = '';
        foreach ($entries as $n => $en) {
            $left = $en['entry']['remaining_after'][$k];
            $cell .= ($n > 0 ? ' <span class="muted">/</span> ' : '') . '<span class="' . trim(balance_class($left)) . '">' . h(fmt_days($left)) . '</span>';
        }
        if ($i === 0) {
            if (count($entries) > 1) {
                $cell .= '<span class="sub">split at ' . h(fmt_date($entries[1]['cycle'])) . '</span>';
            } elseif ($first['note_after'] !== null) {
                $cell .= '<span class="sub">after ' . h(fmt_date($entries[0]['cycle'])) . '</span>';
            }
        }
        // data-v: the first (charged) cycle's balance so a split row sorts by its first value.
        echo '<td class="num" data-v="' . h(fmt_days($first['remaining_after'][$k])) . '">' . $cell . '</td>';
    }
    echo '<td class="note-cell">' . h($r['note'] ?? '') . '</td>';
    echo '<td data-v="' . h(sync_badge_state($r)) . '">' . sync_badge($r) . '</td>';   // SPEC 14.5 (lib/layout.php)
    echo '<td class="actions-cell">';
    echo '<a class="btn btn-sm" href="' . h(app_url('request.php?id=' . (int) $r['id'])) . '">Edit</a>';
    echo '<form method="post" action="' . h(app_url('requests.php') . requests_query($group, $filters)) . '" data-confirm="' . h($confirm) . '">'
        . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
        . '<button class="btn btn-sm btn-danger" type="submit">Delete</button></form>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';

echo '<p class="help">Newest first. "' . h(implode('" / "', array_map(static fn(string $k): string => policy_kind_label($k) . ' after', $kinds)))
    . '" is the balance left in the request\'s cycle once it is charged, in date order, the same as the sheet\'s remaining columns; '
    . 'red is negative, amber is 1 or less. Deleted rows keep a full copy in History and can be restored there.</p>';
layout_footer();

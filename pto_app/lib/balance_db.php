<?php
declare(strict_types=1);

/**
 * DB-backed wrappers over the pure engine (LIB CONTRACT: balance_db.php).
 * Everything here loads rows and hands them to ledger()/summary() in balance.php.
 */

/** employees row joined with its group, or null. */
function employee_with_group(int $employeeId): ?array
{
    $e = row('SELECT * FROM employees WHERE id = ?', [$employeeId]);
    if ($e === null) {
        return null;
    }
    $g = group_by_id((int) $e['group_id']);
    if ($g === null) {
        return null;
    }
    return ['employee' => $e, 'group' => $g];
}

/** time_off rows of one employee in engine order. */
function employee_requests(int $employeeId): array
{
    return rows('SELECT * FROM time_off WHERE employee_id = ? ORDER BY start_date, id', [$employeeId]);
}

function employee_adjustments(int $employeeId): array
{
    return rows('SELECT * FROM adjustments WHERE employee_id = ? ORDER BY effective_date, id', [$employeeId]);
}

/** Cycle-by-cycle ledger for one employee (see ledger() in balance.php). */
function employee_ledger(int $employeeId, ?DateTimeImmutable $today = null): array
{
    $eg = employee_with_group($employeeId);
    if ($eg === null) {
        throw new RuntimeException("Unknown employee $employeeId");
    }
    return employee_ledger_for($eg['group'], $eg['employee'], $today ?? group_today($eg['group']));
}

/**
 * ledger() for an employee row that may differ from the stored one (the hire-date change preview on
 * employee.php passes the modified row); the requests and adjustments are the stored ones.
 */
function employee_ledger_for(array $group, array $employee, DateTimeImmutable $today): array
{
    $id = (int) $employee['id'];
    return ledger(group_policy($group), $employee, employee_requests($id), employee_adjustments($id),
        group_holidays((int) $group['id']), group_holidays_from($group), $today);
}

/** "03/08/2026 - 03/07/2027" for a ledger cycle (the engine's end is exclusive). */
function cycle_label(array $cycle): string
{
    return $cycle['start']->format('m/d/Y') . ' - ' . $cycle['end']->modify('-1 day')->format('m/d/Y');
}

/** group_summaries() rows in the order the sheets listed people (legacy_row, then id): new hires go last. */
function sheet_order(array $summaries): array
{
    uasort($summaries, static function (array $a, array $b): int {
        $la = $a['employee']['legacy_row'] === null ? PHP_INT_MAX : (int) $a['employee']['legacy_row'];
        $lb = $b['employee']['legacy_row'] === null ? PHP_INT_MAX : (int) $b['employee']['legacy_row'];
        return [$la, (int) $a['employee']['id']] <=> [$lb, (int) $b['employee']['id']];
    });
    return $summaries;
}

/** Current/next cycle summary for one employee (see summary() in balance.php). */
function employee_summary(int $employeeId, ?DateTimeImmutable $today = null): array
{
    $eg = employee_with_group($employeeId);
    if ($eg === null) {
        throw new RuntimeException("Unknown employee $employeeId");
    }
    $g = $eg['group'];
    $today ??= group_today($g);
    return summary(group_policy($g), $eg['employee'], employee_requests($employeeId), employee_adjustments($employeeId),
        group_holidays((int) $g['id']), group_holidays_from($g), $today);
}

/**
 * Summaries for every employee of a group (active and departed; callers filter on status):
 * employee id => ['employee' => row, 'summary' => summary()]. Three queries, not 3N.
 */
function group_summaries(int $groupId, ?DateTimeImmutable $today = null): array
{
    $g = group_by_id($groupId);
    if ($g === null) {
        throw new RuntimeException("Unknown group $groupId");
    }
    $today ??= group_today($g);
    $policy = group_policy($g);
    $holidays = group_holidays($groupId);
    $from = group_holidays_from($g);

    $employees = rows('SELECT * FROM employees WHERE group_id = ? ORDER BY hire_date, id', [$groupId]);
    $reqs = [];
    foreach (rows('SELECT t.* FROM time_off t JOIN employees e ON e.id = t.employee_id WHERE e.group_id = ? ORDER BY t.start_date, t.id', [$groupId]) as $r) {
        $reqs[(int) $r['employee_id']][] = $r;
    }
    $adjs = [];
    foreach (rows('SELECT a.* FROM adjustments a JOIN employees e ON e.id = a.employee_id WHERE e.group_id = ? ORDER BY a.effective_date, a.id', [$groupId]) as $a) {
        $adjs[(int) $a['employee_id']][] = $a;
    }
    $out = [];
    foreach ($employees as $e) {
        $id = (int) $e['id'];
        $out[$id] = [
            'employee' => $e,
            'summary'  => summary($policy, $e, $reqs[$id] ?? [], $adjs[$id] ?? [], $holidays, $from, $today),
        ];
    }
    return $out;
}

/**
 * Live preview for the time-off form (SPEC section 7.3). Never blocks; every problem is a warning.
 * Returns: working_days, holidays_skipped[], by_cycle ['Y-m-d'=>days], cycles [...], remaining_after [kind=>n]
 * (the start cycle after this request), warnings[], summary_line, straddle (null or details) - or ['error'=>msg].
 */
function request_preview(int $employeeId, string $kind, string $start, string $end, ?int $excludeRequestId = null): array
{
    $eg = employee_with_group($employeeId);
    if ($eg === null) {
        return ['error' => 'Unknown employee.'];
    }
    $e = $eg['employee'];
    $g = $eg['group'];
    $policy = group_policy($g);
    if (!in_array($kind, $policy['kinds'], true)) {
        return ['error' => 'Kind must be one of: ' . implode(', ', $policy['kinds']) . '.'];
    }
    $s = to_date($start);
    $en = to_date($end);
    if ($s === null || $en === null) {
        return ['error' => 'Enter both dates as YYYY-MM-DD.'];
    }
    if ($en < $s) {
        return ['error' => 'End date is before the start date.'];
    }
    $today = group_today($g);
    $holidays = group_holidays((int) $g['id']);
    $from = group_holidays_from($g);
    $hire = to_date($e['hire_date']);

    $requests = employee_requests($employeeId);
    if ($excludeRequestId !== null) {
        $requests = array_values(array_filter($requests, static fn(array $r): bool => (int) $r['id'] !== $excludeRequestId));
    }
    $new = ['id' => PHP_INT_MAX, 'kind' => $kind, 'start_date' => ymd($s), 'end_date' => ymd($en), 'note' => null];
    $byCycle = consumed_by_cycle($policy, $hire, $new, $holidays, $from);
    $skipped = request_holidays_skipped($new, $holidays, $from);
    $days = array_sum($byCycle);
    $requests[] = $new;
    $cycles = ledger($policy, $e, $requests, employee_adjustments($employeeId), $holidays, $from, $today);

    $warnings = [];
    $cycleInfo = [];
    foreach (array_keys($byCycle) as $key) {
        $c = $cycles[$key];
        $cycleInfo[$key] = [
            'start'     => ymd($c['start']),
            'end'       => ymd($c['end']),
            'label'     => $c['start']->format('m/d/Y') . ' - ' . $c['end']->modify('-1 day')->format('m/d/Y'),
            'allotment' => $c['allotment'],
            'adjusted'  => $c['adjusted'],
            'used'      => $c['used'],
            'remaining' => $c['remaining'],
            'days'      => $byCycle[$key],
        ];
        if ($c['remaining'][$kind] < 0) {
            $warnings[] = sprintf('%s balance goes to %s in the cycle starting %s.', $kind, fmt_days($c['remaining'][$kind]), $c['start']->format('m/d/Y'));
        }
    }
    $startKey = array_key_first($byCycle);
    $remainingAfter = $cycles[$startKey]['remaining'];

    if ($days === 0) {
        $warnings[] = 'No working days in this range (weekend or holiday only).';
    }
    if ($skipped !== []) {
        $names = array_map(static fn(string $d): string => (new DateTimeImmutable($d))->format('M j'), $skipped);
        $warnings[] = implode(', ', $names) . (count($skipped) === 1 ? ' is a company holiday, not charged.' : ' are company holidays, not charged.');
    }
    // Overlap with an existing request.
    foreach ($requests as $r) {
        if ($r === $new) {
            continue;
        }
        if ($r['start_date'] <= ymd($en) && $r['end_date'] >= ymd($s)) {
            $warnings[] = sprintf('Overlaps an existing %s request %s..%s.', $r['kind'], fmt_date($r['start_date']), fmt_date($r['end_date']));
        }
    }
    // Straddles an anniversary.
    $straddle = null;
    $startCycle = cycle_start($hire, $s);
    $endCycle = cycle_start($hire, $en);
    if ($startCycle != $endCycle) {
        $anniv = next_cycle_start($hire, $startCycle);
        if ($policy['straddle'] === 'start_cycle') {
            $straddle = [
                'anniversary' => ymd($anniv),
                'split'       => [[ymd($s), ymd($anniv->modify('-1 day'))], [ymd($anniv), ymd($en)]],
            ];
            $warnings[] = sprintf('Spans the anniversary on %s: the whole request is charged to the cycle starting %s. You can split it into two rows (%s..%s and %s..%s).',
                $anniv->format('m/d/Y'), $startCycle->format('m/d/Y'),
                fmt_date($s), fmt_date($anniv->modify('-1 day')), fmt_date($anniv), fmt_date($en));
        } else {
            $parts = [];
            foreach ($byCycle as $key => $n) {
                $parts[] = fmt_days($n) . ' to the cycle starting ' . fmt_date($key);
            }
            $straddle = ['anniversary' => ymd($anniv), 'by_cycle' => $byCycle];
            $warnings[] = sprintf('Spans the anniversary on %s: %s.', $anniv->format('m/d/Y'), implode(' and ', $parts));
        }
    }
    if ($hire !== null && $s < $hire) {
        $warnings[] = 'Starts before the hire date ' . fmt_date($hire) . ' (allotment 0 in that cycle).';
    }
    if ($e['status'] === 'departed' && $e['departed_on'] !== null && ymd($en) > $e['departed_on']) {
        $warnings[] = 'Ends after the departure date ' . fmt_date($e['departed_on']) . '.';
    }

    $line = plural($days, 'working day');
    if ($skipped !== []) {
        $names = array_map(static fn(string $d): string => (new DateTimeImmutable($d))->format('M j'), $skipped);
        $line .= ' (' . implode(', ', $names) . (count($skipped) === 1 ? ' is a company holiday, not charged' : ' are company holidays, not charged') . ')';
    }
    $line .= '.';
    foreach ($cycleInfo as $ci) {
        $line .= sprintf(' %s %s of %s left in cycle %s.', $kind, fmt_days($ci['remaining'][$kind]),
            fmt_days($ci['allotment'][$kind] + $ci['adjusted'][$kind]), $ci['label']);
    }

    return [
        'employee_id'      => $employeeId,
        'kind'             => $kind,
        'start'            => ymd($s),
        'end'              => ymd($en),
        'working_days'     => $days,
        'holidays_skipped' => $skipped,
        'by_cycle'         => $byCycle,
        'cycles'           => $cycleInfo,
        'remaining_after'  => $remainingAfter,
        'straddle'         => $straddle,
        'warnings'         => $warnings,
        'summary_line'     => $line,
    ];
}

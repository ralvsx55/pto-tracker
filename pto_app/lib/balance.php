<?php
declare(strict_types=1);

/**
 * Balance engine (SPEC section 10). Pure functions: no DB, no clock, no globals.
 *
 * Inputs are plain arrays with 'Y-m-d' string dates (exactly the DB rows) plus DateTimeImmutable for
 * the reference dates. Outputs use DateTimeImmutable at midnight for 'start'/'end' and numbers
 * (int when whole, float when an adjustment made them fractional).
 *
 * Request row:    ['id'=>int, 'kind'=>'PTO'|'Vacation', 'start_date'=>'Y-m-d', 'end_date'=>'Y-m-d', 'note'=>?string]
 * Adjustment row: ['id'=>int, 'kind'=>..., 'effective_date'=>'Y-m-d', 'days'=>numeric, 'reason'=>string]
 * Employee row:   ['hire_date'=>'Y-m-d', ...]
 * $holidayDates:  ['Y-m-d' => true, ...] every day of every company-holiday event of the group
 * $holidaysFrom:  groups.holidays_excluded_from (null = holiday exclusion off)
 */

const ENGINE_VERSION = '1.0.0';

/** Parse a 'Y-m-d' string to a midnight DateTimeImmutable (engine-local; helpers.php has to_date() for pages). */
function engine_date(string|DateTimeInterface $d): DateTimeImmutable
{
    if ($d instanceof DateTimeImmutable) {
        return $d->setTime(0, 0, 0);
    }
    if ($d instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($d)->setTime(0, 0, 0);
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($d, 0, 10));
    if ($dt === false) {
        throw new InvalidArgumentException("Bad date: $d");
    }
    return $dt;
}

/** Whole numbers stay int; anything else is a float (adjustments allow fractions). */
function engine_num(int|float|string $n): int|float
{
    $f = (float) $n;
    return (abs($f - round($f)) < 1e-9) ? (int) round($f) : $f;
}

/**
 * Rule 1: the cycle containing reference date $ref for an employee hired on $hire.
 * A = (ref.year, hire.month, hire.day); if A <= ref then A else the year before.
 * setDate() rolls Feb 29 to Mar 1 in non-leap years, like JS new Date(y, m, d).
 */
function cycle_start(DateTimeImmutable $hire, DateTimeImmutable $ref): DateTimeImmutable
{
    $hire = $hire->setTime(0, 0, 0);
    $ref = $ref->setTime(0, 0, 0);
    $m = (int) $hire->format('n');
    $d = (int) $hire->format('j');
    $y = (int) $ref->format('Y');
    $a = $ref->setDate($y, $m, $d);
    if ($a <= $ref) {
        return $a;
    }
    return $ref->setDate($y - 1, $m, $d);
}

/** Rule 1: exclusive end of a cycle = one year after its start (same month/day, rolled like setDate). */
function cycle_end(DateTimeImmutable $cycleStart): DateTimeImmutable
{
    $cycleStart = $cycleStart->setTime(0, 0, 0);
    return $cycleStart->setDate(
        (int) $cycleStart->format('Y') + 1,
        (int) $cycleStart->format('n'),
        (int) $cycleStart->format('j')
    );
}

/** The cycle after $cycleStart, re-anchored on the hire date (so a Feb 29 hire returns to Feb 29 in leap years). */
function next_cycle_start(DateTimeImmutable $hire, DateTimeImmutable $cycleStart): DateTimeImmutable
{
    return cycle_start($hire, cycle_end($cycleStart));
}

/** Rule 2: completed years of service at $at (not clamped; negative before the hire date). */
function years_of_service(DateTimeImmutable $hire, DateTimeImmutable $at): int
{
    $y = (int) $at->format('Y') - (int) $hire->format('Y');
    $atMd = [(int) $at->format('n'), (int) $at->format('j')];
    $hireMd = [(int) $hire->format('n'), (int) $hire->format('j')];
    if ($atMd < $hireMd) {
        $y--;
    }
    return $y;
}

/** Rule 3: calendar days in [start, end] with ISO weekday 1-5, minus the given holiday dates. */
function working_days(DateTimeImmutable $start, DateTimeImmutable $end, array $holidayDates): int
{
    $n = 0;
    foreach (request_working_dates($start, $end, $holidayDates) as $_) {
        $n++;
    }
    return $n;
}

/** Every counted working day of [start, end] as 'Y-m-d' (weekdays not in $holidayDates). */
function request_working_dates(DateTimeImmutable $start, DateTimeImmutable $end, array $holidayDates): array
{
    $out = [];
    $start = $start->setTime(0, 0, 0);
    $end = $end->setTime(0, 0, 0);
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') > 5) {
            continue;
        }
        $ymd = $d->format('Y-m-d');
        if (isset($holidayDates[$ymd])) {
            continue;
        }
        $out[] = $ymd;
    }
    return $out;
}

/**
 * Section 5: the holiday set that applies to a request. The exclusion is active only when the group's
 * holidays_excluded_from is set and the request starts on or after it; otherwise holidays are charged
 * (that keeps the 26 pre-rule US rows exactly as the sheet stored them).
 */
function holidays_for_request(array $request, array $holidayDates, ?DateTimeImmutable $holidaysFrom): array
{
    if ($holidaysFrom === null) {
        return [];
    }
    $start = engine_date($request['start_date']);
    return $start >= $holidaysFrom->setTime(0, 0, 0) ? $holidayDates : [];
}

/** Holidays skipped inside a request (weekday dates inside [start,end] that the rule excluded), 'Y-m-d' list. */
function request_holidays_skipped(array $request, array $holidayDates, ?DateTimeImmutable $holidaysFrom): array
{
    $applies = holidays_for_request($request, $holidayDates, $holidaysFrom);
    if ($applies === []) {
        return [];
    }
    $start = engine_date($request['start_date']);
    $end = engine_date($request['end_date']);
    $out = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        if ((int) $d->format('N') <= 5 && isset($applies[$ymd])) {
            $out[] = $ymd;
        }
    }
    return $out;
}

/**
 * Days a request charges to each cycle: ['Y-m-d cycle_start' => days].
 * start_cycle (US): everything goes to the cycle containing the start date.
 * split (Manila): each working day goes to the cycle it falls in.
 * A request with zero working days still returns its start cycle with 0 so the ledger can list it.
 */
function consumed_by_cycle(array $policy, DateTimeImmutable $hire, array $request, array $holidayDates, ?DateTimeImmutable $holidaysFrom): array
{
    $start = engine_date($request['start_date']);
    $end = engine_date($request['end_date']);
    $applies = holidays_for_request($request, $holidayDates, $holidaysFrom);
    $dates = request_working_dates($start, $end, $applies);
    $startCycle = cycle_start($hire, $start)->format('Y-m-d');

    if ($policy['straddle'] === 'start_cycle' || $dates === []) {
        return [$startCycle => count($dates)];
    }
    // split
    $out = [];
    foreach ($dates as $ymd) {
        $c = cycle_start($hire, engine_date($ymd))->format('Y-m-d');
        $out[$c] = ($out[$c] ?? 0) + 1;
    }
    ksort($out);
    return $out;
}

/** Sort requests by (start_date, id) - rule 5. */
function engine_sort_requests(array $requests): array
{
    usort($requests, static function (array $a, array $b): int {
        return [$a['start_date'], (int) ($a['id'] ?? 0)] <=> [$b['start_date'], (int) ($b['id'] ?? 0)];
    });
    return $requests;
}

/**
 * Rule 5 / section 10: the employee's cycle-by-cycle ledger.
 * Returns cycles keyed by 'Y-m-d' cycle start, in order, from the hire date through the cycle after
 * today's, extended to any cycle a request or adjustment touches (contiguous). Each cycle:
 *   'start','end' (DateTimeImmutable), 'yos', 'allotment'=>[kind=>int], 'adjustments'=>[rows...],
 *   'adjusted'=>[kind=>n], 'used'=>[kind=>n], 'remaining'=>[kind=>n], 'is_current','is_next','is_future',
 *   'requests'=>[ ['id','kind','start','end','days','days_in_cycle','split','holidays_skipped',
 *                  'remaining_after'=>[kind=>n],'note_after'=>?string,'note'], ... ]
 */
function ledger(array $policy, array $employee, array $requests, array $adjustments, array $holidayDates, ?DateTimeImmutable $holidaysFrom, DateTimeImmutable $today): array
{
    $hire = engine_date($employee['hire_date']);
    $today = $today->setTime(0, 0, 0);
    $kinds = $policy['kinds'];
    $currentStart = cycle_start($hire, $today);
    $nextStart = next_cycle_start($hire, $currentStart);

    // Work out what every request charges, once.
    $requests = engine_sort_requests($requests);
    $charges = [];   // request index => ['by_cycle'=>[...], 'days'=>n, 'skipped'=>[...]]
    $needed = [$hire->format('Y-m-d') => true, $currentStart->format('Y-m-d') => true, $nextStart->format('Y-m-d') => true];
    foreach ($requests as $i => $r) {
        if (!in_array($r['kind'], $kinds, true)) {
            continue;   // a kind the group does not use (cannot happen through the form)
        }
        $by = consumed_by_cycle($policy, $hire, $r, $holidayDates, $holidaysFrom);
        $charges[$i] = [
            'by_cycle' => $by,
            'days'     => array_sum($by),
            'skipped'  => request_holidays_skipped($r, $holidayDates, $holidaysFrom),
        ];
        foreach (array_keys($by) as $c) {
            $needed[$c] = true;
        }
    }
    $adjByCycle = [];
    foreach ($adjustments as $a) {
        if (!in_array($a['kind'], $kinds, true)) {
            continue;
        }
        // Rule 4: an adjustment belongs to the cycle whose [start, end) contains effective_date.
        $c = cycle_start($hire, engine_date($a['effective_date']))->format('Y-m-d');
        $needed[$c] = true;
        $adjByCycle[$c][] = $a;
    }

    // Contiguous cycle list from the earliest needed cycle to the latest.
    $keys = array_keys($needed);
    sort($keys);
    $first = engine_date($keys[0]);
    $last = engine_date($keys[count($keys) - 1]);
    $cycles = [];
    for ($c = $first; $c <= $last; $c = next_cycle_start($hire, $c)) {
        $key = $c->format('Y-m-d');
        $yos = years_of_service($hire, $c);
        $allot = policy_allotment($policy, $yos);
        $adjusted = array_fill_keys($kinds, 0);
        foreach ($adjByCycle[$key] ?? [] as $a) {
            $adjusted[$a['kind']] = engine_num($adjusted[$a['kind']] + (float) $a['days']);
        }
        $remaining = [];
        foreach ($kinds as $k) {
            $remaining[$k] = engine_num($allot[$k] + $adjusted[$k]);
        }
        $cycles[$key] = [
            'start'       => $c,
            'end'         => cycle_end($c),
            'yos'         => $yos,
            'allotment'   => $allot,
            'adjustments' => $adjByCycle[$key] ?? [],
            'adjusted'    => $adjusted,
            'used'        => array_fill_keys($kinds, 0),
            'remaining'   => $remaining,
            'is_current'  => $c == $currentStart,
            'is_next'     => $c == $nextStart,
            'is_future'   => $c > $currentStart,
            'requests'    => [],
        ];
    }

    // Running balances, requests in (start_date, id) order.
    foreach ($requests as $i => $r) {
        if (!isset($charges[$i])) {
            continue;
        }
        $ch = $charges[$i];
        $split = count($ch['by_cycle']) > 1;
        foreach ($ch['by_cycle'] as $key => $daysInCycle) {
            $cy = &$cycles[$key];
            $cy['used'][$r['kind']] = engine_num($cy['used'][$r['kind']] + $daysInCycle);
            foreach ($kinds as $k) {
                $cy['remaining'][$k] = engine_num($cy['allotment'][$k] + $cy['adjusted'][$k] - $cy['used'][$k]);
            }
            $cy['requests'][] = [
                'id'               => $r['id'] ?? null,
                'kind'             => $r['kind'],
                'start'            => engine_date($r['start_date']),
                'end'              => engine_date($r['end_date']),
                'note'             => $r['note'] ?? null,
                'days'             => $ch['days'],
                'days_in_cycle'    => $daysInCycle,
                'split'            => $split,
                'holidays_skipped' => $ch['skipped'],
                'remaining_after'  => $cy['remaining'],
                'note_after'       => $cy['is_future'] ? after_note($policy, $cy['start'], $cy['remaining']) : null,
            ];
            unset($cy);
        }
    }
    return $cycles;
}

/** "After MM/DD/YYYY: 3 PTO / 15 Vac" (two kinds) or "After MM/DD/YYYY: 3" (one kind). */
function after_note(array $policy, DateTimeImmutable $cycleStart, array $remaining): string
{
    $parts = [];
    foreach ($policy['kinds'] as $k) {
        $parts[] = count($policy['kinds']) === 1
            ? engine_fmt($remaining[$k])
            : engine_fmt($remaining[$k]) . ' ' . policy_kind_label($k);
    }
    return 'After ' . $cycleStart->format('m/d/Y') . ': ' . implode(' / ', $parts);
}

/** Number formatting inside the engine (helpers.php has fmt_days() for pages). */
function engine_fmt(int|float $n): string
{
    $n = engine_num($n);
    return is_int($n) ? (string) $n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/**
 * Rule 6: what the viewer table and dashboard show for the group's "today".
 * ['current'=>cycle, 'next'=>cycle, 'after_date'=>'MM/DD/YYYY'] where each cycle is the ledger entry
 * (start, end, yos, allotment, adjusted, used, remaining, requests...).
 */
function summary(array $policy, array $employee, array $requests, array $adjustments, array $holidayDates, ?DateTimeImmutable $holidaysFrom, DateTimeImmutable $today): array
{
    $hire = engine_date($employee['hire_date']);
    $cur = cycle_start($hire, $today);
    $next = next_cycle_start($hire, $cur);
    $cycles = ledger($policy, $employee, $requests, $adjustments, $holidayDates, $holidaysFrom, $today);
    return [
        'current'    => $cycles[$cur->format('Y-m-d')],
        'next'       => $cycles[$next->format('Y-m-d')],
        'after_date' => $next->format('m/d/Y'),
    ];
}

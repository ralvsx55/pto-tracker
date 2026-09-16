<?php
declare(strict_types=1);

/**
 * Engine acceptance tests (SPEC section 4). Hand-rolled runner, no PHPUnit, no DB.
 * Run: C:/xampp/php/php.exe pto_app/tests/balance_test.php     (exit code 1 on any failure)
 *
 * (a) all 177 US rows reproduce the sheet's stored PTO/Vacation-remaining columns (start_cycle, no holidays)
 * (b) the 15 US and 18 Manila current/next balances in fixtures/expected_2026-09-14.json
 * (c) the two Manila split cases
 * (d) the hand-written edge cases of SPEC section 4
 */

require __DIR__ . '/../lib/policy.php';
require __DIR__ . '/../lib/balance.php';

$FIX = __DIR__ . '/fixtures';
$US = json_decode((string) file_get_contents("$FIX/us_snapshot_2026-09-14.json"), true, 512, JSON_THROW_ON_ERROR);
$MN = json_decode((string) file_get_contents("$FIX/manila_snapshot_2026-09-14.json"), true, 512, JSON_THROW_ON_ERROR);
$EXP = json_decode((string) file_get_contents("$FIX/expected_2026-09-14.json"), true, 512, JSON_THROW_ON_ERROR);
$TODAY = new DateTimeImmutable($EXP['as_of']);

$pass = 0;
$fail = 0;
$failures = [];

function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    $failures[] = $label . ($detail !== '' ? ' :: ' . $detail : '');
}

/** Numeric/array/string equality with a tolerance for floats; DateTimeImmutable compared as Y-m-d. */
function same(mixed $a, mixed $b): bool
{
    if ($a instanceof DateTimeInterface) {
        $a = $a->format('Y-m-d');
    }
    if ($b instanceof DateTimeInterface) {
        $b = $b->format('Y-m-d');
    }
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !same($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
    if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
        return abs((float) $a - (float) $b) < 1e-9;
    }
    return $a === $b;
}

function show(mixed $v): string
{
    if ($v instanceof DateTimeInterface) {
        return $v->format('Y-m-d');
    }
    return json_encode($v, JSON_UNESCAPED_SLASHES);
}

function eq(mixed $actual, mixed $expected, string $label): void
{
    check(same($actual, $expected), $label, 'expected ' . show($expected) . ' got ' . show($actual));
}

function d(string $s): DateTimeImmutable
{
    return new DateTimeImmutable(substr($s, 0, 10));
}

/** Kind as stored in the sheet ('vacation', 'PTO', 'Vacation') -> the enum value. */
function norm_kind(string $k): string
{
    $k = strtolower(trim($k));
    return $k === 'vacation' ? 'Vacation' : 'PTO';
}

// ---------------------------------------------------------------------------------------------
// Build the fixture data straight from the JSON (mirrors what the importer stores).
// ---------------------------------------------------------------------------------------------
function fixture_employees(array $snap): array
{
    $out = [];
    foreach (array_slice($snap['Employee Start Date']['rows'], 1) as $r) {
        $name = trim((string) ($r[0] ?? ''));
        if ($name === '' || empty($r[1])) {
            continue;
        }
        $out[$name] = ['name' => $name, 'hire_date' => substr((string) $r[1], 0, 10)];
    }
    return $out;
}

function fixture_requests(array $snap): array
{
    $out = [];   // employee name => list of request rows; id = sheet row number
    foreach (array_slice($snap['Time Requested Off']['rows'], 1) as $i => $r) {
        $name = trim((string) ($r[0] ?? ''));
        if ($name === '' || empty($r[2]) || empty($r[3])) {
            continue;
        }
        $out[$name][] = [
            'id'         => $i + 2,
            'kind'       => norm_kind((string) ($r[1] ?? 'PTO')),
            'start_date' => substr((string) $r[2], 0, 10),
            'end_date'   => substr((string) $r[3], 0, 10),
            'sheet_e'    => $r[4] ?? null,
            'sheet_f'    => $r[5] ?? null,
        ];
    }
    return $out;
}

/** Manila "PTO Adjustments" -> adjustment rows anchored per SPEC section 9. */
function fixture_manila_adjustments(array $snap, array $employees): array
{
    $out = [];
    foreach (array_slice($snap['PTO Adjustments']['rows'], 1) as $i => $r) {
        $name = trim((string) ($r[0] ?? ''));
        if ($name === '' || empty($r[1]) || !isset($employees[$name])) {
            continue;
        }
        $hire = d($employees[$name]['hire_date']);
        $eff = d((string) $r[1]);
        $applyTo = strtoupper(trim((string) ($r[3] ?? 'CURRENT')));
        if ($applyTo === 'NEXT') {
            // NEXT = the start of the cycle after the one containing the sheet's effective date.
            $eff = next_cycle_start($hire, cycle_start($hire, $eff));
        }
        $out[$name][] = [
            'id'             => $i + 2,
            'kind'           => 'PTO',
            'effective_date' => $eff->format('Y-m-d'),
            'days'           => (float) $r[2],
            'reason'         => trim((string) ($r[5] ?? '')),
        ];
    }
    return $out;
}

function summary_matches(array $sum, array $exp, string $label, array $kinds): void
{
    foreach (['current', 'next'] as $which) {
        $c = $sum[$which];
        $e = $exp[$which];
        eq($c['start'], $e['cycle_start'], "$label $which cycle_start");
        eq($c['end'], $e['cycle_end'], "$label $which cycle_end");
        foreach ($kinds as $k) {
            eq($c['allotment'][$k], $e['allotment'][$k], "$label $which allotment $k");
            eq($c['used'][$k], $e['used'][$k], "$label $which used $k");
            eq($c['remaining'][$k], $e['remaining'][$k], "$label $which remaining $k");
            if (isset($e['adjustments'])) {
                eq($c['adjusted'][$k], $e['adjustments'][$k], "$label $which adjustments $k");
            }
        }
    }
    eq($sum['after_date'], $exp['after_date'], "$label after_date");
}

// ---------------------------------------------------------------------------------------------
// (a) US: every stored E/F value, and (b) the 15 balances
// ---------------------------------------------------------------------------------------------
$usPolicy = policy_for('us');
$usEmps = fixture_employees($US);
$usReqs = fixture_requests($US);
eq(count($usEmps), 15, 'US employee count');
eq(array_sum(array_map('count', $usReqs)), 177, 'US request row count');

$rowsChecked = 0;
foreach ($usEmps as $name => $emp) {
    $reqs = $usReqs[$name] ?? [];
    // (a) The sheet charged holidays and had no holiday rule: holidays off (null) reproduces it exactly.
    $ledger = ledger($usPolicy, $emp, $reqs, [], [], null, $TODAY);
    $byId = [];
    foreach ($ledger as $cycle) {
        foreach ($cycle['requests'] as $rq) {
            $byId[$rq['id']] = $rq;
        }
    }
    foreach ($reqs as $r) {
        $rowsChecked++;
        $got = $byId[$r['id']] ?? null;
        check(
            $got !== null && same($got['remaining_after']['PTO'], $r['sheet_e']) && same($got['remaining_after']['Vacation'], $r['sheet_f']),
            "US row {$r['id']} $name {$r['kind']} {$r['start_date']}..{$r['end_date']}",
            'sheet E/F = (' . show($r['sheet_e']) . ',' . show($r['sheet_f']) . ') engine = '
            . ($got === null ? 'missing' : '(' . show($got['remaining_after']['PTO']) . ',' . show($got['remaining_after']['Vacation']) . ')')
        );
    }
    // (b)
    $sum = summary($usPolicy, $emp, $reqs, [], [], null, $TODAY);
    check(isset($EXP['us'][$name]), "US expected entry for $name");
    if (isset($EXP['us'][$name])) {
        summary_matches($sum, $EXP['us'][$name], "US $name", $usPolicy['kinds']);
    }
}
eq($rowsChecked, 177, 'US rows checked');

// ---------------------------------------------------------------------------------------------
// (b) Manila: 18 balances with anchored adjustments and the split rule
// ---------------------------------------------------------------------------------------------
$mnPolicy = policy_for('manila');
$mnEmps = fixture_employees($MN);
$mnReqs = fixture_requests($MN);
$mnAdj = fixture_manila_adjustments($MN, $mnEmps);
eq(count($mnEmps), 18, 'Manila employee count');

// The anchoring rule must produce exactly the dates recorded in the expected fixture.
foreach ($EXP['manila_adjustments_anchored'] as $ea) {
    $found = null;
    foreach ($mnAdj[$ea['employee']] ?? [] as $a) {
        if (same($a['days'], $ea['days'])) {
            $found = $a;
        }
    }
    check($found !== null, "Manila adjustment for {$ea['employee']} present");
    if ($found !== null) {
        eq($found['effective_date'], $ea['effective_date'], "Manila adjustment {$ea['employee']} anchored effective_date ({$ea['sheet_apply_to']})");
        eq($found['reason'], trim($ea['reason']), "Manila adjustment {$ea['employee']} reason");
    }
}

foreach ($mnEmps as $name => $emp) {
    $sum = summary($mnPolicy, $emp, $mnReqs[$name] ?? [], $mnAdj[$name] ?? [], [], null, $TODAY);
    check(isset($EXP['manila'][$name]), "Manila expected entry for $name");
    if (isset($EXP['manila'][$name])) {
        summary_matches($sum, $EXP['manila'][$name], "Manila $name", $mnPolicy['kinds']);
    }
}

// (c) split cases
foreach ($EXP['manila_split_cases'] as $sc) {
    $hire = d($mnEmps[$sc['employee']]['hire_date']);
    $req = ['id' => 0, 'kind' => 'PTO', 'start_date' => $sc['start'], 'end_date' => $sc['end']];
    $by = consumed_by_cycle($mnPolicy, $hire, $req, [], null);
    eq($by, $sc['by_cycle'], "Manila split {$sc['employee']} {$sc['start']}..{$sc['end']}");
    // and under the US rule the whole request would go to the start cycle
    $byUs = consumed_by_cycle($usPolicy, $hire, $req, [], null);
    eq(array_keys($byUs), [array_key_first($sc['by_cycle'])], "start_cycle rule keeps {$sc['employee']} {$sc['start']} in one cycle");
    eq(array_sum($byUs), array_sum($sc['by_cycle']), "start_cycle rule total days {$sc['employee']} {$sc['start']}");
}
// Ledger view of a split request: it is listed in both cycles with days_in_cycle and split=true.
$rim = ledger($mnPolicy, $mnEmps['Rimalyn'], $mnReqs['Rimalyn'], [], [], null, $TODAY);
$seen = [];
foreach ($rim as $key => $cycle) {
    foreach ($cycle['requests'] as $rq) {
        if ($rq['start']->format('Y-m-d') === '2026-04-02') {
            $seen[$key] = [$rq['days_in_cycle'], $rq['split'], $rq['days']];
        }
    }
}
eq($seen, ['2025-04-04' => [2, true, 5], '2026-04-04' => [3, true, 5]], 'Rimalyn split request listed in both cycles');

// ---------------------------------------------------------------------------------------------
// (d) edge cases (SPEC section 4)
// ---------------------------------------------------------------------------------------------
// Saturday-only row (Ryan Danalewich, 2026-05-16) = 0 days and still listed.
eq(working_days(d('2026-05-16'), d('2026-05-16'), []), 0, 'Saturday-only row = 0 working days');
$ryan = ledger($usPolicy, $usEmps['Danalewich, Ryan'], $usReqs['Danalewich, Ryan'], [], [], null, $TODAY);
$sat = null;
foreach ($ryan as $cycle) {
    foreach ($cycle['requests'] as $rq) {
        if ($rq['start']->format('Y-m-d') === '2026-05-16') {
            $sat = $rq;
        }
    }
}
check($sat !== null && $sat['days'] === 0, 'Saturday-only row appears in the ledger with 0 days');

// 6-working-day Mon-Mon row (Clyde Modzik 2024-01-15..2024-01-22).
eq(working_days(d('2024-01-15'), d('2024-01-22'), []), 6, 'Mon-Mon row = 6 working days');

// Request on the anniversary is in the new cycle; the day before is in the old one.
$hire = d('2012-03-08');
eq(cycle_start($hire, d('2026-03-08')), '2026-03-08', 'anniversary day starts the new cycle');
eq(cycle_start($hire, d('2026-03-07')), '2025-03-08', 'day before the anniversary is in the old cycle');
eq(cycle_end(d('2025-03-08')), '2026-03-08', 'cycle_end is one year later (exclusive)');
$emp = ['hire_date' => '2012-03-08'];
$reqs = [
    ['id' => 1, 'kind' => 'PTO', 'start_date' => '2026-03-07', 'end_date' => '2026-03-07'],   // Saturday: 0 days, old cycle
    ['id' => 2, 'kind' => 'PTO', 'start_date' => '2026-03-06', 'end_date' => '2026-03-06'],   // Friday, old cycle
    ['id' => 3, 'kind' => 'PTO', 'start_date' => '2026-03-09', 'end_date' => '2026-03-09'],   // Monday after the anniversary, new cycle
];
$lg = ledger($usPolicy, $emp, $reqs, [], [], null, $TODAY);
eq($lg['2025-03-08']['used']['PTO'], 1, 'old cycle used (day before anniversary)');
eq($lg['2026-03-08']['used']['PTO'], 1, 'new cycle used (day after anniversary)');
eq(array_map(static fn($r) => $r['id'], $lg['2025-03-08']['requests']), [2, 1], 'requests ordered by (start_date, id)');

// Negative balances.
$reqs = [['id' => 1, 'kind' => 'Vacation', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30']];   // 22 working days
$lg = ledger($usPolicy, ['hire_date' => '2024-01-15'], $reqs, [], [], null, $TODAY);   // yos 2 -> 5 Vac
eq($lg['2026-01-15']['used']['Vacation'], 22, 'June 2026 = 22 working days');
eq($lg['2026-01-15']['remaining']['Vacation'], -17, 'balance goes negative (5 - 22)');
eq($lg['2026-01-15']['requests'][0]['remaining_after']['Vacation'], -17, 'negative remaining_after on the row');

// Adjustment on cycle_start (in) and on cycle_end (out); fractional adjustment.
$adjs = [
    ['id' => 1, 'kind' => 'PTO', 'effective_date' => '2026-01-15', 'days' => 2, 'reason' => 'on cycle start: in'],
    ['id' => 2, 'kind' => 'PTO', 'effective_date' => '2027-01-15', 'days' => 5, 'reason' => 'on cycle end: belongs to the next cycle'],
    ['id' => 3, 'kind' => 'Vacation', 'effective_date' => '2026-07-01', 'days' => 0.5, 'reason' => 'fractional'],
    ['id' => 4, 'kind' => 'Vacation', 'effective_date' => '2026-07-02', 'days' => -1, 'reason' => 'deduct'],
];
$lg = ledger($usPolicy, ['hire_date' => '2024-01-15'], [], $adjs, [], null, $TODAY);
eq($lg['2026-01-15']['adjusted']['PTO'], 2, 'adjustment dated on cycle_start counts in that cycle');
eq($lg['2027-01-15']['adjusted']['PTO'], 5, 'adjustment dated on cycle_end counts in the next cycle');
eq($lg['2026-01-15']['remaining']['PTO'], 5, 'PTO 3 + 2');
eq($lg['2026-01-15']['adjusted']['Vacation'], -0.5, 'fractional and negative adjustments sum');
eq($lg['2026-01-15']['remaining']['Vacation'], 4.5, 'fractional remaining 5 - 0.5');
eq(count($lg['2026-01-15']['adjustments']), 3, 'adjustment rows listed in their cycle');
eq(engine_fmt(4.5), '4.5', 'fractional formatting');
eq(engine_fmt(4.0), '4', 'whole float formatting');

// Feb 29 hire: rolls to Mar 1 in non-leap years, back to Feb 29 in leap years.
$feb = d('2024-02-29');
eq(cycle_start($feb, d('2025-06-01')), '2025-03-01', 'Feb 29 hire: 2025 cycle starts Mar 1');
eq(cycle_start($feb, d('2025-02-28')), '2024-02-29', 'Feb 29 hire: Feb 28 2025 is still the first cycle');
eq(cycle_start($feb, d('2028-03-15')), '2028-02-29', 'Feb 29 hire: leap year cycle starts Feb 29');
eq(next_cycle_start($feb, d('2027-03-01')), '2028-02-29', 'Feb 29 hire: next cycle after Mar 1 2027 is Feb 29 2028');
eq(years_of_service($feb, d('2025-03-01')), 1, 'Feb 29 hire: 1 year at Mar 1 2025');
eq(years_of_service($feb, d('2025-02-28')), 0, 'Feb 29 hire: 0 years at Feb 28 2025');
$lg = ledger($usPolicy, ['hire_date' => '2024-02-29'], [], [], [], null, d('2028-06-01'));
eq(array_keys($lg), ['2024-02-29', '2025-03-01', '2026-03-01', '2027-03-01', '2028-02-29', '2029-03-01'], 'Feb 29 hire: ledger cycle sequence');

// Dec 31 / Jan 1.
$jan = d('2020-01-01');
eq(cycle_start($jan, d('2025-12-31')), '2025-01-01', 'Dec 31 is in the Jan 1 cycle of that year');
eq(cycle_start($jan, d('2026-01-01')), '2026-01-01', 'Jan 1 anniversary starts the new cycle');
$req = ['id' => 1, 'kind' => 'PTO', 'start_date' => '2025-12-31', 'end_date' => '2026-01-02'];
eq(consumed_by_cycle($mnPolicy, $jan, $req, [], null), ['2025-01-01' => 1, '2026-01-01' => 2], 'split across Dec 31 / Jan 1');
eq(consumed_by_cycle($usPolicy, $jan, $req, [], null), ['2025-01-01' => 3], 'start_cycle across Dec 31 / Jan 1');
eq(cycle_start($jan, d('2019-12-31')), '2019-01-01', 'a date before the hire date still maps to a cycle');

// Request before hire date: allotment 0, negative remaining (Eric Codog's earlier stint works this way).
$lg = ledger($mnPolicy, ['hire_date' => '2026-08-31'], [['id' => 1, 'kind' => 'PTO', 'start_date' => '2024-07-29', 'end_date' => '2024-07-30']], [], [], null, $TODAY);
eq(array_key_first($lg), '2023-08-31', 'ledger extends back to the cycle of a pre-hire request');
eq($lg['2023-08-31']['allotment']['PTO'], 0, 'pre-hire cycle allotment 0');
check($lg['2023-08-31']['yos'] < 0, 'pre-hire years of service negative (not clamped)');
eq($lg['2023-08-31']['remaining']['PTO'], -2, 'pre-hire request leaves a negative balance');
eq(array_keys($lg), ['2023-08-31', '2024-08-31', '2025-08-31', '2026-08-31', '2027-08-31'], 'ledger cycles are contiguous through the next cycle');
eq($lg['2026-08-31']['is_current'], true, 'current cycle flagged');
eq($lg['2027-08-31']['is_next'], true, 'next cycle flagged');

// Holiday inside a request before and after holidays_excluded_from.
$hol = ['2025-12-25' => true, '2026-12-25' => true];
$from = d('2026-01-01');
$before = ['id' => 1, 'kind' => 'PTO', 'start_date' => '2025-12-24', 'end_date' => '2025-12-26'];
$after = ['id' => 2, 'kind' => 'PTO', 'start_date' => '2026-12-24', 'end_date' => '2026-12-28'];
eq(consumed_by_cycle($usPolicy, $jan, $before, $hol, $from), ['2025-01-01' => 3], 'holiday charged when the request starts before holidays_excluded_from');
eq(request_holidays_skipped($before, $hol, $from), [], 'no holidays skipped before the rule date');
eq(consumed_by_cycle($usPolicy, $jan, $after, $hol, $from), ['2026-01-01' => 2], 'holiday not charged after holidays_excluded_from (Thu, Mon)');
eq(request_holidays_skipped($after, $hol, $from), ['2026-12-25'], 'skipped holiday reported');
eq(consumed_by_cycle($usPolicy, $jan, $after, $hol, null), ['2026-01-01' => 3], 'NULL holidays_excluded_from = rule off');
eq(consumed_by_cycle($usPolicy, $jan, ['id' => 3, 'kind' => 'PTO', 'start_date' => '2026-01-01', 'end_date' => '2026-01-01'], ['2026-01-01' => true], $from), ['2026-01-01' => 0], 'request starting exactly on holidays_excluded_from uses the rule');

// A two-day holiday spanning a weekend (Fri 2026-07-03 .. Mon 2026-07-06, every day in the set).
$span = ['2026-07-03' => true, '2026-07-04' => true, '2026-07-05' => true, '2026-07-06' => true];
$req = ['id' => 4, 'kind' => 'PTO', 'start_date' => '2026-07-02', 'end_date' => '2026-07-07'];
eq(consumed_by_cycle($usPolicy, $jan, $req, $span, $from), ['2026-01-01' => 2], 'weekend-spanning holiday: only Thu and Tue charged');
eq(request_holidays_skipped($req, $span, $from), ['2026-07-03', '2026-07-06'], 'weekend days are not reported as skipped holidays');
eq(working_days(d('2026-07-02'), d('2026-07-07'), $span), 2, 'working_days honours the holiday set');

// Ledger holiday handling: used reflects the skipped day; the row carries holidays_skipped.
$lg = ledger($usPolicy, ['hire_date' => '2020-01-01'], [$after], [], $hol, $from, $TODAY);
eq($lg['2026-01-01']['used']['PTO'], 2, 'ledger used excludes the holiday');
eq($lg['2026-01-01']['requests'][0]['holidays_skipped'], ['2026-12-25'], 'ledger row lists skipped holidays');

// After-note wording.
$lg = ledger($usPolicy, ['hire_date' => '2012-03-08'], [['id' => 9, 'kind' => 'Vacation', 'start_date' => '2027-03-10', 'end_date' => '2027-03-12']], [], [], null, $TODAY);
eq($lg['2027-03-08']['requests'][0]['note_after'], 'After 03/08/2027: 3 PTO / 12 Vac', 'US next-cycle note');
eq($lg['2027-03-08']['requests'][0]['days'], 3, 'next-cycle request days');
$lg = ledger($mnPolicy, ['hire_date' => '2012-11-11'], [['id' => 9, 'kind' => 'PTO', 'start_date' => '2026-11-12', 'end_date' => '2026-11-12']], [], [], null, $TODAY);
eq($lg['2026-11-11']['requests'][0]['note_after'], 'After 11/11/2026: 9', 'Manila next-cycle note');
$lg = ledger($usPolicy, ['hire_date' => '2012-03-08'], [['id' => 9, 'kind' => 'Vacation', 'start_date' => '2026-03-10', 'end_date' => '2026-03-12']], [], [], null, $TODAY);
eq($lg['2026-03-08']['requests'][0]['note_after'], null, 'no note in the current cycle');

// Policy tiers.
eq(policy_allotment($usPolicy, 0), ['PTO' => 0, 'Vacation' => 0], 'US tier <1');
eq(policy_allotment($usPolicy, 1), ['PTO' => 3, 'Vacation' => 5], 'US tier 1-4');
eq(policy_allotment($usPolicy, 5), ['PTO' => 3, 'Vacation' => 10], 'US tier 5-9');
eq(policy_allotment($usPolicy, 10), ['PTO' => 3, 'Vacation' => 15], 'US tier 10+');
eq(policy_allotment($mnPolicy, 0), ['PTO' => 0], 'Manila tier <1');
eq(policy_allotment($mnPolicy, 4), ['PTO' => 3], 'Manila tier 1-4');
eq(policy_allotment($mnPolicy, 9), ['PTO' => 5], 'Manila tier 5-9');
eq(policy_allotment($mnPolicy, 10), ['PTO' => 10], 'Manila tier 10+');
eq(ENGINE_VERSION, '1.0.0', 'engine version');

// ---------------------------------------------------------------------------------------------
$total = $pass + $fail;
if ($fail === 0) {
    echo "\033[32mOK: $total assertions\033[0m\n";
    exit(0);
}
foreach ($failures as $f) {
    echo "FAIL: $f\n";
}
echo "\033[31mFAILED: $fail of $total assertions\033[0m\n";
exit(1);

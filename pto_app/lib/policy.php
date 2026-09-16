<?php
declare(strict_types=1);

/**
 * Group policies (SPEC section 4). Pure: no DB, no clock.
 *
 * Tiers live in code on purpose: they change once a decade and a table invites accidental edits.
 * policy_for() returns:
 *   'key'      => 'us' | 'manila'
 *   'kinds'    => list of kinds the group uses ('PTO', 'Vacation')
 *   'straddle' => 'start_cycle' (whole request charged to the cycle containing its start date)
 *               | 'split'       (each working day charged to the cycle it falls in)
 *   'allot'    => callable(int $yos): array<kind, int>  allotment per cycle by completed years of service at cycle start
 */
function policy_for(string $policyKey): array
{
    switch ($policyKey) {
        case 'us':
            return [
                'key'      => 'us',
                'kinds'    => ['PTO', 'Vacation'],
                'straddle' => 'start_cycle',
                'allot'    => static function (int $yos): array {
                    // Vacation: 0 (<1), 5 (1-4), 10 (5-9), 15 (10+). PTO: 0 (<1), 3 (1+).
                    $vac = $yos >= 10 ? 15 : ($yos >= 5 ? 10 : ($yos >= 1 ? 5 : 0));
                    $pto = $yos >= 1 ? 3 : 0;
                    return ['PTO' => $pto, 'Vacation' => $vac];
                },
            ];
        case 'manila':
            return [
                'key'      => 'manila',
                'kinds'    => ['PTO'],
                'straddle' => 'split',
                'allot'    => static function (int $yos): array {
                    // PTO: 0 (<1), 3 (1-4), 5 (5-9), 10 (10+).
                    $pto = $yos >= 10 ? 10 : ($yos >= 5 ? 5 : ($yos >= 1 ? 3 : 0));
                    return ['PTO' => $pto];
                },
            ];
    }
    throw new InvalidArgumentException("Unknown policy key: $policyKey");
}

/** Allotment for every kind of the policy at the given years of service (kinds not returned by allot() get 0). */
function policy_allotment(array $policy, int $yos): array
{
    $raw = ($policy['allot'])($yos);
    $out = [];
    foreach ($policy['kinds'] as $kind) {
        $out[$kind] = (int) ($raw[$kind] ?? 0);
    }
    return $out;
}

/** Short label used in the "After MM/DD/YYYY: 3 PTO / 15 Vac" note. */
function policy_kind_label(string $kind): string
{
    return $kind === 'Vacation' ? 'Vac' : $kind;
}

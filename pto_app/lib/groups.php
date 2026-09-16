<?php
declare(strict_types=1);

/** Groups, their policies, calendars and holidays (LIB CONTRACT: groups.php). */

/** Every active group as id => row, sorted; filtered to the user's group when the account is limited. */
function groups_all(): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (rows('SELECT * FROM groups WHERE is_active = 1 ORDER BY sort_order, id') as $g) {
            $all[(int) $g['id']] = $g;
        }
    }
    $user = PHP_SAPI === 'cli' ? null : current_user();
    if ($user !== null && $user['group_id'] !== null && $user['role'] !== 'admin') {
        return array_filter($all, static fn(array $g): bool => (int) $g['id'] === (int) $user['group_id'], ARRAY_FILTER_USE_BOTH);
    }
    return $all;
}

function group_by_id(int $id): ?array
{
    return row('SELECT * FROM groups WHERE id = ?', [$id]);
}

function group_by_key(string $key): ?array
{
    return row('SELECT * FROM groups WHERE group_key = ?', [$key]);
}

/** The selected group: ?g=<key> (remembered in the session), else the session's, else the first allowed. */
function current_group(): array
{
    $allowed = groups_all();
    if ($allowed === []) {
        throw new RuntimeException('No group is available for this account.');
    }
    $byKey = [];
    foreach ($allowed as $g) {
        $byKey[$g['group_key']] = $g;
    }
    $want = get('g');
    if (is_string($want) && isset($byKey[$want])) {
        $_SESSION['group_key'] = $want;
        return $byKey[$want];
    }
    $sess = $_SESSION['group_key'] ?? null;
    if (is_string($sess) && isset($byKey[$sess])) {
        return $byKey[$sess];
    }
    $first = reset($byKey);
    $_SESSION['group_key'] = $first['group_key'];
    return $first;
}

/** Midnight "today" in the group's own timezone (used for cycle math and the viewer page). */
function group_today(array $group): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone($group['timezone']));
    // Re-create in the default zone so comparisons with other engine dates are zone-free.
    return new DateTimeImmutable($now->format('Y-m-d'));
}

function group_policy(array $group): array
{
    return policy_for((string) $group['policy_key']);
}

function group_kinds(array $group): array
{
    return group_policy($group)['kinds'];
}

/**
 * 'Y-m-d' => true for every day of every is_holiday event on the group's calendars.
 * Memoised per request; any code that writes events and then computes balances in the same request must call
 * group_holidays_reset() after the write (the Events screen and History's Restore do).
 */
function group_holidays(int $groupId): array
{
    static $cache = [];
    if ($groupId === 0) {           // group_holidays_reset() sentinel: forget everything
        $cache = [];
        return [];
    }
    if (isset($cache[$groupId])) {
        return $cache[$groupId];
    }
    $out = [];
    $sql = 'SELECT e.start_date, e.end_date FROM events e JOIN calendars c ON c.cal_key = e.cal_key
            WHERE c.group_id = ? AND e.is_holiday = 1';
    foreach (rows($sql, [$groupId]) as $ev) {
        $d = to_date($ev['start_date']);
        $end = to_date($ev['end_date']);
        if ($d === null || $end === null) {
            continue;
        }
        for (; $d <= $end; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = true;
        }
    }
    return $cache[$groupId] = $out;
}

/** Forget the memoised holiday sets; call after inserting, updating, moving or deleting an events row. */
function group_holidays_reset(): void
{
    group_holidays(0);
}

/**
 * groups.holidays_excluded_from as a date, or null when the rule is off. Section 5 is retired: nothing in the product
 * sets the column any more (migration 002 nulled it), so this returns null and the engine charges plain weekdays.
 */
function group_holidays_from(array $group): ?DateTimeImmutable
{
    $raw = $group['holidays_excluded_from'] ?? null;
    return $raw === null || $raw === '' ? null : to_date((string) $raw);
}

/** The group's calendars (optionally one kind: 'pto' | 'birthdays' | 'events'), sorted. */
function group_calendars(int $groupId, ?string $kind = null): array
{
    if ($kind === null) {
        return rows('SELECT * FROM calendars WHERE group_id = ? ORDER BY sort_order, cal_key', [$groupId]);
    }
    return rows('SELECT * FROM calendars WHERE group_id = ? AND kind = ? ORDER BY sort_order, cal_key', [$groupId, $kind]);
}

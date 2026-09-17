<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Dashboard (SPEC section 7.2). For the selected group:
 *   - one row per active employee: name, hire date, years of service, current cycle dates, per kind
 *     "left / allotment", Total, and the next-cycle preview in the sheet's own wording
 *     ("After 03/08/2027: 3 PTO / 15 Vac"); red when negative, amber when 1 or fewer;
 *   - Upcoming: Today / This week / Next week / Later this month (time off, birthdays, events of the group);
 *   - the calendar sync status strip from sync_status_for_group() (SPEC 14.5): green / amber / red, the failing or
 *     stale calendars by name, and an admin-only "Sync now" that posts to admin.php (action sync_group);
 *   - buttons + Time off and + Employee; the show-former-employees toggle (?former=1).
 * Balances come from group_summaries() (the engine) for the group's own "today".
 */

/**
 * Upcoming items of the group from $today through the later of "next week" and "end of this month".
 * Weeks run Monday to Sunday. Returns ['buckets' => ['today'=>[], 'week'=>[], 'next'=>[], 'month'=>[]],
 * 'week_end', 'next_week_end', 'month_end'] where each item is
 * ['on' => first day on/after today, 'start', 'end', 'type' => 'timeoff'|'birthday'|'event', 'who', 'what'].
 * A multi-day item is filed once, under the first bucket it touches (an ongoing vacation is under Today).
 */
function dashboard_upcoming(array $group, DateTimeImmutable $today): array
{
    $gid = (int) $group['id'];
    $weekEnd = $today->modify('+' . (7 - (int) $today->format('N')) . ' days');   // Sunday of this week
    $nextWeekEnd = $weekEnd->modify('+7 days');
    $monthEnd = $today->modify('last day of this month');
    $windowEnd = $nextWeekEnd > $monthEnd ? $nextWeekEnd : $monthEnd;
    $holidays = group_holidays($gid);
    $from = group_holidays_from($group);
    $items = [];

    // Time off of active employees that overlaps the window (departed people are off every list).
    $sql = 'SELECT t.*, e.name FROM time_off t JOIN employees e ON e.id = t.employee_id
            WHERE e.group_id = ? AND e.status = \'active\' AND t.end_date >= ? AND t.start_date <= ?
            ORDER BY t.start_date, t.id';
    foreach (rows($sql, [$gid, ymd($today), ymd($windowEnd)]) as $t) {
        $s = to_date($t['start_date']);
        $e = to_date($t['end_date']);
        if ($s === null || $e === null) {
            continue;
        }
        // Same day count the engine charges (weekdays; the dormant section 5 exclusion is off with NULL holidays_excluded_from).
        $days = working_days($s, $e, holidays_for_request($t, $holidays, $from));
        $items[] = [
            'on' => $s < $today ? $today : $s, 'start' => $s, 'end' => $e, 'type' => 'timeoff', 'rank' => 0,
            'who' => (string) $t['name'], 'what' => $t['kind'] . ', ' . plural($days, 'day'),
        ];
    }

    // Birthdays of active employees: this year's and next year's occurrence (a window can cross New Year).
    $sql = 'SELECT name, birth_month, birth_day FROM employees
            WHERE group_id = ? AND status = \'active\' AND birth_month IS NOT NULL AND birth_day IS NOT NULL ORDER BY name';
    foreach (rows($sql, [$gid]) as $e) {
        foreach ([(int) $today->format('Y'), (int) $today->format('Y') + 1] as $y) {
            // setDate rolls Feb 29 to Mar 1 in a non-leap year, the same way the engine's cycle math does.
            $d = $today->setDate($y, (int) $e['birth_month'], (int) $e['birth_day']);
            if ($d >= $today && $d <= $windowEnd) {
                $items[] = [
                    'on' => $d, 'start' => $d, 'end' => $d, 'type' => 'birthday', 'rank' => 1,
                    'who' => (string) $e['name'], 'what' => 'Birthday',
                ];
            }
        }
    }

    // Events on the group's events calendars (office closures, factory closings, sales, parties).
    $sql = 'SELECT ev.*, c.label AS cal_label FROM events ev JOIN calendars c ON c.cal_key = ev.cal_key
            WHERE c.group_id = ? AND ev.end_date >= ? AND ev.start_date <= ?
            ORDER BY ev.start_date, ev.id';
    foreach (rows($sql, [$gid, ymd($today), ymd($windowEnd)]) as $ev) {
        $s = to_date($ev['start_date']);
        $e = to_date($ev['end_date']);
        if ($s === null || $e === null) {
            continue;
        }
        $items[] = [
            'on' => $s < $today ? $today : $s, 'start' => $s, 'end' => $e, 'type' => 'event', 'rank' => 2,
            'who' => (string) $ev['title'], 'what' => (string) $ev['cal_label'],
        ];
    }

    usort($items, static fn(array $a, array $b): int => [ymd($a['on']), $a['rank'], $a['who']] <=> [ymd($b['on']), $b['rank'], $b['who']]);
    $buckets = ['today' => [], 'week' => [], 'next' => [], 'month' => []];
    foreach ($items as $it) {
        $on = $it['on'];
        if ($on == $today) {
            $buckets['today'][] = $it;
        } elseif ($on <= $weekEnd) {
            $buckets['week'][] = $it;
        } elseif ($on <= $nextWeekEnd) {
            $buckets['next'][] = $it;
        } elseif ($on <= $monthEnd) {
            $buckets['month'][] = $it;
        }
    }
    return ['buckets' => $buckets, 'week_end' => $weekEnd, 'next_week_end' => $nextWeekEnd, 'month_end' => $monthEnd];
}

/** "Wed 09/16" or "Wed 09/16 - Fri 09/18" for an item's own dates. */
function dashboard_when(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    $fmt = 'D m/d';
    if ($start == $end) {
        return $start->format($fmt);
    }
    return $start->format($fmt) . ' - ' . $end->format($fmt);
}

const DASHBOARD_BUCKET_MAX = 3;   // items listed per Upcoming card before the "view more" link

/** One Upcoming bucket as a card. */
function dashboard_bucket(string $title, string $range, array $items, string $moreHref): void
{
    // The cards keep one fixed size (dashboard.css); only the first DASHBOARD_BUCKET_MAX items are listed and the
    // rest are reached through a link to the group's live calendar page.
    $extra = max(0, count($items) - DASHBOARD_BUCKET_MAX);
    $items = array_slice($items, 0, DASHBOARD_BUCKET_MAX);
    echo '<div class="card"><h3>' . h($title) . '<span class="range">' . h($range) . '</span></h3>';
    if ($items === []) {
        echo '<p class="empty">Nothing scheduled.</p></div>';
        return;
    }
    echo '<ul>';
    foreach ($items as $it) {
        $when = '<span class="when">' . h(dashboard_when($it['start'], $it['end'])) . '</span>';
        $who = '<span class="who">' . h($it['who']) . '</span>';
        $what = '<span class="what">' . h($it['what']) . '</span>';
        echo '<li class="up-' . h($it['type']) . '">' . $when . $who . ' ' . $what . '</li>';
    }
    echo '</ul>';
    if ($extra > 0) {
        echo '<a class="more" target="_blank" rel="noopener" href="' . h($moreHref) . '">View ' . $extra . ' more on the calendar &#8599;</a>';
    }
    echo '</div>';
}

// --- page --------------------------------------------------------------------------------------------
$user = require_login();
$group = current_group();
$policy = group_policy($group);
$kinds = $policy['kinds'];
$today = group_today($group);
$showFormer = get('former') === '1';
$g = '?g=' . rawurlencode((string) $group['group_key']);
$all = sheet_order(group_summaries((int) $group['id'], $today));
$activeCount = count(array_filter($all, static fn(array $r): bool => $r['employee']['status'] === 'active'));

layout_header('Dashboard', ['css' => ['assets/dashboard.css']]);

echo '<div class="toolbar"><h1>' . h($group['name']) . '</h1>';
echo '<span class="dash-meta">today ' . h($today->format('m/d/Y')) . ' (' . h($group['timezone']) . ') &middot; '
    . h((string) $activeCount) . ' active ' . ($activeCount === 1 ? 'employee' : 'employees') . '</span>';
echo '<span class="spacer"></span>';
echo '<a class="btn btn-primary" href="' . h(app_url('request.php') . $g) . '">+ Time off</a> ';
echo '<a class="btn" href="' . h(app_url('employees.php') . $g . '&add=1') . '#add">+ Employee</a></div>';

// Calendar sync status strip (SPEC 14.5): state from the engine; the names of the calendars that failed or have
// been dirty for 30+ minutes when the state is failed / stale; "Sync now" for admins posts to Admin's sync_group.
$sync = sync_status_for_group((int) $group['id']);
$syncState = (string) ($sync['state'] ?? 'not_configured');
$syncClass = match ($syncState) {
    'ok'              => ' sync-ok',
    'dry_run', 'stale' => ' sync-warn',
    'failed'          => ' sync-err',
    default           => '',
};
$syncSummary = (string) ($sync['summary'] ?? '');
if ($syncSummary === '') {
    $syncSummary = $syncState === 'not_configured' ? 'Google not configured' : 'Calendar sync: ' . $syncState;
}
echo '<div class="status-strip sync' . $syncClass . '"><span>' . h($syncSummary) . '</span>';
if ($syncState === 'failed' || $syncState === 'stale') {
    $names = [];
    foreach ((array) ($sync['calendars'] ?? []) as $c) {
        $failed = isset($c['last_sync_ok']) && $c['last_sync_ok'] !== null && (int) $c['last_sync_ok'] === 0;
        $stale = !empty($c['dirty']) && (int) ($c['minutes_dirty'] ?? 0) >= SYNC_STALE_MINUTES;
        $label = (string) ($c['label'] ?? '');
        // Only names the engine's summary does not already spell out.
        if ((($syncState === 'failed' && $failed) || ($syncState === 'stale' && $stale)) && $label !== '' && !str_contains($syncSummary, $label)) {
            $names[] = $label;
        }
    }
    if ($names !== []) {
        echo ' <span class="sync-cals">(' . h(implode(', ', $names)) . ')</span>';
    }
}
if ($user['role'] === 'admin' && $syncState !== 'not_configured') {
    echo '<form method="post" action="' . h(app_url('admin.php')) . '" class="inline-form">' . csrf_field()
        . '<input type="hidden" name="action" value="sync_group"><input type="hidden" name="group_id" value="' . (int) $group['id'] . '">'
        . '<input type="hidden" name="return" value="dashboard"><button class="btn btn-sm" type="submit">Sync now</button></form>';
} elseif ($user['role'] === 'admin') {
    echo '<a class="btn btn-sm" href="' . h(app_url('admin.php?tab=calendars')) . '">Set up</a>';
}
echo '</div>';

// --- upcoming ---------------------------------------------------------------------------------------
$up = dashboard_upcoming($group, $today);
$b = $up['buckets'];
$moreHref = app_url('view.php') . '?g=' . rawurlencode((string) $group['group_key']);
echo '<h2>Upcoming</h2><div class="upcoming">';
dashboard_bucket('Today', $today->format('D m/d'), $b['today'], $moreHref);
dashboard_bucket('This week', 'through ' . $up['week_end']->format('D m/d'), $b['week'], $moreHref);
dashboard_bucket('Next week', $up['week_end']->modify('+1 day')->format('m/d') . ' - ' . $up['next_week_end']->format('m/d'), $b['next'], $moreHref);
$laterFrom = $up['next_week_end']->modify('+1 day');
dashboard_bucket('Later this month', $laterFrom <= $up['month_end']
    ? $laterFrom->format('m/d') . ' - ' . $up['month_end']->format('m/d')
    : 'nothing left of ' . $today->format('F'), $b['month'], $moreHref);
echo '</div>';

// --- balances table --------------------------------------------------------------------------------
// Sortable (app.js): dates carry data-v Y-m-d, "left / allot." cells carry the "left" value in data-v.
echo '<div class="table-wrap"><table class="balances sortable"><thead><tr><th data-sort="text">Employee</th><th data-sort="date">Hire date</th><th class="num" data-sort="num">Years</th><th>Current cycle</th>';
foreach ($kinds as $k) {
    echo '<th class="num" data-sort="num">' . h($k) . ' left / allot.</th>';
}
echo '<th class="num" data-sort="num">Total</th><th>Next cycle</th></tr></thead><tbody>';
$shown = 0;
foreach ($all as $row) {
    $e = $row['employee'];
    $former = $e['status'] !== 'active';
    if ($former && !$showFormer) {
        continue;
    }
    $shown++;
    $s = $row['summary'];
    $cur = $s['current'];
    $next = $s['next'];
    echo '<tr' . ($former ? ' class="former"' : '') . '>';
    echo '<td><a href="' . h(app_url('employee.php?id=' . (int) $e['id'])) . '">' . h($e['name']) . '</a>'
        . ($former ? ' <span class="badge">departed ' . h(fmt_date($e['departed_on'])) . '</span>' : '') . '</td>';
    echo '<td data-v="' . h((string) $e['hire_date']) . '">' . h(fmt_date($e['hire_date'])) . '</td>';
    echo '<td class="num">' . h((string) $cur['yos']) . '</td>';
    echo '<td class="cycle">' . h($cur['start']->format('m/d/Y')) . ' - ' . h($cur['end']->modify('-1 day')->format('m/d/Y')) . '</td>';
    $total = 0;
    foreach ($kinds as $k) {
        $left = $cur['remaining'][$k];
        $allot = $cur['allotment'][$k] + $cur['adjusted'][$k];   // allotment including this cycle's adjustments
        $total += $left;
        echo '<td class="num' . balance_class($left) . '" data-v="' . h(fmt_days($left)) . '">' . h(fmt_days($left)) . ' / ' . h(fmt_days($allot)) . '</td>';
    }
    echo '<td class="num' . balance_class($total) . '">' . h(fmt_days($total)) . '</td>';
    // Next-cycle preview in the sheet's wording: "After 03/08/2027: 3 PTO / 15 Vac" (Manila: "After ...: 3").
    $booked = array_sum($next['used']) > 0;
    echo '<td class="next-note muted">' . h(after_note($policy, $next['start'], $next['remaining']))
        . ($booked ? ' <span class="badge badge-warn">booked</span>' : '') . '</td>';
    echo '</tr>';
}
if ($shown === 0) {
    echo '<tr><td colspan="' . (6 + count($kinds)) . '" class="muted">No employees yet.</td></tr>';
}
echo '</tbody></table></div>';
echo '<p class="help">' . ($showFormer
    ? '<a href="' . h(app_url('dashboard.php') . $g) . '">Hide former employees</a>'
    : '<a href="' . h(app_url('dashboard.php') . $g . '&former=1') . '">Show former employees</a>')
    . ' &middot; "left / allot." is this cycle\'s remaining days over its allotment (adjustments included).'
    . ' Red: negative. Amber: 1 or fewer.</p>';

layout_footer();

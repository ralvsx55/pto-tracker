<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Staff viewer page (SPEC section 7.0 and 8): view.php?g=us|manila
 * Same layout and wording as the old bookmarked pages so staff recognise them: the heading, the Google
 * Calendar embed (groups.viewer_embed_src, height 700) on the left and the remaining-days table on the
 * right (they stack on narrow screens). Active employees only, server-rendered from the engine for the
 * group's own "today". No nav, no group tabs, no login link; a page never shows the other group.
 *
 * Access: one shared password per group with a 90-day trust cookie (viewer_auth.php). A device without
 * a valid cookie gets a minimal password form; a wrong password sleeps 1 s and is audited with the IP
 * (inside viewer_check_password()). Admin/editor sessions do not unlock this page.
 */

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

/** Page chrome for the viewer: no nav, no group tabs, the viewer stylesheet. */
function view_header(string $title): void
{
    layout_header($title, ['nav' => false, 'group_tabs' => false, 'title_suffix' => false, 'body_class' => 'viewer-page', 'css' => ['assets/view.css']]);
}

/** 404 for an unknown group key, an inactive group or a group whose viewer page is disabled (no password set). */
function view_not_found(): never
{
    http_response_code(404);
    view_header('Not found');
    echo '<div class="viewer viewer-gate"><div class="card narrow"><h1>Not found</h1>'
        . '<p>There is no calendar page at this address.</p></div></div>';
    layout_footer();
    exit;
}

/** The minimal password form (one field; "Remember this device" is always on), styled like the page. */
function view_gate(array $group, ?string $error): never
{
    view_header((string) $group['viewer_title']);
    echo '<div class="viewer viewer-gate"><h1>' . h($group['viewer_heading']) . '</h1>';
    echo '<div class="card narrow"><p>Enter the office password to see the calendar.</p>';
    if ($error !== null) {
        echo '<div class="flash flash-err">' . h($error) . '</div>';
    }
    echo '<form method="post" action="' . h(app_url('view.php') . '?g=' . rawurlencode((string) $group['group_key'])) . '" data-autofocus-first>'
        . csrf_field();
    echo '<label for="password">Password</label>'
        . '<input type="password" id="password" name="password" autocomplete="current-password" required>';
    // SPEC 7.0: "Remember this device" is always on (the trust cookie lasts 90 days and slides).
    echo '<label class="remember"><input type="checkbox" checked disabled> Remember this device</label>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Continue</button></div>';
    echo '</form></div></div>';
    layout_footer();
    exit;
}

// --- which group ------------------------------------------------------------------------------------
$key = get('g');
$group = is_string($key) && $key !== '' ? group_by_key($key) : null;
if ($group === null || (int) $group['is_active'] !== 1) {
    view_not_found();
}
$hash = $group['viewer_password_hash'] ?? null;
if (!is_string($hash) || $hash === '') {
    view_not_found();   // NULL hash = viewer page disabled (SPEC section 6)
}

// --- password form ----------------------------------------------------------------------------------
$error = null;
if (is_post()) {
    if (viewer_check_password($group, (string) post('password', ''))) {
        viewer_issue_cookie($group);
        redirect('view.php?g=' . rawurlencode((string) $group['group_key']));
    }
    $error = 'Wrong password. Try again.';
}
// viewer_trusted() may re-issue the cookie (sliding window), so it runs before any output.
if (!viewer_trusted($group)) {
    view_gate($group, $error);
}

// --- the page ---------------------------------------------------------------------------------------
$policy = group_policy($group);
$kinds = $policy['kinds'];
$twoKinds = count($kinds) > 1;   // US: PTO + Vacation; Manila: PTO only
$today = group_today($group);
$rows = sheet_order(group_summaries((int) $group['id'], $today));

view_header((string) $group['viewer_title']);
echo '<div class="viewer"><h1>' . h($group['viewer_heading']) . '</h1>';
echo '<div class="viewer-cols">';

// Calendar column: the embed URL is kept verbatim from the old page (groups.viewer_embed_src).
echo '<div class="viewer-cal">';
$embed = $group['viewer_embed_src'] ?? null;
if (is_string($embed) && $embed !== '') {
    echo '<iframe src="' . h($embed) . '" title="' . h($group['name']) . ' calendar" width="100%" height="700" frameborder="0" scrolling="no"></iframe>';
} else {
    echo '<p class="muted">The calendar embed has not been configured yet.</p>';
}
echo '</div>';

// Table column: the old pages' wording. US: "Remaining Days After Scheduling Time Off" Employee | Hire Date | PTO | VAC.
// Manila: "Remaining Time Off" Employee | Hire Date | PTO (Current) with the small "After MM/DD/YYYY: N" line.
echo '<div class="viewer-table">';
echo '<h2>' . ($twoKinds ? 'Remaining Days After Scheduling Time Off' : 'Remaining Time Off') . '</h2>';
// Sortable (app.js): hire date carries data-v Y-m-d, the balances their plain number.
echo '<table class="sortable"><thead><tr><th data-sort="text">Employee</th><th data-sort="date">Hire Date</th>';
if ($twoKinds) {
    echo '<th class="num" data-sort="num">PTO</th><th class="num" data-sort="num">VAC</th>';
} else {
    echo '<th class="num" data-sort="num">PTO (Current)</th>';
}
echo '</tr></thead><tbody>';
$shown = 0;
foreach ($rows as $row) {
    $e = $row['employee'];
    if ($e['status'] !== 'active') {
        continue;   // departed employees are off the viewer page (SPEC section 1)
    }
    $shown++;
    $s = $row['summary'];
    $cur = $s['current'];
    $next = $s['next'];
    echo '<tr><td>' . h($e['name']) . '</td>';
    // Hire date with the "cycle renews MM/DD" hint under it.
    echo '<td data-v="' . h((string) $e['hire_date']) . '">' . h(fmt_date($e['hire_date'])) . '<span class="renews">cycle renews ' . h($next['start']->format('m/d')) . '</span></td>';
    if ($twoKinds) {
        foreach ($kinds as $k) {
            $left = $cur['remaining'][$k];
            echo '<td class="num' . ($left < 0 ? ' neg' : '') . '" data-v="' . h(fmt_days($left)) . '">' . h(fmt_days($left)) . '</td>';
        }
    } else {
        $k = $kinds[0];
        $left = $cur['remaining'][$k];
        echo '<td class="num' . ($left < 0 ? ' neg' : '') . '" data-v="' . h(fmt_days($left)) . '">' . h(fmt_days($left));
        if (array_sum($next['used']) > 0) {
            // Only when next-cycle usage exists, like the old Manila page: "After MM/DD/YYYY: N".
            echo '<span class="after">' . h(after_note($policy, $next['start'], $next['remaining'])) . '</span>';
        }
        echo '</td>';
    }
    echo '</tr>';
}
if ($shown === 0) {
    echo '<tr><td colspan="' . ($twoKinds ? 4 : 3) . '" class="muted">No employees.</td></tr>';
}
echo '</tbody></table>';
echo '<p class="help">Balances as of ' . h($today->format('m/d/Y')) . '. Company holidays do not count against time off.</p>';
echo '</div>';   // .viewer-table

echo '</div></div>';   // .viewer-cols .viewer
layout_footer();

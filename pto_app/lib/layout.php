<?php
declare(strict_types=1);

/**
 * Page chrome (LIB CONTRACT: layout.php). No inline <script>/<style> anywhere: the CSP blocks them.
 * layout_header($title, ['nav'=>true, 'group_tabs'=>true, 'css'=>['assets/x.css'], 'js'=>['assets/x.js'],
 *                        'title_suffix'=>true, 'body_class'=>''])
 *   title_suffix false = the bare title (the viewer page uses the group's own viewer_title); body_class is added
 *   to <body> so a page stylesheet can restyle the chrome without inline styles.
 * Theme: assets/theme.js is loaded in <head> before the stylesheets so the first paint already carries
 *   <html data-theme="dark|light"> (dark by default, remembered in localStorage). The sun/moon toggle button sits
 *   in the top bar next to the user name; pages without a top bar (login, viewer page, setup) get the same button
 *   fixed in the top-right corner. layout_footer() prints the same copyright line on every page.
 */
function layout_header(string $title, array $opts = []): void
{
    $nav = $opts['nav'] ?? true;
    $tabs = $opts['group_tabs'] ?? true;
    $css = array_merge(['assets/app.css'], $opts['css'] ?? []);
    $user = PHP_SAPI === 'cli' ? null : current_user();
    $GLOBALS['layout_js'] = array_merge(['assets/app.js'], $opts['js'] ?? []);
    $bodyClass = (string) ($opts['body_class'] ?? '');

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . (($opts['title_suffix'] ?? true) ? ' - PTO Tracker' : '') . '</title>';
    // theme.js before the stylesheets: it sets data-theme on <html> synchronously, so there is no light flash.
    echo '<script src="' . h(app_url('assets/theme.js')) . '?v=' . h(APP_VERSION) . '"></script>';
    foreach ($css as $c) {
        echo '<link rel="stylesheet" href="' . h(app_url($c)) . '?v=' . h(APP_VERSION) . '">';
    }
    echo '</head><body' . ($bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '') . '>';

    if ($nav && $user !== null) {
        echo '<header class="topbar"><div class="wrap">';
        echo '<a class="brand" href="' . h(app_url('dashboard.php')) . '">PTO Tracker</a>';
        echo '<nav class="mainnav">';
        $links = [
            'dashboard.php' => 'Dashboard',
            'requests.php'  => 'Time off',
            'employees.php' => 'Employees',
            'events.php'    => 'Events',
        ];
        if ($user['role'] === 'admin') {   // master admin only: History and Admin (both pages answer 403 otherwise)
            $links['history.php'] = 'History';
            $links['admin.php'] = 'Admin';
        }
        $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        foreach ($links as $href => $label) {
            $active = ($current === $href || ($href === 'requests.php' && $current === 'request.php')
                || ($href === 'employees.php' && $current === 'employee.php')) ? ' class="active"' : '';
            echo '<a' . $active . ' href="' . h(app_url($href)) . '">' . h($label) . '</a>';
        }
        echo '</nav>';
        echo '<div class="userbox"><span class="username">' . h($user['display_name']) . '</span> '
            . '<a href="' . h(app_url('logout.php')) . '">Logout</a>' . layout_theme_toggle('') . '</div>';
        echo '</div></header>';

        if ($tabs) {
            $groups = groups_all();
            $cur = current_group();
            echo '<div class="grouptabs"><div class="wrap">';
            foreach ($groups as $g) {
                $active = (int) $g['id'] === (int) $cur['id'] ? ' class="active"' : '';
                echo '<a' . $active . ' href="' . h(app_url($current === '' ? 'dashboard.php' : $current) . '?g=' . rawurlencode($g['group_key'])) . '">'
                    . h($g['name']) . '</a>';
            }
            // Quick link to the group's live viewer page (what staff see), opened in a new tab so the admin keeps their place.
            echo '<a class="livelink" target="_blank" rel="noopener" href="' . h(app_url('view.php') . '?g=' . rawurlencode($cur['group_key'])) . '">'
                . 'Open live page: ' . h($cur['name']) . ' &#8599;</a>';
            echo '</div></div>';
        }
    }

    if (!($nav && $user !== null)) {
        // No top bar (login, viewer page, setup, error pages): the toggle floats in the top-right corner.
        echo layout_theme_toggle('theme-toggle-fixed');
    }

    echo '<main class="wrap">';
    foreach (flashes() as $f) {
        $cls = $f['type'] === 'ok' ? 'flash-ok' : 'flash-err';
        echo '<div class="flash ' . $cls . '">' . h($f['msg']) . '</div>';
    }
}

/**
 * The dark/light toggle: a sun and a moon (inline SVG, currentColor); app.css shows the moon in light mode and
 * the sun in dark mode. theme.js handles the click on data-theme-toggle. $extraClass: '' in the top bar,
 * 'theme-toggle-fixed' for pages without one.
 */
function layout_theme_toggle(string $extraClass): string
{
    $sun = '<svg class="icon-sun" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="4" fill="currentColor"/>'
        . '<path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"'
        . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
    $moon = '<svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
        . '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" fill="currentColor"/></svg>';
    $class = 'theme-toggle' . ($extraClass !== '' ? ' ' . h($extraClass) : '');
    return '<button type="button" class="' . $class . '" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">'
        . $sun . $moon . '</button>';
}

/**
 * Calendar state of a mirrored row (SPEC 14.5): 'error' when sync_error is set, 'synced' when google_event_id is
 * set, else 'pending'. Used as the sortable cell's data-v; sync_badge() renders it. $row may be null (no row).
 */
function sync_badge_state(?array $row): string
{
    if ($row === null) {
        return '';
    }
    $err = $row['sync_error'] ?? null;
    if ($err !== null && $err !== '') {
        return 'error';
    }
    $gid = $row['google_event_id'] ?? null;
    return $gid !== null && $gid !== '' ? 'synced' : 'pending';
}

/**
 * The Calendar column badge for a time_off / events / birthday_events row (SPEC 14.5): synced (green, the Google
 * event id as the title), pending (amber: NULL id, the next sync inserts it), error (red, the sync_error text as the
 * title; $showError also prints the text after the badge). '' for a null row. One helper for every screen.
 */
function sync_badge(?array $row, bool $showError = false): string
{
    switch (sync_badge_state($row)) {
        case 'error':
            $err = (string) $row['sync_error'];
            return '<span class="badge badge-err" title="' . h($err) . '">error</span>' . ($showError ? ' <span class="muted">' . h($err) . '</span>' : '');
        case 'synced':
            return '<span class="badge badge-ok" title="Google event ' . h((string) $row['google_event_id']) . '">synced</span>';
        case 'pending':
            return '<span class="badge badge-warn" title="Not on the Google calendar yet; the next sync inserts it">pending</span>';
    }
    return '';
}

/**
 * "Schema behind" warning for logged-in users: settings.schema_version older than the code's SCHEMA_VERSION means a
 * file in pto_app/migrations/ has not been pasted into phpMyAdmin yet. Empty on public pages or without a database.
 */
function layout_schema_warning(): string
{
    try {
        // Master admins only, and only on the admin dashboard: the reminder is for the person who runs migrations,
        // not for the viewer pages or data-entry screens.
        $user = current_user();
        if ($user === null || $user['role'] !== 'admin' || basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'dashboard.php') {
            return '';
        }
        $db = setting('schema_version', '?') ?? '?';
    } catch (Throwable) {
        return '';
    }
    if ($db === SCHEMA_VERSION) {
        return '';
    }
    return ' <span class="badge badge-warn">database schema ' . h($db) . ' is behind the code (' . h(SCHEMA_VERSION)
        . '): apply the missing pto_app/migrations/*.sql in phpMyAdmin</span>';
}

function layout_footer(): void
{
    echo '</main>';
    // One footer everywhere: the company line with the current year in the app timezone (now_str()). The version
    // line (app, engine, schema, PHP) moved to the Admin screen so the PHP version is served to admins only.
    echo '<footer class="footer wrap">Lightsaber Promotions Inc. &copy; ' . h(substr(now_str(), 0, 4)) . layout_schema_warning() . '</footer>';
    foreach ($GLOBALS['layout_js'] ?? ['assets/app.js'] as $js) {
        echo '<script src="' . h(app_url($js)) . '?v=' . h(APP_VERSION) . '"></script>';
    }
    echo '</body></html>';
}

/**
 * A whole error page (403 / 404 / ...) with the normal chrome and a way back. Ends the request.
 * Used for "not found", "not allowed" and similar dead ends so every screen words them the same way.
 */
function layout_error_page(int $status, string $title, string $message, string $backHref = 'dashboard.php', string $backLabel = 'Dashboard'): never
{
    http_response_code($status);
    layout_header($title);
    echo '<div class="card"><h1>' . h($title) . '</h1><p>' . h($message) . '</p>'
        . '<p><a class="btn" href="' . h(app_url($backHref)) . '">' . h($backLabel) . '</a></p></div>';
    layout_footer();
    exit;
}

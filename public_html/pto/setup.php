<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * One-time installer (SPEC section 7.10): setup.php?token=<config install_token>
 * Checks PHP, extensions, .htaccess and the DB connection; applies 001_init.sql when the settings table is
 * missing; creates the first admin and both viewer passwords. Delete this file afterwards.
 */
$token = (string) (get('token') ?? post('token') ?? '');
$expected = (string) config('install_token', '');
// A config.example.php deployed unedited must not open the installer: the placeholder or anything short
// counts as "no token set" and the page answers 404.
$tokenUsable = strlen($expected) >= 16 && $expected !== 'CHANGE-ME';
if (!$tokenUsable || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(404);
    layout_header('Not found', ['nav' => false]);
    echo '<div class="card narrow"><h1>Not found</h1></div>';
    layout_footer();
    exit;
}

// --- checks -------------------------------------------------------------------------------------------
$checks = [];
$checks[] = ['PHP ' . PHP_VERSION . ' (8.2+ required)', version_compare(PHP_VERSION, '8.2.0', '>=')];
foreach (['pdo_mysql', 'openssl', 'mbstring', 'json', 'session', 'curl'] as $ext) {
    $checks[] = ["extension $ext", extension_loaded($ext)];
}
$ht = __DIR__ . '/.htaccess';
$checks[] = ['.htaccess with RewriteEngine On', is_file($ht) && str_contains((string) file_get_contents($ht), 'RewriteEngine On')];
$checks[] = ['pto_data writable (logs, sessions)', is_writable(PTO_DATA . '/logs') && is_writable(PTO_DATA . '/sessions')];
$checks[] = ['config secret set (64 hex chars)', (bool) preg_match('/^[0-9a-f]{64,}$/i', (string) config('secret', ''))];
$checks[] = ['config install_token set (16+ chars, not the example placeholder)', $tokenUsable];
$dbOk = false;
$dbMsg = '';
try {
    db();
    $dbOk = true;
} catch (Throwable $e) {
    $dbMsg = $e->getMessage();
}
$checks[] = ['database connection' . ($dbMsg !== '' ? ' (' . $dbMsg . ')' : ''), $dbOk];
$schemaPresent = false;
$userCount = 0;
if ($dbOk) {
    $schemaPresent = col("SHOW TABLES LIKE 'settings'") !== null;
    if ($schemaPresent) {
        $userCount = (int) col('SELECT COUNT(*) FROM users');
    }
}
$checks[] = ['schema present (settings table)', $schemaPresent];
$allOk = array_reduce($checks, static fn(bool $c, array $x): bool => $c && $x[1], true);

// --- actions -------------------------------------------------------------------------------------------
$msg = null;
$err = null;
if (is_post() && $dbOk) {
    $action = post('action');
    if ($action === 'migrate' && !$schemaPresent) {
        $n = apply_sql_file(PTO_APP . '/migrations/001_init.sql');
        flash('ok', "Applied 001_init.sql ($n statements).");
        redirect('setup.php?token=' . rawurlencode($token));
    }
    if ($action === 'admin' && $schemaPresent && $userCount === 0) {
        $email = (string) post('email', '');
        $name = (string) post('display_name', '');
        $pw = (string) post('password', '');
        $pw2 = (string) post('password2', '');
        $vUs = (string) post('viewer_us', '');
        $vMn = (string) post('viewer_manila', '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $err = 'Enter a valid email and a display name.';
        } elseif (strlen($pw) < 11 || $pw !== $pw2) {
            $err = 'The admin password needs 11+ characters and must be typed twice the same.';
        } elseif (strlen($vUs) < 11 || strlen($vMn) < 11) {
            $err = 'Both viewer passwords need 11+ characters.';
        } else {
            tx(static function () use ($email, $name, $pw, $vUs, $vMn): void {
                $id = user_create($email, $name, $pw, 'admin', null, false);
                audit('insert', 'users', $id, null, null, null, ['email' => $email, 'role' => 'admin'], "Setup created admin $email");
                viewer_set_password((int) group_by_key('us')['id'], $vUs);
                viewer_set_password((int) group_by_key('manila')['id'], $vMn);
            });
            flash('ok', 'Admin account and viewer passwords saved.');
            redirect('setup.php?token=' . rawurlencode($token));
        }
    }
}

layout_header('Setup', ['nav' => false]);
echo '<div class="card"><h1>PTO Tracker setup</h1>';
if ($err !== null) {
    echo '<div class="flash flash-err">' . h($err) . '</div>';
}
echo '<ul class="checks">';
foreach ($checks as [$label, $ok]) {
    echo '<li class="' . ($ok ? 'ok' : 'bad') . '">' . h($label) . '</li>';
}
echo '</ul>';

if ($dbOk && !$schemaPresent) {
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="action" value="migrate">';
    echo '<p>The database is empty. Apply <code>migrations/001_init.sql</code> (tables, the two groups, ten calendars)?</p>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Create the schema</button></div></form>';
} elseif ($schemaPresent && $userCount === 0) {
    echo '<h2>First admin and viewer passwords</h2>';
    echo '<form method="post" data-autofocus-first>' . csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '"><input type="hidden" name="action" value="admin">';
    echo '<div class="form-row"><div><label for="email">Admin email</label><input type="email" id="email" name="email" value="' . h((string) post('email', '')) . '" required></div>';
    echo '<div><label for="display_name">Display name</label><input type="text" id="display_name" name="display_name" value="' . h((string) post('display_name', '')) . '" required></div></div>';
    echo '<div class="form-row"><div><label for="password">Admin password (11+)</label><input type="password" id="password" name="password" autocomplete="new-password" required minlength="11"></div>';
    echo '<div><label for="password2">Repeat</label><input type="password" id="password2" name="password2" autocomplete="new-password" required minlength="11"></div></div>';
    echo '<div class="form-row"><div><label for="viewer_us">Viewer password: Lightsaber Promotions (11+)</label><input type="password" id="viewer_us" name="viewer_us" autocomplete="off" required minlength="11"></div>';
    echo '<div><label for="viewer_manila">Viewer password: Bright Bird Design (11+)</label><input type="password" id="viewer_manila" name="viewer_manila" autocomplete="off" required minlength="11"></div></div>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Create admin</button></div></form>';
} elseif ($schemaPresent) {
    echo '<div class="flash flash-ok">Setup is complete: ' . h((string) $userCount) . ' user account(s) exist.</div>';
    echo '<p><strong>Delete this file now:</strong> <code>public_html/pto/setup.php</code>. Then <a href="' . h(app_url('login.php')) . '">log in</a> '
        . 'and use Admin &gt; Import to load the sheet snapshots.</p>';
} else {
    echo '<p>Fix the failed checks above (config.php lives in <code>pto_data/</code>, see <code>pto_app/config.example.php</code>) and reload.</p>';
}
echo '</div>';
layout_footer();

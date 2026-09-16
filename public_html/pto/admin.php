<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Admin (SPEC section 7.9). Admin role only. Five tabs:
 *   users      accounts: add with a temporary password (must_change_password), role, group limit, deactivate/reactivate
 *   groups     per group: viewer password (viewer_set_password() bumps the trust-cookie version),
 *              holidays_excluded_from, viewer title/heading, calendar embed URL
 *   calendars  Google setup status, the global sync_mode switch, the ten calendar rows (Google calendar id, active/sync
 *              toggles) and per calendar the Milestone 2 sync actions (test, preview, sync now, force, adopt, unmanaged /
 *              orphaned events, wipe birthdays), reset sync state, test alert and the sync.log tail (SPEC 14.5)
 *   import     upload a sheet snapshot JSON for one group: dry run / commit / replace all (tools/import_sheet.php)
 *   selftest   environment checks and tests/balance_test.php run in a separate PHP process
 * The version footer (app, engine, schema, PHP) is printed by layout_footer() on every page.
 * Private helpers are prefixed admin_ (candidates for lib/ if another screen needs them).
 */

$me = require_role('admin');

const ADMIN_TABS = ['users' => 'Users', 'groups' => 'Groups', 'calendars' => 'Calendars', 'import' => 'Import', 'selftest' => 'Self-test'];
const ADMIN_MIN_TEMP_PASSWORD = 8;      // a typed temporary password; the user must replace it at first login
const ADMIN_MIN_VIEWER_PASSWORD = 12;   // SPEC section 8: a shared 12+ character password
const ADMIN_MAX_UPLOAD = 8 * 1024 * 1024;
const ADMIN_EMBED_PREFIX = 'https://calendar.google.com/';   // the only frame-src the CSP allows

$tab = (string) get('tab', 'users');
if (!isset(ADMIN_TABS[$tab])) {
    $tab = 'users';
}

// ---------------------------------------------------------------------------------------------------
// private helpers
// ---------------------------------------------------------------------------------------------------

/** Back to a tab after a POST (post-redirect-get). */
function admin_redirect(string $tab, string $extra = ''): never
{
    redirect('admin.php?tab=' . rawurlencode($tab) . $extra);
}

/** Every group (active or not) as id => row. Admins see all groups; groups_all() would filter for editors. */
function admin_groups(): array
{
    $out = [];
    foreach (rows('SELECT * FROM groups ORDER BY sort_order, id') as $g) {
        $out[(int) $g['id']] = $g;
    }
    return $out;
}

/** A users row without the hash, for audit before/after images. */
function admin_user_image(array $u): array
{
    unset($u['password_hash']);
    return $u;
}

/** Active admins other than $exceptId (guards against locking everyone out of Admin). */
function admin_other_active_admins(int $exceptId): int
{
    return (int) col("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$exceptId]);
}

/** 14 characters from an alphabet without look-alikes (no 0/O, 1/l/I). */
function admin_generate_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 14; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/** One-shot results (import report, self-test output) survive the redirect in the session. */
function admin_stash_put(string $key, array $data): void
{
    $_SESSION['admin_' . $key] = $data;
}

function admin_stash_take(string $key): ?array
{
    $d = $_SESSION['admin_' . $key] ?? null;
    unset($_SESSION['admin_' . $key]);
    return is_array($d) ? $d : null;
}

/** Group limit from the form: '' = all groups; admins always get NULL (they see everything anyway). */
function admin_group_limit_from_post(string $role, array $groups): ?int
{
    $raw = (string) post('group_id', '');
    if ($role === 'admin' || $raw === '') {
        return null;
    }
    $id = (int) $raw;
    return isset($groups[$id]) ? $id : null;
}

/** Validation shared by add and edit; returns error messages. */
function admin_validate_user(string $email, string $name, string $role, ?int $excludeId): array
{
    $errors = [];
    if ($email === '' || strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address (it is the login name).';
    } elseif (col('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $excludeId ?? 0]) !== null) {
        $errors[] = "An account with the email $email already exists.";
    }
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'Enter a display name (up to 80 characters).';
    }
    if (!in_array($role, ['admin', 'editor'], true)) {
        $errors[] = 'Role must be admin or editor.';
    }
    return $errors;
}

/** Human text for a $_FILES error code. */
function admin_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
        UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded; try again.',
        UPLOAD_ERR_NO_FILE => 'Choose a snapshot JSON file first.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload (temp folder).',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
        default => 'Upload failed (code ' . $code . ').',
    };
}

/** True when the function exists and is not in disable_functions (shared hosts often disable proc_open/exec). */
function admin_function_enabled(string $fn): bool
{
    if (!function_exists($fn)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array($fn, $disabled, true);
}

/**
 * A PHP command-line binary for the self-test. The running binary is right for the built-in server and CLI;
 * under php-fpm / lsphp / cgi it is not an interpreter for scripts, so the cPanel EasyApache CLI is tried instead.
 */
function admin_php_binary(): ?string
{
    $candidates = [
        sprintf('/opt/cpanel/ea-php%d%d/root/usr/bin/php', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
        '/usr/local/bin/php',
        '/usr/bin/php',
    ];
    $running = (string) PHP_BINARY;
    if ($running !== '' && !preg_match('/fpm|lsphp|cgi/i', basename($running))) {
        array_unshift($candidates, $running);
    }
    foreach ($candidates as $c) {
        if (is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    return null;
}

/**
 * Run tests/balance_test.php in a separate PHP process and capture its output and exit code.
 * (The test file plain-requires the engine files and calls exit(), so it cannot be included in-process.)
 */
function admin_run_selftest(): array
{
    $script = PTO_APP . '/tests/balance_test.php';
    $result = ['ok' => false, 'code' => null, 'output' => '', 'command' => '', 'ms' => 0, 'at' => now_str(), 'error' => null];
    $started = microtime(true);
    if (!is_file($script)) {
        $result['error'] = 'Missing ' . $script;
        return $result;
    }
    $php = admin_php_binary();
    if ($php === null) {
        $result['error'] = 'No PHP command-line binary was found on this server. Run "php pto_app/tests/balance_test.php" from a shell instead.';
        return $result;
    }
    $result['command'] = $php . ' ' . $script;
    $out = '';
    $err = '';
    $code = null;
    if (admin_function_enabled('proc_open')) {
        $proc = proc_open([$php, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PTO_APP);
        if (!is_resource($proc)) {
            $result['error'] = 'proc_open failed to start ' . $php;
            return $result;
        }
        // The test prints one line on success and a short list on failure, so sequential reads are safe.
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    } elseif (admin_function_enabled('exec')) {
        $lines = [];
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
        $out = implode("\n", $lines) . "\n";
    } else {
        $result['error'] = 'proc_open and exec are disabled on this server. Run "php pto_app/tests/balance_test.php" from a shell (cPanel Terminal or SSH) instead.';
        return $result;
    }
    $text = $out . ($err !== '' ? "\n[stderr]\n" . $err : '');
    $text = (string) preg_replace('/\e\[[0-9;]*m/', '', $text);   // strip the runner's ANSI colours
    $result['output'] = trim($text);
    $result['code'] = $code;
    $result['ok'] = $code === 0 && str_contains($text, 'OK:');
    $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    return $result;
}

/** Environment checks for the Self-test tab: list of [ok, label]. */
function admin_env_checks(): array
{
    $checks = [];
    $checks[] = [version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP ' . PHP_VERSION . ' (8.2 or newer)'];
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'session', 'curl'] as $ext) {
        $checks[] = [extension_loaded($ext), 'extension ' . $ext . ($ext === 'curl' ? ' (needed for Milestone 2 calendar sync)' : '')];
    }
    try {
        $checks[] = [true, 'database connection ok (server ' . (string) db()->getAttribute(PDO::ATTR_SERVER_VERSION) . ')'];
    } catch (Throwable $e) {
        $checks[] = [false, 'database connection failed: ' . $e->getMessage()];
        return $checks;
    }
    $schema = setting('schema_version', '?') ?? '?';
    $checks[] = [$schema === '1', 'schema_version ' . $schema];
    $engine = setting('engine_version', '?') ?? '?';
    $checks[] = [$engine === ENGINE_VERSION, 'engine_version setting ' . $engine . ' matches the code (' . ENGINE_VERSION . ')'];
    $env = (string) config('environment', 'production');
    $checks[] = [in_array($env, ['production', 'local'], true), 'environment ' . $env . ', sync_mode ' . (setting('sync_mode', '?') ?? '?')
        . ($env !== 'production' ? ' (not production: calendar sync stays dry-run, Google writes are refused)' : '')];
    $checks[] = [strlen((string) config('secret', '')) >= 64, 'config secret is at least 32 bytes (signs viewer trust cookies)'];
    $checks[] = [is_writable(PTO_DATA . '/logs'), 'pto_data/logs is writable'];
    $checks[] = [is_writable(PTO_DATA . '/sessions'), 'pto_data/sessions is writable'];
    $checks[] = [is_file(__DIR__ . '/.htaccess'), 'public_html/pto/.htaccess present (RewriteEngine On)'];
    $checks[] = [!is_file(__DIR__ . '/setup.php'), 'setup.php has been deleted after install'];
    $checks[] = [is_file(PTO_APP . '/tests/balance_test.php'), 'tests/balance_test.php present'];
    $php = admin_php_binary();
    $checks[] = [$php !== null && (admin_function_enabled('proc_open') || admin_function_enabled('exec')),
        'can run the engine test in a separate PHP process (' . ($php ?? 'no CLI binary found') . ')'];
    return $checks;
}

// ---------------------------------------------------------------------------------------------------
// POST handlers (each ends in a redirect)
// ---------------------------------------------------------------------------------------------------

function admin_post_user_add(): never
{
    $groups = admin_groups();
    $email = mb_strtolower((string) post('email', ''));
    $name = (string) post('display_name', '');
    $role = (string) post('role', 'editor');
    $groupId = admin_group_limit_from_post($role, $groups);
    $temp = (string) post('temp_password', '');
    $errors = admin_validate_user($email, $name, $role, null);
    $generated = false;
    if ($temp === '') {
        $temp = admin_generate_password();
        $generated = true;
    } elseif (strlen($temp) < ADMIN_MIN_TEMP_PASSWORD) {
        $errors[] = 'The temporary password needs at least ' . ADMIN_MIN_TEMP_PASSWORD . ' characters (or leave it blank to generate one).';
    }
    if ($errors !== []) {
        foreach ($errors as $e) {
            flash('err', $e);
        }
        admin_redirect('users');
    }
    tx(static function () use ($email, $name, $temp, $role, $groupId, $groups): void {
        // Every new account gets a temporary password and must change it at first login (SPEC section 7.1).
        $id = user_create($email, $name, $temp, $role, $groupId, true);
        $after = admin_user_image(row('SELECT * FROM users WHERE id = ?', [$id]) ?? []);
        $limit = $groupId === null ? 'all groups' : $groups[$groupId]['name'];
        audit('insert', 'users', $id, null, $groupId, null, $after, "$name ($email) added as $role, $limit");
    });
    flash('ok', "Account for $name created. Temporary password" . ($generated ? ' (generated)' : '') . ": $temp  - they must choose their own at first login.");
    admin_redirect('users');
}

function admin_post_user_save(): never
{
    $groups = admin_groups();
    $id = (int) post('id', 0);
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    if ($u === null) {
        flash('err', 'That user no longer exists.');
        admin_redirect('users');
    }
    $email = mb_strtolower((string) post('email', ''));
    $name = (string) post('display_name', '');
    $role = (string) post('role', 'editor');
    $groupId = admin_group_limit_from_post($role, $groups);
    $temp = (string) post('temp_password', '');
    $errors = admin_validate_user($email, $name, $role, $id);
    if ($u['role'] === 'admin' && $role !== 'admin' && (int) $u['is_active'] === 1 && admin_other_active_admins($id) === 0) {
        $errors[] = 'This is the only active admin. Make someone else an admin before changing this role.';
    }
    if ($temp !== '' && strlen($temp) < ADMIN_MIN_TEMP_PASSWORD) {
        $errors[] = 'The temporary password needs at least ' . ADMIN_MIN_TEMP_PASSWORD . ' characters.';
    }
    if ($errors !== []) {
        foreach ($errors as $e) {
            flash('err', $e);
        }
        admin_redirect('users', '&edit=' . $id);
    }
    $data = ['email' => $email, 'display_name' => $name, 'role' => $role, 'group_id' => $groupId];
    if ($temp !== '') {
        $data['password_hash'] = password_hash($temp, PASSWORD_DEFAULT);
        $data['must_change_password'] = 1;
    }
    tx(static function () use ($id, $u, $data, $name, $temp): void {
        update_row('users', $data, 'id = ?', [$id]);
        $after = admin_user_image(row('SELECT * FROM users WHERE id = ?', [$id]) ?? []);
        audit('update', 'users', $id, null, $data['group_id'], admin_user_image($u), $after,
            "$name: account updated" . ($temp !== '' ? ', temporary password set' : ''));
    });
    flash('ok', "$name saved." . ($temp !== '' ? " Temporary password: $temp  - they must choose their own at next login." : ''));
    admin_redirect('users');
}

function admin_post_user_active(array $me): never
{
    $id = (int) post('id', 0);
    $to = post('to') === '1' ? 1 : 0;
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    if ($u === null) {
        flash('err', 'That user no longer exists.');
        admin_redirect('users');
    }
    if ($id === (int) $me['id']) {
        flash('err', 'You cannot deactivate your own account.');
        admin_redirect('users');
    }
    if ($to === 0 && $u['role'] === 'admin' && (int) $u['is_active'] === 1 && admin_other_active_admins($id) === 0) {
        flash('err', 'This is the only active admin; it cannot be deactivated.');
        admin_redirect('users');
    }
    if ((int) $u['is_active'] === $to) {
        flash('ok', 'No change.');
        admin_redirect('users');
    }
    tx(static function () use ($id, $u, $to): void {
        update_row('users', ['is_active' => $to], 'id = ?', [$id]);
        $after = admin_user_image(row('SELECT * FROM users WHERE id = ?', [$id]) ?? []);
        audit('update', 'users', $id, null, $u['group_id'] === null ? null : (int) $u['group_id'], admin_user_image($u), $after,
            $u['display_name'] . ': account ' . ($to === 1 ? 'reactivated' : 'deactivated'));
    });
    flash('ok', $u['display_name'] . ($to === 1 ? ' reactivated.' : ' deactivated. Their sessions end at the next page load.'));
    admin_redirect('users');
}

function admin_post_group_save(): never
{
    $id = (int) post('id', 0);
    $g = group_by_id($id);
    if ($g === null) {
        flash('err', 'Unknown group.');
        admin_redirect('groups');
    }
    $errors = [];
    $fromRaw = (string) post('holidays_excluded_from', '');
    $from = $fromRaw === '' ? null : to_date($fromRaw);
    if ($fromRaw !== '' && $from === null) {
        $errors[] = 'Holidays excluded from: enter a date (YYYY-MM-DD) or leave it blank to switch the rule off.';
    }
    $title = (string) post('viewer_title', '');
    $heading = (string) post('viewer_heading', '');
    $embed = (string) post('viewer_embed_src', '');
    if ($title === '' || mb_strlen($title) > 80) {
        $errors[] = 'Viewer page title: 1 to 80 characters.';
    }
    if ($heading === '' || mb_strlen($heading) > 120) {
        $errors[] = 'Viewer page heading: 1 to 120 characters.';
    }
    if ($embed !== '' && (!str_starts_with($embed, ADMIN_EMBED_PREFIX) || strlen($embed) > 4000 || preg_match('/[\s"<>]/', $embed))) {
        $errors[] = 'Embed URL: must be a single URL starting with ' . ADMIN_EMBED_PREFIX . ' (the only frame source the viewer page allows), or blank.';
    }
    if ($errors !== []) {
        foreach ($errors as $e) {
            flash('err', $e);
        }
        admin_redirect('groups');
    }
    $data = [
        'holidays_excluded_from' => $from === null ? null : ymd($from),   // SPEC section 5; NULL = rule off
        'viewer_title'           => $title,
        'viewer_heading'         => $heading,
        'viewer_embed_src'       => $embed === '' ? null : $embed,
    ];
    $before = array_intersect_key($g, $data);
    $changed = [];
    foreach ($data as $k => $v) {
        if (($before[$k] ?? null) !== $v) {
            $changed[] = $k;
        }
    }
    if ($changed === []) {
        flash('ok', $g['name'] . ': no changes.');
        admin_redirect('groups');
    }
    tx(static function () use ($id, $g, $data, $before, $changed): void {
        update_row('groups', $data, 'id = ?', [$id]);
        audit('setting', 'groups', $id, null, $id, $before, $data, $g['name'] . ': ' . implode(', ', $changed) . ' updated');
    });
    flash('ok', $g['name'] . ' settings saved (' . implode(', ', $changed) . ').');
    admin_redirect('groups');
}

function admin_post_viewer_password(): never
{
    $id = (int) post('id', 0);
    $g = group_by_id($id);
    if ($g === null) {
        flash('err', 'Unknown group.');
        admin_redirect('groups');
    }
    $pw = (string) post('password', '');
    $pw2 = (string) post('password2', '');
    if (strlen($pw) < ADMIN_MIN_VIEWER_PASSWORD) {
        flash('err', 'The viewer password needs at least ' . ADMIN_MIN_VIEWER_PASSWORD . ' characters.');
        admin_redirect('groups');
    }
    if ($pw !== $pw2) {
        flash('err', 'The two viewer passwords do not match.');
        admin_redirect('groups');
    }
    // SPEC section 8: sets the hash, bumps viewer_password_version (every trust cookie of the group dies), audits.
    viewer_set_password($id, $pw);
    flash('ok', 'Viewer password for ' . $g['name'] . ' set. Every device will be asked for it again.');
    admin_redirect('groups');
}

function admin_post_calendars_save(): never
{
    $gid = post('gid', []);
    $active = post('active', []);
    $sync = post('sync', []);
    $gid = is_array($gid) ? $gid : [];
    $active = is_array($active) ? $active : [];
    $sync = is_array($sync) ? $sync : [];
    $errors = [];
    $pending = [];
    // Iterate over the DB rows, never over the posted keys.
    foreach (rows('SELECT * FROM calendars ORDER BY group_id, sort_order, cal_key') as $c) {
        $key = (string) $c['cal_key'];
        $newId = trim((string) ($gid[$key] ?? ''));
        if ($newId !== '' && (strlen($newId) > 160 || !str_contains($newId, '@') || preg_match('/[\s"<>]/', $newId))) {
            $errors[] = $c['label'] . ': a Google calendar ID looks like xxxx@group.calendar.google.com (up to 160 characters).';
            continue;
        }
        $data = [
            'google_calendar_id' => $newId === '' ? null : $newId,
            'is_active'          => isset($active[$key]) ? 1 : 0,
            'sync_enabled'       => isset($sync[$key]) ? 1 : 0,
        ];
        $before = [
            'google_calendar_id' => $c['google_calendar_id'],
            'is_active'          => (int) $c['is_active'],
            'sync_enabled'       => (int) $c['sync_enabled'],
        ];
        if ($before === $data) {
            continue;
        }
        $pending[] = [$c, $before, $data];
    }
    if ($errors !== []) {
        foreach ($errors as $e) {
            flash('err', $e);
        }
        flash('err', 'Nothing was saved.');
        admin_redirect('calendars');
    }
    if ($pending === []) {
        flash('ok', 'Calendars: no changes.');
        admin_redirect('calendars');
    }
    tx(static function () use ($pending): void {
        foreach ($pending as [$c, $before, $data]) {
            update_row('calendars', $data, 'cal_key = ?', [$c['cal_key']]);
            $changed = array_keys(array_filter($data, static fn($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
            // calendars has a string primary key, so row_id stays NULL and the summary names the cal_key.
            audit('setting', 'calendars', null, null, (int) $c['group_id'], $before, $data,
                'Calendar ' . $c['label'] . ' (' . $c['cal_key'] . '): ' . implode(', ', $changed) . ' updated');
        }
    });
    flash('ok', count($pending) . ' calendar' . (count($pending) === 1 ? '' : 's') . ' updated.');
    admin_redirect('calendars');
}

function admin_post_import(): never
{
    $groups = admin_groups();
    $groupId = (int) post('group_id', 0);
    $mode = (string) post('mode', 'dry');
    $replaceAll = post('replace_all') === '1';
    $asOf = (string) post('as_of', '');
    if (!isset($groups[$groupId])) {
        flash('err', 'Choose a group.');
        admin_redirect('import');
    }
    if ($asOf !== '' && to_date($asOf) === null) {
        flash('err', 'As of: enter a date (YYYY-MM-DD) or leave it blank.');
        admin_redirect('import');
    }
    if ($mode === 'commit' && post('confirm_commit') !== '1') {
        flash('err', 'Tick the confirmation box to commit (or use Dry run).');
        admin_redirect('import');
    }
    $f = $_FILES['snapshot'] ?? null;
    $code = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        flash('err', admin_upload_error($code));
        admin_redirect('import');
    }
    if ((int) $f['size'] > ADMIN_MAX_UPLOAD || !is_uploaded_file((string) $f['tmp_name'])) {
        flash('err', 'The snapshot must be an uploaded JSON file of at most 8 MB.');
        admin_redirect('import');
    }
    $json = json_decode((string) file_get_contents((string) $f['tmp_name']), true);
    if (!is_array($json) || $json === []) {
        flash('err', 'That file is not a JSON snapshot ({"<sheet name>": {"rows": [[...]]}}).');
        admin_redirect('import');
    }
    // Same code path as the CLI importer; it writes inside one transaction, runs the acceptance check,
    // and commits only when asked to and the check passes (SPEC section 9).
    require_once PTO_APP . '/tools/import_sheet.php';
    $report = import_snapshot($groupId, $json, [
        'commit'      => $mode === 'commit',
        'replace_all' => $replaceAll,
        'as_of'       => $asOf === '' ? null : $asOf,
    ]);
    admin_stash_put('import_report', [
        'group'       => (string) $groups[$groupId]['name'],
        'file'        => basename((string) ($f['name'] ?? 'snapshot.json')),
        'mode'        => $mode === 'commit' ? 'commit' : 'dry run',
        'replace_all' => $replaceAll,
        'ok'          => (bool) $report['ok'],
        'committed'   => (bool) $report['committed'],
        'lines'       => $report['lines'],
        'at'          => now_str(),
    ]);
    if ($report['committed']) {
        flash('ok', 'Import committed for ' . $groups[$groupId]['name'] . '.');
    } elseif ($report['ok']) {
        flash('ok', 'Dry run finished for ' . $groups[$groupId]['name'] . '; nothing was written.');
    } else {
        flash('err', 'Import for ' . $groups[$groupId]['name'] . ' failed and was rolled back; see the report.');
    }
    admin_redirect('import');
}

function admin_post_selftest(): never
{
    $result = admin_run_selftest();
    admin_stash_put('selftest', $result);
    if ($result['error'] !== null) {
        flash('err', 'Self-test could not run: ' . $result['error']);
    } else {
        flash($result['ok'] ? 'ok' : 'err', $result['ok'] ? 'Self-test passed.' : 'Self-test did not pass; see the output below.');
    }
    admin_redirect('selftest');
}

// ---------------------------------------------------------------------------------------------------
// Milestone 2: calendar sync (SPEC 14.5). Engine functions live in lib/google.php, lib/sync.php, lib/alerts.php.
// ---------------------------------------------------------------------------------------------------

/** The lists of a sync_calendar() plan in display order. */
const ADMIN_PLAN_LISTS = [
    'inserts'          => 'Inserts',
    'patches'          => 'Patches',
    'deletes'          => 'Deletes',
    'departed_cleanup' => 'Departed cleanup',
    'orphaned'         => 'Orphaned (reported, never deleted automatically)',
    'unmanaged'        => 'Unmanaged (no lsp key)',
];
const ADMIN_PLAN_SHOW = 50;        // items shown per list (SPEC 14.5)
const ADMIN_REMOTE_SHOW = 200;     // unmanaged / orphaned events listed for adopt / delete

/** Per-calendar results of this session's sync actions (test, preview, last run, adopt, unmanaged, wipe). Not one-shot. */
function admin_sync_stash_get(string $calKey, string $what): ?array
{
    $d = $_SESSION['admin_sync'][$calKey][$what] ?? null;
    return is_array($d) ? $d : null;
}

function admin_sync_stash_set(string $calKey, string $what, ?array $data): void
{
    if ($data === null) {
        unset($_SESSION['admin_sync'][$calKey][$what]);
        return;
    }
    $_SESSION['admin_sync'][$calKey][$what] = $data + ['at' => now_str()];
}

/** Drop one Google event id from the stashed unmanaged list and the last preview's orphaned list (after adopt / delete). */
function admin_sync_stash_forget_event(string $calKey, string $googleEventId): void
{
    $un = admin_sync_stash_get($calKey, 'unmanaged');
    if ($un !== null) {
        $un['items'] = array_values(array_filter($un['items'] ?? [], static fn(array $e): bool => (string) ($e['id'] ?? '') !== $googleEventId));
        $_SESSION['admin_sync'][$calKey]['unmanaged'] = $un;
    }
    $pv = admin_sync_stash_get($calKey, 'preview');
    if ($pv !== null && isset($pv['plan']['orphaned']['items'])) {
        $pv['plan']['orphaned']['items'] = array_values(array_filter($pv['plan']['orphaned']['items'],
            static fn(array $e): bool => (string) ($e['google_event_id'] ?? '') !== $googleEventId));
        $_SESSION['admin_sync'][$calKey]['preview'] = $pv;
    }
}

/** The calendars row for the posted cal_key, or a flash + redirect. */
function admin_sync_calendar(): array
{
    $key = (string) post('cal_key', '');
    $c = $key === '' ? null : row('SELECT * FROM calendars WHERE cal_key = ?', [$key]);
    if ($c === null) {
        flash('err', 'Unknown calendar.');
        admin_redirect('calendars');
    }
    return $c;
}

function admin_sync_redirect(string $calKey): never
{
    admin_redirect('calendars', '#cal-' . rawurlencode($calKey));
}

/** "Configured" = the service account key file is in place and parses (google_status()['configured']). */
function admin_google_configured(array $gs): bool
{
    return (bool) $gs['configured'];
}

/** Refuse a Google action until the key file is in place (SPEC 14.5: degrade gracefully). No token probe here. */
function admin_require_google(): void
{
    $gs = google_status(false);
    if (!admin_google_configured($gs)) {
        flash('err', 'Google is not configured: ' . $gs['message']);
        admin_redirect('calendars');
    }
}

/** A plan trimmed to the first ADMIN_PLAN_SHOW items per list, with the full counts kept. */
function admin_plan_trim(array $plan): array
{
    $out = [];
    foreach (ADMIN_PLAN_LISTS as $list => $label) {
        $items = is_array($plan[$list] ?? null) ? array_values($plan[$list]) : [];
        $out[$list] = ['count' => count($items), 'items' => array_slice($items, 0, ADMIN_PLAN_SHOW)];
    }
    return $out;
}

/** A list of engine result items trimmed to $max entries, with the count kept. */
function admin_list_trim(mixed $items, int $max): array
{
    $items = is_array($items) ? array_values($items) : [];
    return ['count' => count($items), 'items' => array_slice($items, 0, $max)];
}

/** Engine items and remote events carry Google's exclusive end date; the screens show the inclusive last day. */
function admin_end_inclusive(string $endExclusive, string $start): string
{
    $d = to_date($endExclusive);
    if ($d === null) {
        return $endExclusive;
    }
    $inc = $d->modify('-1 day');
    return $inc->format('Y-m-d') < $start ? $start : $inc->format('Y-m-d');
}

/** The inclusive DB end date as Google's exclusive end (end + 1 day). */
function admin_end_exclusive(string $endInclusive): string
{
    $d = to_date($endInclusive);
    return $d === null ? $endInclusive : $d->modify('+1 day')->format('Y-m-d');
}

/** "inserts=3 patches=1 deletes=0" for an executed / counts array. */
function admin_counts_text(array $counts): string
{
    $parts = [];
    foreach ($counts as $k => $v) {
        if (is_scalar($v)) {
            $parts[] = $k . '=' . (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
        }
    }
    return implode(' ', $parts);
}

/**
 * One line of text for a plan / adopt item: "Walker, Rebecca - Vacation 2026-10-11..2026-10-13 [time_off:12] (reason)".
 * Items are arrays with key, title, start, end, google_event_id?, reason? (SPEC 14.3); anything else is shown as JSON.
 */
function admin_item_text(mixed $item): string
{
    if (is_string($item)) {
        return $item;
    }
    if (!is_array($item)) {
        return (string) json_encode($item);
    }
    $title = (string) ($item['title'] ?? '');
    $start = (string) ($item['start'] ?? '');
    $end = (string) ($item['end'] ?? '');
    if ($title === '' && $start === '') {
        return (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $s = $title;
    if ($start !== '') {
        $endInc = $end !== '' ? admin_end_inclusive($end, $start) : '';
        $s .= ' ' . $start . ($endInc !== '' && $endInc !== $start ? '..' . $endInc : '');
    }
    if (!empty($item['key'])) {
        $s .= ' [' . (string) $item['key'] . ']';
    }
    if (!empty($item['google_event_id'])) {
        $s .= ' {' . (string) $item['google_event_id'] . '}';
    }
    if (!empty($item['reason'])) {
        $s .= ' (' . (string) $item['reason'] . ')';
    }
    return $s;
}

/**
 * Rows of a calendar that have no Google event yet, as adopt targets: [ ['key','label','title','start','end'], ... ]
 * (pto: time_off of active employees; events: the calendar's events; birthdays: birthday_events of active employees).
 * The key is the engine's lsp key (SPEC 14.2) and the title is the canonical calendar title.
 */
function admin_unlinked_rows(array $c): array
{
    $gid = (int) $c['group_id'];
    $out = [];
    if ($c['kind'] === 'pto') {
        $sql = 'SELECT t.id, t.kind, t.start_date, t.end_date, e.name FROM time_off t JOIN employees e ON e.id = t.employee_id
                WHERE e.group_id = ? AND e.status = \'active\' AND t.google_event_id IS NULL ORDER BY t.start_date DESC, t.id DESC';
        foreach (rows($sql, [$gid]) as $r) {
            $title = $r['name'] . ' - ' . $r['kind'];
            $out[] = ['key' => 'time_off:' . (int) $r['id'], 'title' => $title, 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'end_excl' => admin_end_exclusive((string) $r['end_date']),
                'label' => $title . ' (' . fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' - ' . fmt_date($r['end_date']) : '') . ') #' . (int) $r['id']];
        }
    } elseif ($c['kind'] === 'events') {
        foreach (rows('SELECT id, title, start_date, end_date FROM events WHERE cal_key = ? AND google_event_id IS NULL ORDER BY start_date DESC, id DESC', [$c['cal_key']]) as $r) {
            $out[] = ['key' => 'event:' . (int) $r['id'], 'title' => (string) $r['title'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'], 'end_excl' => admin_end_exclusive((string) $r['end_date']),
                'label' => $r['title'] . ' (' . fmt_date($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' - ' . fmt_date($r['end_date']) : '') . ') #' . (int) $r['id']];
        }
    } else {
        $sql = 'SELECT b.employee_id, b.year, e.name, e.birth_month, e.birth_day FROM birthday_events b JOIN employees e ON e.id = b.employee_id
                WHERE e.group_id = ? AND e.status = \'active\' AND b.google_event_id IS NULL ORDER BY b.year DESC, e.name';
        foreach (rows($sql, [$gid]) as $r) {
            // Feb 29 rolls to Mar 1 in a non-leap year, the same way the engine builds the event (SPEC 14.2).
            $d = (new DateTimeImmutable('2000-01-01'))->setDate((int) $r['year'], (int) $r['birth_month'], (int) $r['birth_day']);
            $out[] = ['key' => 'birthday:' . (int) $r['employee_id'] . ':' . (int) $r['year'], 'title' => $r['name'] . ' Birthday', 'start' => ymd($d), 'end' => ymd($d), 'end_excl' => admin_end_exclusive(ymd($d)),
                'label' => $r['name'] . ' Birthday ' . (int) $r['year'] . ' (' . $d->format('m/d/Y') . ')'];
        }
    }
    return $out;
}

// ---- POST handlers (sync) ----

function admin_post_sync_mode(): never
{
    $to = (string) post('sync_mode', '');
    if (!in_array($to, ['dry_run', 'live'], true)) {
        flash('err', 'Sync mode must be dry_run or live.');
        admin_redirect('calendars');
    }
    $from = setting('sync_mode', 'dry_run') ?? 'dry_run';
    if ($from === $to) {
        flash('ok', 'Sync mode is already ' . $to . '.');
        admin_redirect('calendars');
    }
    tx(static function () use ($from, $to): void {
        setting_set('sync_mode', $to);
        audit('setting', 'settings', null, null, null, ['sync_mode' => $from], ['sync_mode' => $to], 'sync_mode ' . $from . ' -> ' . $to);
    });
    $env = (string) config('environment', 'production');
    flash('ok', 'Sync mode set to ' . $to . '.' . ($to === 'live' && $env !== 'production'
        ? ' This is a ' . $env . ' install, so nothing is written to Google until the environment is production.' : ''));
    admin_redirect('calendars');
}

function admin_post_sync_test(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    $r = sync_test_connection((string) $c['cal_key']);
    $ok = (bool) ($r['ok'] ?? false);
    $msg = (string) ($r['message'] ?? '');
    tx(static function () use ($c, $ok, $msg): void {
        audit('sync', 'calendars', null, null, (int) $c['group_id'], null, null, 'Test connection ' . $c['cal_key'] . ': ' . ($ok ? 'ok' : 'failed') . ' - ' . $msg);
    });
    admin_sync_stash_set((string) $c['cal_key'], 'test', ['ok' => $ok, 'message' => $msg]);
    flash($ok ? 'ok' : 'err', $c['label'] . ' connection test: ' . $msg);
    admin_sync_redirect((string) $c['cal_key']);
}

function admin_post_sync_preview(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    $r = sync_calendar((string) $c['cal_key'], 'reconcile', ['dry_run' => true, 'force' => false, 'trigger' => 'admin preview']);
    admin_sync_stash_set((string) $c['cal_key'], 'preview', [
        'ok'      => (bool) ($r['ok'] ?? false),
        'message' => (string) ($r['message'] ?? ''),
        'aborted' => isset($r['aborted']) ? (string) $r['aborted'] : null,
        'plan'    => admin_plan_trim(is_array($r['plan'] ?? null) ? $r['plan'] : []),
    ]);
    flash(($r['ok'] ?? false) ? 'ok' : 'err', $c['label'] . ' preview (nothing written): ' . (string) ($r['message'] ?? ''));
    admin_sync_redirect((string) $c['cal_key']);
}

/** A sync that writes may take minutes on a big calendar: no time limit, and finish even if the browser goes away. */
function admin_long_request(): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
}

/** Sync now (reconcile) and Force (same plan, delete guard ignored). */
function admin_post_sync_run(bool $force): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    admin_long_request();
    $r = sync_calendar((string) $c['cal_key'], 'reconcile', ['dry_run' => false, 'force' => $force, 'trigger' => $force ? 'admin force' : 'admin']);
    $ok = (bool) ($r['ok'] ?? false);
    $msg = (string) ($r['message'] ?? '');
    tx(static function () use ($c, $force, $ok, $msg): void {
        audit('sync', 'calendars', null, null, (int) $c['group_id'], null, null, ($force ? 'Force sync ' : 'Sync now ') . $c['cal_key'] . ': ' . ($ok ? 'ok' : 'failed') . ' - ' . $msg);
    });
    admin_sync_stash_set((string) $c['cal_key'], 'last', [
        'ok'       => $ok,
        'message'  => $msg,
        'aborted'  => isset($r['aborted']) ? (string) $r['aborted'] : null,
        'force'    => $force,
        'executed' => is_array($r['executed'] ?? null) ? $r['executed'] : [],
        'plan'     => admin_plan_trim(is_array($r['plan'] ?? null) ? $r['plan'] : []),
    ]);
    flash($ok ? 'ok' : 'err', $c['label'] . ($force ? ' force sync: ' : ' sync: ') . $msg);
    admin_sync_redirect((string) $c['cal_key']);
}

/** Dashboard's "Sync now": reconcile every active, sync-enabled calendar of one group, then back to the dashboard. */
function admin_post_sync_group(): never
{
    $groups = admin_groups();
    $gid = (int) post('group_id', 0);
    if (!isset($groups[$gid])) {
        flash('err', 'Unknown group.');
        admin_redirect('calendars');
    }
    $back = post('return') === 'dashboard' ? 'dashboard.php?g=' . rawurlencode((string) $groups[$gid]['group_key']) : null;
    $gs = google_status(false);
    if (!admin_google_configured($gs)) {
        flash('err', 'Google is not configured: ' . $gs['message']);
        if ($back !== null) {
            redirect($back);
        }
        admin_redirect('calendars');
    }
    admin_long_request();
    $lines = [];
    $allOk = true;
    foreach (group_calendars($gid) as $c) {
        if ((int) $c['is_active'] !== 1 || (int) $c['sync_enabled'] !== 1) {
            continue;
        }
        $r = sync_calendar((string) $c['cal_key'], 'reconcile', ['dry_run' => false, 'force' => false, 'trigger' => 'dashboard']);
        $ok = (bool) ($r['ok'] ?? false);
        $allOk = $allOk && $ok;
        $lines[] = $c['label'] . ': ' . (string) ($r['message'] ?? '');
        admin_sync_stash_set((string) $c['cal_key'], 'last', [
            'ok' => $ok, 'message' => (string) ($r['message'] ?? ''), 'aborted' => isset($r['aborted']) ? (string) $r['aborted'] : null, 'force' => false,
            'executed' => is_array($r['executed'] ?? null) ? $r['executed'] : [], 'plan' => admin_plan_trim(is_array($r['plan'] ?? null) ? $r['plan'] : []),
        ]);
    }
    $summary = $lines === [] ? 'No active calendar with sync enabled.' : implode(' | ', $lines);
    tx(static function () use ($gid, $groups, $allOk, $summary): void {
        audit('sync', 'calendars', null, null, $gid, null, null, 'Sync now (' . $groups[$gid]['name'] . '): ' . ($allOk ? 'ok' : 'failed') . ' - ' . $summary);
    });
    flash($allOk && $lines !== [] ? 'ok' : 'err', $summary);
    if ($back !== null) {
        redirect($back);
    }
    admin_redirect('calendars');
}

function admin_post_sync_adopt(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    if (!in_array($c['kind'], ['pto', 'events'], true)) {
        flash('err', 'Adopt existing events works on PTO and events calendars only.');
        admin_sync_redirect((string) $c['cal_key']);
    }
    $commit = post('commit') === '1';
    if ($commit) {
        admin_long_request();
    }
    $r = sync_adopt((string) $c['cal_key'], $commit);
    $refused = isset($r['refused']) ? (string) $r['refused'] : null;
    $report = [
        'commit'           => $commit,
        'refused'          => $refused,
        'matched'          => admin_list_trim($r['matched'] ?? [], ADMIN_PLAN_SHOW),
        'unmatched_remote' => admin_list_trim($r['unmatched_remote'] ?? [], ADMIN_PLAN_SHOW),
        'unmatched_rows'   => admin_list_trim($r['unmatched_rows'] ?? [], ADMIN_PLAN_SHOW),
    ];
    admin_sync_stash_set((string) $c['cal_key'], 'adopt', $report);
    $text = sprintf('%d matched, %d remote events unmatched, %d rows unmatched', $report['matched']['count'], $report['unmatched_remote']['count'], $report['unmatched_rows']['count']);
    if ($commit && $refused === null) {
        tx(static function () use ($c, $text): void {
            audit('sync', 'calendars', null, null, (int) $c['group_id'], null, null, 'Adopt existing events ' . $c['cal_key'] . ': ' . $text);
        });
    }
    if ($refused !== null) {
        flash('err', $c['label'] . ' adopt refused: ' . $refused);
    } else {
        flash('ok', $c['label'] . ($commit ? ' adopted: ' : ' adopt dry run (nothing written): ') . $text . '.');
    }
    admin_sync_redirect((string) $c['cal_key']);
}

function admin_post_sync_adopt_one(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    $eventId = (string) post('google_event_id', '');
    $rowKey = (string) post('row_key', '');
    if ($eventId === '' || strlen($eventId) > 120 || preg_match('/[\s"<>]/', $eventId)) {
        flash('err', 'Missing Google event id.');
        admin_sync_redirect((string) $c['cal_key']);
    }
    $target = null;
    foreach (admin_unlinked_rows($c) as $r) {
        if ($r['key'] === $rowKey) {
            $target = $r;
        }
    }
    if ($target === null) {
        flash('err', 'Pick a row without a Google event to adopt it as.');
        admin_sync_redirect((string) $c['cal_key']);
    }
    $ok = sync_adopt_one((string) $c['cal_key'], $eventId, $rowKey);
    tx(static function () use ($c, $eventId, $target, $ok): void {
        audit('sync', 'calendars', null, null, (int) $c['group_id'], null, null,
            'Adopt Google event ' . $eventId . ' as ' . $target['key'] . ' (' . $target['title'] . ') on ' . $c['cal_key'] . ': ' . ($ok ? 'ok' : 'failed'));
    });
    if ($ok) {
        admin_sync_stash_forget_event((string) $c['cal_key'], $eventId);
        flash('ok', 'Adopted the Google event as ' . $target['label'] . '.');
    } else {
        flash('err', 'Could not adopt the Google event as ' . $target['label'] . '; see sync.log.');
    }
    admin_sync_redirect((string) $c['cal_key']);
}

function admin_post_sync_delete_remote(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    $ids = post('del', []);
    $ids = is_array($ids) ? array_values(array_unique(array_filter($ids, static fn($v): bool => is_string($v) && $v !== '' && strlen($v) <= 120 && !preg_match('/[\s"<>]/', $v)))) : [];
    if ($ids === []) {
        flash('err', 'Tick the events to delete first.');
        admin_sync_redirect((string) $c['cal_key']);
    }
    $done = 0;
    $failed = [];
    foreach ($ids as $id) {
        if (sync_delete_remote((string) $c['cal_key'], (string) $id)) {
            $done++;
            admin_sync_stash_forget_event((string) $c['cal_key'], (string) $id);
        } else {
            $failed[] = (string) $id;
        }
    }
    tx(static function () use ($c, $done, $failed, $ids): void {
        audit('sync', 'calendars', null, null, (int) $c['group_id'], null, null,
            'Delete from Google on ' . $c['cal_key'] . ': ' . $done . ' of ' . count($ids) . ' deleted' . ($failed !== [] ? ', failed: ' . implode(', ', $failed) : ''));
    });
    flash($failed === [] ? 'ok' : 'err', $c['label'] . ': ' . plural($done, 'event') . ' deleted from Google' . ($failed !== [] ? '; ' . count($failed) . ' failed (see sync.log)' : '') . '.');
    admin_sync_redirect((string) $c['cal_key']);
}

function admin_post_sync_unmanaged(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    $list = admin_list_trim(sync_unmanaged((string) $c['cal_key']), ADMIN_REMOTE_SHOW);
    admin_sync_stash_set((string) $c['cal_key'], 'unmanaged', $list);
    flash('ok', $c['label'] . ': ' . plural($list['count'], 'unmanaged event') . ' on the Google calendar.');
    admin_sync_redirect((string) $c['cal_key']);
}

function admin_post_sync_wipe(): never
{
    admin_require_google();
    $c = admin_sync_calendar();
    if ($c['kind'] !== 'birthdays') {
        flash('err', 'Wipe and regenerate works on birthday calendars only.');
        admin_sync_redirect((string) $c['cal_key']);
    }
    $commit = post('commit') === '1';
    if ($commit) {
        admin_long_request();
    }
    $r = sync_wipe_regenerate_birthdays((string) $c['cal_key'], $commit);
    $refused = isset($r['refused']) ? (string) $r['refused'] : null;
    $failed = (int) ($r['failed'] ?? 0);
    $done = $commit && $refused === null;
    $report = ['commit' => $done, 'refused' => $refused,
        'deleted'  => $done ? (int) ($r['deleted'] ?? 0) : (int) ($r['remote_count'] ?? 0),     // dry run: what a commit would do
        'inserted' => $done ? (int) ($r['inserted'] ?? 0) : (int) ($r['would_insert'] ?? 0),
        'failed' => $failed, 'message' => (string) ($r['message'] ?? '')];
    admin_sync_stash_set((string) $c['cal_key'], 'wipe', $report);
    if ($commit && $refused === null) {
        tx(static function () use ($c, $report): void {
            audit('sync', 'calendars', null, null, (int) $c['group_id'], null, null,
                'Wipe and regenerate birthdays ' . $c['cal_key'] . ': deleted ' . $report['deleted'] . ', inserted ' . $report['inserted'] . ' - ' . $report['message']);
        });
    }
    flash($refused !== null || $failed > 0 ? 'err' : 'ok', $c['label'] . ($refused !== null ? ' wipe refused: ' : ($commit ? ': ' : ' dry run (nothing written): ')) . $report['message']);
    admin_sync_redirect((string) $c['cal_key']);
}

function admin_post_sync_reset(): never
{
    $groups = admin_groups();
    $raw = (string) post('group_id', '');
    $gid = $raw === '' ? null : (int) $raw;
    if ($gid !== null && !isset($groups[$gid])) {
        flash('err', 'Unknown group.');
        admin_redirect('calendars');
    }
    sync_reset_state($gid);   // audited by the engine (SPEC 14.3)
    unset($_SESSION['admin_sync']);
    flash('ok', 'Sync state reset for ' . ($gid === null ? 'every calendar' : $groups[$gid]['name']) . ': every stored Google event id is forgotten; the next reconcile re-inserts (or adopt first).');
    admin_redirect('calendars');
}

function admin_post_alert_test(): never
{
    $msg = alert_test();
    tx(static function () use ($msg): void {
        audit('sync', null, null, null, null, null, null, 'Test alert: ' . $msg);
    });
    flash('ok', $msg);
    admin_redirect('calendars');
}

if (is_post()) {
    // Every admin_post_* handler ends in a redirect (declared never); the breaks keep a future handler that
    // returns from falling through into the next case.
    switch ((string) post('action', '')) {
        case 'user_add':
            admin_post_user_add();
            break;
        case 'user_save':
            admin_post_user_save();
            break;
        case 'user_active':
            admin_post_user_active($me);
            break;
        case 'group_save':
            admin_post_group_save();
            break;
        case 'group_viewer_password':
            admin_post_viewer_password();
            break;
        case 'calendars_save':
            admin_post_calendars_save();
            break;
        case 'import':
            admin_post_import();
            break;
        case 'selftest':
            admin_post_selftest();
            break;
        case 'sync_mode':
            admin_post_sync_mode();
            break;
        case 'sync_test':
            admin_post_sync_test();
            break;
        case 'sync_preview':
            admin_post_sync_preview();
            break;
        case 'sync_now':
            admin_post_sync_run(false);
            break;
        case 'sync_force':
            admin_post_sync_run(true);
            break;
        case 'sync_group':
            admin_post_sync_group();
            break;
        case 'sync_adopt':
            admin_post_sync_adopt();
            break;
        case 'sync_adopt_one':
            admin_post_sync_adopt_one();
            break;
        case 'sync_delete_remote':
            admin_post_sync_delete_remote();
            break;
        case 'sync_unmanaged':
            admin_post_sync_unmanaged();
            break;
        case 'sync_wipe':
            admin_post_sync_wipe();
            break;
        case 'sync_reset':
            admin_post_sync_reset();
            break;
        case 'alert_test':
            admin_post_alert_test();
            break;
        default:
            flash('err', 'Unknown action.');
            admin_redirect($tab);
    }
}

// ---------------------------------------------------------------------------------------------------
// rendering
// ---------------------------------------------------------------------------------------------------

function admin_render_user_form(?array $u, array $groups): void
{
    $isNew = $u === null;
    echo '<div class="card"><h2>' . ($isNew ? 'Add user' : 'Edit ' . h($u['display_name'])) . '</h2>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="' . ($isNew ? 'user_add' : 'user_save') . '">';
    if (!$isNew) {
        echo '<input type="hidden" name="id" value="' . (int) $u['id'] . '">';
    }
    echo '<div class="form-row">';
    echo '<div><label for="u_name">Name</label><input type="text" id="u_name" name="display_name" maxlength="80" required value="' . h($u['display_name'] ?? '') . '"></div>';
    echo '<div><label for="u_email">Email (login name)</label><input type="email" id="u_email" name="email" maxlength="120" required value="' . h($u['email'] ?? '') . '"></div>';
    echo '</div><div class="form-row">';
    $role = $u['role'] ?? 'editor';
    echo '<div><label for="u_role">Role</label><select id="u_role" name="role">';
    foreach (['editor' => 'Editor (data entry)', 'admin' => 'Admin (everything)'] as $val => $label) {
        echo '<option value="' . h($val) . '"' . ($role === $val ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</select></div>';
    $limit = $u['group_id'] ?? null;
    echo '<div><label for="u_group">Group limit (editors only)</label><select id="u_group" name="group_id"><option value="">All groups</option>';
    foreach ($groups as $gid => $g) {
        echo '<option value="' . $gid . '"' . ((int) ($limit ?? 0) === $gid ? ' selected' : '') . '>' . h($g['name']) . '</option>';
    }
    echo '</select></div>';
    echo '<div><label for="u_temp">Temporary password</label>'
        . '<input type="text" id="u_temp" name="temp_password" autocomplete="off" minlength="' . ADMIN_MIN_TEMP_PASSWORD . '" placeholder="'
        . ($isNew ? 'blank = generate one' : 'blank = keep the current password') . '">'
        . '<span class="help">They must choose their own password at the next login.</span></div>';
    echo '</div>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">' . ($isNew ? 'Add user' : 'Save') . '</button>';
    if (!$isNew) {
        echo '<a class="btn" href="' . h(app_url('admin.php?tab=users')) . '">Cancel</a>';
    }
    echo '</div></form></div>';
}

function admin_render_users(array $me): void
{
    $groups = admin_groups();
    $editId = (int) get('edit', 0);
    $edit = $editId > 0 ? row('SELECT * FROM users WHERE id = ?', [$editId]) : null;
    $users = rows('SELECT * FROM users ORDER BY is_active DESC, display_name, id');

    echo '<p class="help">Admins see every group and this page. Editors do data entry; with a group limit they see only that group.</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Group limit</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
        $id = (int) $u['id'];
        $isMe = $id === (int) $me['id'];
        $active = (int) $u['is_active'] === 1;
        echo '<tr><td>' . h($u['display_name']) . ($isMe ? ' <span class="badge">you</span>' : '')
            . ((int) $u['must_change_password'] === 1 ? ' <span class="badge badge-warn">must change password</span>' : '') . '</td>';
        echo '<td>' . h($u['email']) . '</td><td>' . h($u['role']) . '</td>';
        echo '<td>' . ($u['group_id'] === null ? 'All groups' : h($groups[(int) $u['group_id']]['name'] ?? ('#' . (int) $u['group_id']))) . '</td>';
        echo '<td>' . ($active ? '<span class="badge badge-ok">active</span>' : '<span class="badge badge-err">deactivated</span>') . '</td>';
        echo '<td>' . h(fmt_datetime($u['last_login_at'])) . '</td>';
        echo '<td class="actions-cell"><a class="btn btn-sm" href="' . h(app_url('admin.php?tab=users&edit=' . $id)) . '">Edit</a>';
        if (!$isMe) {
            $to = $active ? 0 : 1;
            echo '<form method="post" class="inline-form"'
                . ($to === 0 ? ' data-confirm="Deactivate ' . h($u['display_name']) . '? They cannot log in until reactivated."' : '') . '>'
                . csrf_field() . '<input type="hidden" name="action" value="user_active">'
                . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="to" value="' . $to . '">'
                . '<button class="btn btn-sm' . ($to === 0 ? ' btn-danger' : '') . '" type="submit">' . ($to === 0 ? 'Deactivate' : 'Reactivate') . '</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    admin_render_user_form($edit, $groups);
}

function admin_render_groups(): void
{
    foreach (admin_groups() as $g) {
        $id = (int) $g['id'];
        $p = 'g' . $id . '_';
        echo '<div class="card"><h2>' . h($g['name']) . ' <span class="muted">(' . h($g['group_key']) . ', ' . h($g['timezone']) . ', policy ' . h($g['policy_key']) . ')</span></h2>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="group_save"><input type="hidden" name="id" value="' . $id . '">';
        echo '<div class="form-row">';
        echo '<div><label for="' . $p . 'hef">Holidays excluded from</label><input type="date" id="' . $p . 'hef" name="holidays_excluded_from" value="' . h($g['holidays_excluded_from']) . '">'
            . '<span class="help">Requests starting on or after this date skip company holidays. Blank = rule off. Older requests stay as the sheets charged them.</span></div>';
        echo '<div><label for="' . $p . 'title">Viewer page title</label><input type="text" id="' . $p . 'title" name="viewer_title" maxlength="80" required value="' . h($g['viewer_title']) . '"></div>';
        echo '<div><label for="' . $p . 'heading">Viewer page heading</label><input type="text" id="' . $p . 'heading" name="viewer_heading" maxlength="120" required value="' . h($g['viewer_heading']) . '"></div>';
        echo '</div>';
        echo '<label for="' . $p . 'embed">Calendar embed URL (the iframe src on the viewer page)</label>'
            . '<textarea class="embed" id="' . $p . 'embed" name="viewer_embed_src" rows="4" maxlength="4000">' . h($g['viewer_embed_src']) . '</textarea>'
            . '<span class="help">Must start with ' . h(ADMIN_EMBED_PREFIX) . ' (the only frame source the viewer page allows). Blank hides the calendar.</span>';
        echo '<div class="actions"><button class="btn btn-primary" type="submit">Save settings</button>'
            . '<a class="btn" href="' . h(app_url('view.php?g=' . rawurlencode($g['group_key']))) . '">Open viewer page</a></div></form>';

        $hasPw = is_string($g['viewer_password_hash']) && $g['viewer_password_hash'] !== '';
        echo '<h3>Viewer password</h3>';
        echo '<p class="help">' . ($hasPw
            ? 'Set (version ' . h((string) $g['viewer_password_version']) . '); the viewer page is enabled.'
            : 'Not set; the viewer page is disabled until a password is set.')
            . ' Changing it bumps the version, so every device is asked for it again.</p>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="group_viewer_password"><input type="hidden" name="id" value="' . $id . '">';
        echo '<div class="form-row">';
        echo '<div><label for="' . $p . 'pw">New viewer password (' . ADMIN_MIN_VIEWER_PASSWORD . '+ characters)</label><input type="password" id="' . $p . 'pw" name="password" autocomplete="new-password" minlength="' . ADMIN_MIN_VIEWER_PASSWORD . '" required></div>';
        echo '<div><label for="' . $p . 'pw2">Repeat it</label><input type="password" id="' . $p . 'pw2" name="password2" autocomplete="new-password" minlength="' . ADMIN_MIN_VIEWER_PASSWORD . '" required></div>';
        echo '</div><div class="actions"><button class="btn" type="submit">' . ($hasPw ? 'Change viewer password' : 'Set viewer password') . '</button></div></form>';
        echo '</div>';
    }
}

/** A one-button POST form for the Calendars tab (hidden fields + label); disabled buttons carry the reason as a title. */
function admin_sync_button(string $action, array $hidden, string $label, string $btnClass = '', ?string $confirm = null, ?string $disabledWhy = null): string
{
    $html = '<form method="post" class="inline-form"' . ($confirm !== null ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($hidden as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    $html .= '<button class="btn btn-sm' . ($btnClass !== '' ? ' ' . h($btnClass) : '') . '" type="submit"'
        . ($disabledWhy !== null ? ' disabled title="' . h($disabledWhy) . '"' : '') . '>' . h($label) . '</button></form>';
    return $html;
}

/** "3 inserts, 1 patch, ..." from a trimmed plan. */
function admin_plan_counts(array $plan): string
{
    $parts = [];
    foreach (ADMIN_PLAN_LISTS as $list => $label) {
        $parts[] = (string) ($plan[$list]['count'] ?? 0) . ' ' . strtolower(explode(' (', $label)[0]);
    }
    return implode(' &middot; ', array_map('h', $parts));
}

/** The lists of a trimmed plan, each in a collapsible block with its first ADMIN_PLAN_SHOW items. */
function admin_render_plan_lists(array $plan): void
{
    foreach (ADMIN_PLAN_LISTS as $list => $label) {
        $count = (int) ($plan[$list]['count'] ?? 0);
        $items = $plan[$list]['items'] ?? [];
        echo '<details class="plan-list"><summary>' . h($label) . ' <span class="badge' . ($count > 0 ? ($list === 'deletes' || $list === 'orphaned' ? ' badge-warn' : ' badge-ok') : '') . '">' . h((string) $count) . '</span>'
            . ($count > count($items) ? ' <span class="muted">first ' . h((string) count($items)) . ' shown</span>' : '') . '</summary>';
        if ($items === []) {
            echo '<p class="muted">None.</p>';
        } else {
            echo '<ul class="plan-items">';
            foreach ($items as $it) {
                echo '<li>' . h(admin_item_text($it)) . '</li>';
            }
            echo '</ul>';
        }
        echo '</details>';
    }
}

/** A list of engine items (adopt report) as a collapsible block. */
function admin_render_item_list(string $label, array $list): void
{
    $count = (int) ($list['count'] ?? 0);
    $items = $list['items'] ?? [];
    echo '<details class="plan-list"><summary>' . h($label) . ' <span class="badge">' . h((string) $count) . '</span>'
        . ($count > count($items) ? ' <span class="muted">first ' . h((string) count($items)) . ' shown</span>' : '') . '</summary>';
    if ($items === []) {
        echo '<p class="muted">None.</p>';
    } else {
        echo '<ul class="plan-items">';
        foreach ($items as $it) {
            echo '<li>' . h(admin_item_text($it)) . '</li>';
        }
        echo '</ul>';
    }
    echo '</details>';
}

/**
 * Remote Google events (unmanaged, or the last preview's orphaned) with "adopt as" (a select of the calendar's rows
 * that have no Google event yet, the exact date + title match preselected) and delete checkboxes. Each event is
 * ['id','title','start','end'] (+ 'key' / 'reason' for orphaned ones). The checkboxes belong to one delete form
 * per list (form="..."), placed after the table so nothing nests.
 */
function admin_render_remote_events(array $c, array $events, string $listId, array $unlinked, bool $enabled): void
{
    if ($events === []) {
        echo '<p class="muted">None.</p>';
        return;
    }
    $calKey = (string) $c['cal_key'];
    $delId = 'del-' . preg_replace('/[^a-z0-9_-]/i', '', $listId);
    $why = $enabled ? null : 'Google is not configured';
    echo '<div class="table-wrap"><table class="remote-events"><thead><tr><th class="num"><span class="sr-label">Delete</span></th><th>Google event</th><th>Start</th><th>End</th><th>Adopt as</th></tr></thead><tbody>';
    foreach ($events as $ev) {
        $id = (string) ($ev['id'] ?? '');
        $title = (string) ($ev['title'] ?? '');
        $start = (string) ($ev['start'] ?? '');
        $end = (string) ($ev['end'] ?? '');
        $year = substr($start, 0, 4);
        // Candidates: rows starting in the event's year (else every unlinked row); the exact match is preselected.
        $cands = array_values(array_filter($unlinked, static fn(array $r): bool => substr($r['start'], 0, 4) === $year));
        if ($cands === []) {
            $cands = $unlinked;
        }
        $exact = null;
        foreach ($cands as $r) {
            // Remote events carry Google's exclusive end; compare it with the row's end + 1 day only.
            if ($r['start'] === $start && $r['end_excl'] === $end && mb_strtolower(trim($r['title'])) === mb_strtolower(trim($title))) {
                $exact = $r['key'];
                break;
            }
        }
        $endShown = $end === '' ? '' : admin_end_inclusive($end, $start);
        echo '<tr><td class="num"><input form="' . h($delId) . '" type="checkbox" name="del[]" value="' . h($id) . '" aria-label="Delete ' . h($title) . '"' . ($enabled ? '' : ' disabled') . '></td>';
        echo '<td>' . h($title) . '<br><span class="mono muted">' . h($id) . '</span>'
            . (!empty($ev['key']) ? ' <span class="muted">was ' . h((string) $ev['key']) . '</span>' : '')
            . (!empty($ev['reason']) ? '<br><span class="muted">' . h((string) $ev['reason']) . '</span>' : '') . '</td>';
        echo '<td>' . h(fmt_date($start) ?: $start) . '</td><td>' . h(fmt_date($endShown) ?: $endShown) . '</td>';
        echo '<td><form method="post" class="adopt-form">' . csrf_field() . '<input type="hidden" name="action" value="sync_adopt_one">'
            . '<input type="hidden" name="cal_key" value="' . h($calKey) . '"><input type="hidden" name="google_event_id" value="' . h($id) . '">';
        if ($cands === []) {
            echo '<span class="muted">no row without a Google event</span>';
        } else {
            echo '<select name="row_key" aria-label="Adopt ' . h($title) . ' as"><option value="">Choose a row...</option>';
            foreach ($cands as $r) {
                echo '<option value="' . h($r['key']) . '"' . ($r['key'] === $exact ? ' selected' : '') . '>' . h($r['label']) . ($r['key'] === $exact ? ' (match)' : '') . '</option>';
            }
            echo '</select> <button class="btn btn-sm" type="submit"' . ($why !== null ? ' disabled title="' . h($why) . '"' : '') . '>Adopt</button>';
        }
        echo '</form></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<form id="' . h($delId) . '" method="post" data-confirm="Delete the ticked events from the Google calendar ' . h($c['label']) . '? This cannot be undone.">'
        . csrf_field() . '<input type="hidden" name="action" value="sync_delete_remote"><input type="hidden" name="cal_key" value="' . h($calKey) . '">'
        . '<div class="actions"><button class="btn btn-sm btn-danger" type="submit"' . ($why !== null ? ' disabled title="' . h($why) . '"' : '') . '>Delete ticked events from Google</button>'
        . '<span class="help">Adopt links a Google event to the selected row (the row gets its id, the event gets the lsp key and canonical title). Delete removes it from Google only.</span></div></form>';
}

/** One calendar's sync card: state line, action buttons and this session's results. */
function admin_render_sync_card(array $c, array $groups, bool $configured, string $env, string $syncMode): void
{
    $key = (string) $c['cal_key'];
    $kind = (string) $c['kind'];
    $hasId = $c['google_calendar_id'] !== null && $c['google_calendar_id'] !== '';
    $why = !$configured ? 'Google is not configured' : (!$hasId ? 'No Google calendar ID (set it in the table above)' : null);
    $enabled = $why === null;
    $hid = ['cal_key' => $key];

    echo '<div class="card sync-card" id="cal-' . h($key) . '"><div class="sync-head"><h3>' . h($c['label']) . ' <span class="mono muted">' . h($key) . '</span></h3>';
    echo '<span class="badge">' . h($kind) . '</span>';
    if ((int) $c['is_active'] !== 1) {
        echo '<span class="badge badge-err">inactive</span>';
    }
    if ((int) $c['sync_enabled'] !== 1) {
        echo '<span class="badge badge-warn">sync off</span>';
    }
    if ((int) $c['dirty'] === 1) {
        echo '<span class="badge badge-warn">changes pending</span>';
    }
    echo '</div>';
    echo '<p class="help">Last sync: ';
    if ($c['last_sync_at'] === null) {
        echo 'never';
    } else {
        echo h(fmt_datetime($c['last_sync_at'])) . ' ' . ((int) $c['last_sync_ok'] === 1 ? '<span class="badge badge-ok">ok</span>' : '<span class="badge badge-err">failed</span>')
            . ($c['last_sync_message'] !== null ? ' ' . h($c['last_sync_message']) : '');
    }
    echo '</p>';

    // Action buttons. Writes are refused by the engine unless environment = production and sync_mode = live (or explicit).
    $live = $env === 'production' && $syncMode === 'live';
    echo '<div class="sync-actions">';
    echo admin_sync_button('sync_test', $hid, 'Test connection', '', null, $why);
    echo admin_sync_button('sync_preview', $hid, 'Preview plan', '', null, $why);
    echo admin_sync_button('sync_now', $hid, 'Sync now', $live ? 'btn-primary' : '', null, $why);
    echo admin_sync_button('sync_force', $hid, 'Force', 'btn-danger', 'Force sync ' . $c['label'] . ': runs the same plan but ignores the mass-delete guard (more than 25 or 20% deletes). Continue?', $why);
    if ($kind === 'pto' || $kind === 'events') {
        echo admin_sync_button('sync_adopt', $hid + ['commit' => '0'], 'Adopt existing events (dry run)', '', null, $why);
    }
    echo admin_sync_button('sync_unmanaged', $hid, 'List unmanaged events', '', null, $why);
    if ($kind === 'birthdays') {
        echo admin_sync_button('sync_wipe', $hid + ['commit' => '0'], 'Wipe and regenerate birthdays (dry run)', '', null, $why);
    }
    echo '</div>';

    // Results of this session's actions on this calendar.
    $test = admin_sync_stash_get($key, 'test');
    if ($test !== null) {
        echo '<p class="sync-result">' . ($test['ok'] ? '<span class="badge badge-ok">connection ok</span>' : '<span class="badge badge-err">connection failed</span>')
            . ' ' . h($test['message']) . ' <span class="muted">' . h(fmt_datetime($test['at'])) . '</span></p>';
    }
    $last = admin_sync_stash_get($key, 'last');
    if ($last !== null) {
        echo '<details class="sync-block" open><summary>Last run result ' . ($last['ok'] ? '<span class="badge badge-ok">ok</span>' : '<span class="badge badge-err">failed</span>')
            . ($last['force'] ? ' <span class="badge badge-warn">forced</span>' : '') . ' <span class="muted">' . h(fmt_datetime($last['at'])) . '</span></summary>';
        echo '<p>' . h($last['message']) . '</p>';
        if ($last['aborted'] !== null) {
            echo '<div class="inline-warn">Aborted: ' . h($last['aborted']) . '</div>';
        }
        if ($last['executed'] !== []) {
            echo '<p class="help">Executed: ' . h(admin_counts_text($last['executed'])) . '</p>';
        }
        echo '<p class="help">Plan: ' . admin_plan_counts($last['plan']) . '</p>';
        admin_render_plan_lists($last['plan']);
        echo '</details>';
    }
    $preview = admin_sync_stash_get($key, 'preview');
    if ($preview !== null) {
        echo '<details class="sync-block" open><summary>Preview plan <span class="badge">dry run</span> <span class="muted">' . h(fmt_datetime($preview['at'])) . '</span></summary>';
        echo '<p>' . h($preview['message']) . '</p>';
        if ($preview['aborted'] !== null) {
            echo '<div class="inline-warn">The run would abort: ' . h($preview['aborted']) . '</div>';
        }
        echo '<p class="help">' . admin_plan_counts($preview['plan']) . '</p>';
        admin_render_plan_lists($preview['plan']);
        $orphans = [];
        foreach ($preview['plan']['orphaned']['items'] ?? [] as $it) {
            if (is_array($it)) {
                $orphans[] = ['id' => (string) ($it['google_event_id'] ?? ''), 'title' => (string) ($it['title'] ?? ''), 'start' => (string) ($it['start'] ?? ''),
                    'end' => (string) ($it['end'] ?? ''), 'key' => (string) ($it['key'] ?? ''), 'reason' => (string) ($it['reason'] ?? '')];
            }
        }
        if ($orphans !== []) {
            echo '<h4>Orphaned events: re-adopt or delete</h4>';
            echo '<p class="help">Managed events whose row is gone without a delete in History (or whose owner is active). The sync never deletes these by itself.</p>';
            admin_render_remote_events($c, $orphans, 'orph-' . $key, admin_unlinked_rows($c), $enabled);
        }
        echo '</details>';
    }
    $un = admin_sync_stash_get($key, 'unmanaged');
    if ($un !== null) {
        echo '<details class="sync-block" open><summary>Unmanaged events <span class="badge">' . h((string) $un['count']) . '</span>'
            . ($un['count'] > count($un['items']) ? ' <span class="muted">first ' . h((string) count($un['items'])) . ' shown</span>' : '') . ' <span class="muted">' . h(fmt_datetime($un['at'])) . '</span></summary>';
        echo '<p class="help">Events on the Google calendar without an lsp key (typed by hand or left from the old scripts). The sync reports them and leaves them alone.</p>';
        $items = [];
        foreach ($un['items'] as $it) {
            if (is_array($it)) {
                $items[] = ['id' => (string) ($it['id'] ?? ''), 'title' => (string) ($it['title'] ?? ''), 'start' => (string) ($it['start'] ?? ''), 'end' => (string) ($it['end'] ?? '')];
            }
        }
        admin_render_remote_events($c, $items, 'unm-' . $key, admin_unlinked_rows($c), $enabled);
        echo '</details>';
    }
    $adopt = admin_sync_stash_get($key, 'adopt');
    if ($adopt !== null) {
        echo '<details class="sync-block" open><summary>Adopt existing events ' . ($adopt['refused'] !== null ? '<span class="badge badge-err">refused</span>' : ($adopt['commit'] ? '<span class="badge badge-ok">committed</span>' : '<span class="badge">dry run</span>'))
            . ' <span class="muted">' . h(fmt_datetime($adopt['at'])) . '</span></summary>';
        if ($adopt['refused'] !== null) {
            echo '<div class="inline-err">Refused: ' . h($adopt['refused']) . '</div>';
        } else {
            echo '<p class="help">Matched by exact fingerprint, then by the same dates and a case-insensitive title. A committed match stores the Google id on the row and stamps the event with its lsp key and canonical title.</p>';
            admin_render_item_list('Matched', $adopt['matched']);
            admin_render_item_list('Remote events with no matching row', $adopt['unmatched_remote']);
            admin_render_item_list('Rows with no matching remote event', $adopt['unmatched_rows']);
            if (!$adopt['commit'] && $adopt['matched']['count'] > 0) {
                echo admin_sync_button('sync_adopt', $hid + ['commit' => '1'], 'Commit: adopt ' . $adopt['matched']['count'] . ' matched events', 'btn-primary',
                    'Adopt ' . $adopt['matched']['count'] . ' Google events on ' . $c['label'] . ' (writes their lsp key and title to Google)?', $why);
            }
        }
        echo '</details>';
    }
    $wipe = admin_sync_stash_get($key, 'wipe');
    if ($wipe !== null) {
        echo '<details class="sync-block" open><summary>Wipe and regenerate birthdays ' . ($wipe['refused'] !== null ? '<span class="badge badge-err">refused</span>' : ($wipe['commit'] ? '<span class="badge badge-ok">done</span>' : '<span class="badge">dry run</span>'))
            . ' <span class="muted">' . h(fmt_datetime($wipe['at'])) . '</span></summary>';
        echo '<p>' . h($wipe['message']) . '</p><p class="help">' . h((string) $wipe['deleted']) . ' events ' . ($wipe['commit'] ? 'deleted' : 'would be deleted') . ' (every event on the calendar, past birthdays included), '
            . h((string) $wipe['inserted']) . ' birthday events ' . ($wipe['commit'] ? 'inserted' : 'would be inserted') . ' for this year and next for active employees.</p>';
        if ($wipe['failed'] > 0) {
            echo '<div class="inline-err">' . h((string) $wipe['failed']) . ' Google calls failed; see sync.log.</div>';
        }
        if (!$wipe['commit'] && $wipe['refused'] === null) {
            echo admin_sync_button('sync_wipe', $hid + ['commit' => '1'], 'Commit: wipe and regenerate', 'btn-danger',
                'Delete EVERY event on the Google calendar ' . $c['label'] . ' and insert ' . $wipe['inserted'] . ' birthday events? This cannot be undone.', $why);
        }
        echo '</details>';
    }
    echo '</div>';
}

function admin_render_calendars(): void
{
    $groups = admin_groups();
    $env = (string) config('environment', 'production');
    $syncMode = setting('sync_mode', 'dry_run') ?? 'dry_run';
    $gs = google_status();   // probes the token (one network round trip, cached 55 minutes)
    $configured = admin_google_configured($gs);
    $keyFile = (bool) $gs['key_file'];
    $clientEmail = (string) ($gs['client_email'] ?? '');
    $tokenOk = $gs['token_ok'];
    $tokenErr = (string) ($gs['error'] ?? '');
    $message = $gs['message'];

    // --- Google setup status (SPEC 14.1 / 14.5) ---
    echo '<div class="card"><h2>Google setup</h2><ul class="checks check-list">';
    echo '<li class="' . ($keyFile ? 'ok' : 'bad') . '">Service account key file pto_data/google-service-account.json '
        . ($keyFile ? 'present' : 'missing: create a service account in Google Cloud (Calendar API enabled), download its JSON key and save it there (mode 600)') . '</li>';
    if ($clientEmail !== '') {
        echo '<li class="ok">Share every calendar with <span class="mono">' . h($clientEmail) . '</span> as "Make changes to events" (calendar settings in the company Google account)</li>';
    } else {
        echo '<li class="bad">The address to share the calendars with is read from the key file (client_email)</li>';
    }
    if ($tokenOk === true) {
        echo '<li class="ok">Access token obtained (the key is valid and the Calendar API answers)</li>';
    } elseif ($keyFile && ($tokenOk === false || $tokenErr !== '')) {
        echo '<li class="bad">Token request failed: ' . h($tokenErr !== '' ? $tokenErr : $message) . '</li>';
    } else {
        echo '<li class="na">Access token not checked' . ($keyFile ? '' : ' until the key file is present') . '</li>';
    }
    echo '</ul>';
    if (!$configured) {
        echo '<div class="inline-warn">Google is not configured, so the sync buttons below are disabled and the dashboard says so. ' . h($message) . '</div>';
    } elseif ($message !== '') {
        echo '<p class="help">' . h($message) . '</p>';
    }
    echo '</div>';

    // --- Global sync mode (settings.sync_mode) ---
    echo '<div class="card"><h2>Sync mode</h2>';
    echo '<p class="help">The database is the source of truth; the Google Calendars are a mirror written by the sync. '
        . '<b>Dry run</b> computes and logs every plan but writes nothing to Google. <b>Live</b> lets the 15-minute and nightly jobs (and Sync now) insert, patch and delete events. '
        . 'Test connection, Force, Adopt and Wipe are explicit actions that write even in dry-run mode. '
        . 'Nothing is ever written unless the config environment is <b>production</b>; this install is <b>' . h($env) . '</b>'
        . ($env !== 'production' ? ', so every Google write is refused here whatever the mode' : '') . '.</p>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="sync_mode">';
    echo '<div class="segmented" role="radiogroup" aria-label="Sync mode">';
    foreach (['dry_run' => 'Dry run (plan and log only)', 'live' => 'Live (write to Google)'] as $val => $label) {
        echo '<label><input type="radio" name="sync_mode" value="' . h($val) . '"' . ($syncMode === $val ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    echo '</div>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Save sync mode</button><span class="help">Currently <b>' . h($syncMode) . '</b>. The change is audited.</span></div></form></div>';

    // --- Calendar settings (Milestone 1 table): Google calendar IDs, active / sync toggles ---
    echo '<div class="card"><h2>Calendars</h2>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="calendars_save">';
    echo '<div class="table-wrap"><table><thead><tr><th>Calendar</th><th>Group</th><th>Kind</th><th>Google calendar ID</th><th>Active</th><th>Sync</th><th>Last sync</th></tr></thead><tbody>';
    $all = rows('SELECT * FROM calendars ORDER BY group_id, sort_order, cal_key');
    foreach ($all as $c) {
        $key = (string) $c['cal_key'];
        echo '<tr><td><a href="#cal-' . h($key) . '">' . h($c['label']) . '</a><br><span class="mono muted">' . h($key) . '</span></td>';
        echo '<td>' . h($groups[(int) $c['group_id']]['name'] ?? '') . '</td><td>' . h($c['kind']) . '</td>';
        echo '<td><input class="cal-id mono" type="text" name="gid[' . h($key) . ']" maxlength="160" value="' . h($c['google_calendar_id']) . '" aria-label="Google calendar ID for ' . h($c['label']) . '"></td>';
        echo '<td class="num"><input type="checkbox" name="active[' . h($key) . ']" value="1"' . ((int) $c['is_active'] === 1 ? ' checked' : '') . ' aria-label="' . h($c['label']) . ' active"></td>';
        echo '<td class="num"><input type="checkbox" name="sync[' . h($key) . ']" value="1"' . ((int) $c['sync_enabled'] === 1 ? ' checked' : '') . ' aria-label="' . h($c['label']) . ' sync enabled"></td>';
        echo '<td>';
        if ($c['last_sync_at'] === null) {
            echo '<span class="muted">never</span>';
        } else {
            echo h(fmt_datetime($c['last_sync_at'])) . ' '
                . ((int) $c['last_sync_ok'] === 1 ? '<span class="badge badge-ok">ok</span>' : '<span class="badge badge-err">failed</span>')
                . ($c['last_sync_message'] !== null ? ' <span class="muted">' . h($c['last_sync_message']) . '</span>' : '');
        }
        if ((int) $c['dirty'] === 1) {
            echo ' <span class="badge badge-warn">changes pending</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Save calendars</button><span class="help">Untick Sync to keep a calendar out of the mirror; untick Active to hide it everywhere.</span></div></form></div>';

    // --- Per-calendar sync actions ---
    echo '<h2>Sync actions</h2>';
    echo '<p class="help">Preview plan is a reconcile dry run (one full listing of the Google calendar compared with the database). Sync now runs the reconcile for real; '
        . 'Force ignores the mass-delete guard. Adopt links events that already exist on Google to their rows instead of inserting duplicates (use it once at cutover, before the first live sync).</p>';
    foreach ($groups as $gid => $g) {
        echo '<h3 class="sync-group">' . h($g['name']) . '</h3>';
        foreach ($all as $c) {
            if ((int) $c['group_id'] === $gid) {
                admin_render_sync_card($c, $groups, $configured, $env, $syncMode);
            }
        }
    }

    // --- Global actions ---
    echo '<div class="card"><h2>Global</h2>';
    echo '<form method="post" class="inline-form" data-confirm="Reset sync state? Every stored Google event id, fingerprint and sync error is cleared. The next reconcile inserts everything again (or use Adopt first to re-link the existing events). Continue?">'
        . csrf_field() . '<input type="hidden" name="action" value="sync_reset">';
    echo '<label for="reset_group">Reset sync state for</label><div class="form-row reset-row"><div><select id="reset_group" name="group_id"><option value="">Every calendar</option>';
    foreach ($groups as $gid => $g) {
        echo '<option value="' . $gid . '">' . h($g['name']) . '</option>';
    }
    echo '</select></div><div><button class="btn btn-danger" type="submit">Reset sync state</button></div></div>';
    echo '<span class="help">Forgets which Google event belongs to which row (nothing is deleted on Google). Audited.</span></form>';
    echo '<h3>Alerts</h3><p class="help">Sync failures (3 in a row) and a failed nightly self-test email <span class="mono">' . h((string) config('alert_email', '(alert_email not set)')) . '</span> from <span class="mono">'
        . h((string) config('alert_from', '(alert_from not set)')) . '</span>, at most once a day.</p>';
    echo '<form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="action" value="alert_test"><button class="btn" type="submit">Send test alert</button></form>';
    echo '</div>';

    // --- sync.log tail ---
    $tail = sync_log_tail(100);
    echo '<div class="card"><h2>sync.log <span class="muted">(last ' . h((string) count($tail)) . ' lines)</span></h2>';
    if ($tail === []) {
        echo '<p class="help">Empty. Every sync run appends one line here (pto_data/logs/sync.log).</p>';
    } else {
        echo '<pre class="report">' . h(implode("\n", $tail)) . '</pre>';
    }
    echo '</div>';
}

function admin_render_import(): void
{
    $report = admin_stash_take('import_report');
    if ($report !== null) {
        $badge = $report['committed'] ? '<span class="badge badge-ok">committed</span>'
            : ($report['ok'] ? '<span class="badge">dry run, nothing written</span>' : '<span class="badge badge-err">failed, rolled back</span>');
        echo '<div class="card"><h2>Import report ' . $badge . '</h2>';
        echo '<p class="help">' . h($report['group']) . ' &middot; ' . h($report['file']) . ' &middot; ' . h($report['mode'])
            . ($report['replace_all'] ? ' &middot; replace all' : '') . ' &middot; ' . h(fmt_datetime($report['at'])) . '</p>';
        echo '<pre class="report">' . h(implode("\n", $report['lines'])) . '</pre></div>';
    }
    echo '<div class="card"><h2>Import a sheet snapshot</h2>';
    echo '<p class="help">The snapshot is the openpyxl dump of one workbook: <code>{"&lt;sheet name&gt;": {"rows": [[...], ...]}}</code>, first row = headers, dates as ISO strings. '
        . 'Dry run writes the rows inside a transaction, runs the acceptance check (every US row against the sheet\'s stored balances, and the expected fixture for the as-of date when there is one), prints the report and rolls back. '
        . 'Commit keeps the rows only when that check passes. A group that already has employees needs Replace all.</p>';
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="import">';
    echo '<div class="form-row">';
    echo '<div><label for="imp_group">Group</label><select id="imp_group" name="group_id" required>';
    foreach (admin_groups() as $gid => $g) {
        echo '<option value="' . $gid . '">' . h($g['name']) . ' (' . h($g['group_key']) . ')</option>';
    }
    echo '</select></div>';
    echo '<div><label for="imp_file">Snapshot JSON</label><input type="file" id="imp_file" name="snapshot" accept=".json,application/json" required></div>';
    echo '<div><label for="imp_asof">As of (optional)</label><input type="date" id="imp_asof" name="as_of">'
        . '<span class="help">Becomes holidays_excluded_from and selects tests/fixtures/expected_&lt;date&gt;.json for the balance check. Blank = the group\'s today.</span></div>';
    echo '</div>';
    echo '<label class="check"><input type="checkbox" name="replace_all" value="1"> Replace all: first delete this group\'s employees, time off, adjustments, birthday events and events (the other group, users, calendars and settings are untouched)</label>';
    echo '<label class="check"><input type="checkbox" name="confirm_commit" value="1"> I understand that Commit changes the database (required for Commit)</label>';
    echo '<div class="actions"><button class="btn" type="submit" name="mode" value="dry">Dry run</button>'
        . '<button class="btn btn-primary" type="submit" name="mode" value="commit">Commit</button></div>';
    echo '</form></div>';
}

function admin_render_selftest(): void
{
    $result = admin_stash_take('selftest');
    echo '<div class="card"><h2>Engine acceptance test</h2>';
    echo '<p class="help">Runs <code>pto_app/tests/balance_test.php</code> in a separate PHP process: all 177 US rows against the sheet\'s stored balances, the 33 current/next balances, the Manila split cases and the edge cases of SPEC section 4. It takes about a second and touches no data.</p>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="selftest"><div class="actions"><button class="btn btn-primary" type="submit">Run self-test</button></div></form>';
    if ($result !== null) {
        echo '<h3>Result ' . ($result['error'] !== null ? '<span class="badge badge-err">could not run</span>'
            : ($result['ok'] ? '<span class="badge badge-ok">passed</span>' : '<span class="badge badge-err">failed</span>')) . '</h3>';
        echo '<p class="help">' . h(fmt_datetime($result['at']))
            . ($result['code'] !== null ? ' &middot; exit code ' . h((string) $result['code']) . ' &middot; ' . h((string) $result['ms']) . ' ms' : '')
            . ($result['command'] !== '' ? ' &middot; <span class="mono">' . h($result['command']) . '</span>' : '') . '</p>';
        echo '<pre class="report">' . h($result['error'] ?? ($result['output'] !== '' ? $result['output'] : '(no output)')) . '</pre>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Environment</h2><ul class="checks check-list">';
    foreach (admin_env_checks() as [$ok, $label]) {
        echo '<li class="' . ($ok ? 'ok' : 'bad') . '">' . h($label) . '</li>';
    }
    echo '</ul>';

    echo '<h3>Versions</h3><dl class="kv">';
    $dbVersion = '?';
    try {
        $dbVersion = (string) db()->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Throwable) {
        // shown as ? when the database is unreachable
    }
    $kv = [
        'App'               => APP_VERSION,
        'Engine (code)'     => ENGINE_VERSION,
        'Engine (settings)' => setting('engine_version', '?') ?? '?',
        'Schema'            => setting('schema_version', '?') ?? '?',
        'PHP'               => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'Database'          => $dbVersion,
        'Environment'       => (string) config('environment', 'production'),
        'Server time'       => now_str() . ' ' . date_default_timezone_get(),
    ];
    foreach (admin_groups() as $g) {
        $kv['Today for ' . $g['name']] = group_today($g)->format('m/d/Y') . ' (' . $g['timezone'] . ')';
    }
    foreach ($kv as $k => $v) {
        echo '<dt>' . h($k) . '</dt><dd>' . h($v) . '</dd>';
    }
    echo '</dl>';

    $log = PTO_DATA . '/logs/error.log';
    echo '<h3>Error log</h3>';
    if (is_file($log) && filesize($log) > 0) {
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        echo '<p class="help">' . h((string) count($lines)) . ' lines, ' . h((string) (int) ceil(filesize($log) / 1024)) . ' KB; last 10 lines:</p>';
        echo '<pre class="report">' . h(implode("\n", array_slice($lines, -10))) . '</pre>';
    } else {
        echo '<p class="help">Empty. Nothing has gone wrong.</p>';
    }
    echo '</div>';
}

layout_header('Admin', ['group_tabs' => false, 'css' => ['assets/admin.css']]);
echo '<h1>Admin</h1><nav class="subtabs">';
foreach (ADMIN_TABS as $key => $label) {
    echo '<a' . ($key === $tab ? ' class="active"' : '') . ' href="' . h(app_url('admin.php?tab=' . $key)) . '">' . h($label) . '</a>';
}
echo '</nav>';
switch ($tab) {
    case 'groups':
        admin_render_groups();
        break;
    case 'calendars':
        admin_render_calendars();
        break;
    case 'import':
        admin_render_import();
        break;
    case 'selftest':
        admin_render_selftest();
        break;
    default:
        admin_render_users($me);
}
// SPEC section 7.9's version line lives here (admins only) rather than in the footer of every page.
$aboutSchema = '?';
try {
    $aboutSchema = setting('schema_version', '?') ?? '?';
} catch (Throwable) {
    // shown as ? when the database is unreachable
}
echo '<p class="about muted">About: PTO Tracker v' . h(APP_VERSION) . ' &middot; engine ' . h(ENGINE_VERSION)
    . ' &middot; schema ' . h($aboutSchema) . ' &middot; PHP ' . h(PHP_VERSION) . '</p>';
layout_footer();

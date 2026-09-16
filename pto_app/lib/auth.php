<?php
declare(strict_types=1);

/** Admin/editor accounts (LIB CONTRACT: auth.php). Viewer-page passwords live in viewer_auth.php. */

/** A bcrypt hash of a random string nobody knows; login() verifies against it when the email is unknown. */
const LOGIN_DUMMY_HASH = '$2y$10$UMp94ICJnkjE9zRyyp8UdeuRvI02l0n.GceEs43rGKfHSg6B3gZmq';

/** The logged-in users row, or null. Cached per request. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        return $user = null;
    }
    $u = row('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
    return $user = $u;
}

/** Redirects to login.php when nobody is logged in; returns the user otherwise. */
function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        if (isset($_SERVER['REQUEST_URI'])) {
            $_SESSION['after_login'] = (string) $_SERVER['REQUEST_URI'];
        }
        redirect('login.php');
    }
    // must_change_password forces the change screen (login.php handles it) before anything else.
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ((int) $u['must_change_password'] === 1 && !in_array($script, ['login.php', 'logout.php'], true)) {
        redirect('login.php?change=1');
    }
    return $u;
}

/** Like require_login() but also demands a role ('admin' or 'editor'; admins satisfy 'editor'). */
function require_role(string $role): array
{
    $u = require_login();
    if ($role === 'admin' && $u['role'] !== 'admin') {
        layout_error_page(403, 'Not allowed', 'This page is for administrators.');
    }
    return $u;
}

/** Email + password login. Failure: half-second delay, audited. Success: fresh session id, audited. */
function login(string $email, string $password): bool
{
    $email = mb_strtolower(trim($email));
    $u = row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($u === null) {
        // Unknown address: still pay the bcrypt cost against a dummy hash so the timing does not reveal
        // whether the email exists (the response text is identical either way).
        password_verify($password, LOGIN_DUMMY_HASH);
    }
    $ok = $u !== null && (int) $u['is_active'] === 1 && password_verify($password, (string) $u['password_hash']);
    if (!$ok) {
        usleep(500000);
        audit('login_failed', 'users', $u['id'] ?? null, null, null, null, null, 'Failed login for ' . $email);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['last_seen'] = time();
    unset($_SESSION['group_key']);
    update_row('users', ['last_login_at' => now_str()], 'id = ?', [(int) $u['id']]);
    if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
        update_row('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $u['id']]);
    }
    audit('login', 'users', (int) $u['id'], null, null, null, null, $u['display_name'] . ' logged in');
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

/** Admins see every group; an editor with users.group_id set sees only that group. */
function user_can_group(array $user, int $groupId): bool
{
    if ($user['role'] === 'admin' || $user['group_id'] === null) {
        return true;
    }
    return (int) $user['group_id'] === $groupId;
}

/** Create a user row (used by setup, dev_reset and Admin). Returns the id. */
function user_create(string $email, string $displayName, string $password, string $role = 'editor', ?int $groupId = null, bool $mustChange = false): int
{
    return insert('users', [
        'email'                => mb_strtolower(trim($email)),
        'display_name'         => trim($displayName),
        'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
        'role'                 => $role,
        'group_id'             => $groupId,
        'is_active'            => 1,
        'must_change_password' => $mustChange ? 1 : 0,
        'created_at'           => now_str(),
    ]);
}

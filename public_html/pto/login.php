<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/** Login (SPEC section 7.1) and the forced password change for must_change_password accounts. */
$user = current_user();
$error = null;

if ($user !== null && (int) $user['must_change_password'] === 1) {
    // Forced password change.
    if (is_post() && post('action') === 'change') {
        $new = (string) post('password', '');
        $again = (string) post('password2', '');
        if (strlen($new) < 12) {
            $error = 'Use at least 12 characters.';
        } elseif ($new !== $again) {
            $error = 'The two passwords do not match.';
        } else {
            tx(static function () use ($user, $new): void {
                update_row('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [(int) $user['id']]);
                audit('update', 'users', (int) $user['id'], null, null, null, null, $user['display_name'] . ' changed their password');
            });
            flash('ok', 'Password changed.');
            redirect('dashboard.php');
        }
    }
    layout_header('Change password', ['nav' => false]);
    echo '<div class="card narrow"><h1>Choose a new password</h1>';
    echo '<p class="help">Your account was created with a temporary password. Pick a new one (12+ characters) to continue.</p>';
    if ($error !== null) {
        echo '<div class="flash flash-err">' . h($error) . '</div>';
    }
    echo '<form method="post" data-autofocus-first>' . csrf_field() . '<input type="hidden" name="action" value="change">';
    echo '<label for="password">New password</label><input type="password" id="password" name="password" autocomplete="new-password" required minlength="12">';
    echo '<label for="password2">Repeat it</label><input type="password" id="password2" name="password2" autocomplete="new-password" required minlength="12">';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Save password</button>'
        . '<a class="btn" href="' . h(app_url('logout.php')) . '">Log out</a></div></form></div>';
    layout_footer();
    exit;
}

if ($user !== null) {
    redirect('dashboard.php');
}

if (is_post()) {
    $email = (string) post('email', '');
    $pw = (string) post('password', '');
    if (login($email, $pw)) {
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        $target = 'dashboard.php';
        // Only return to a path inside this app (never an absolute URL from elsewhere).
        if (is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//')
            && !str_contains($next, 'login.php') && !str_contains($next, 'logout.php')) {
            $target = $next;
        }
        redirect($target);
    }
    $error = 'Wrong email or password.';
}

layout_header('Login', ['nav' => false]);
echo '<div class="card narrow"><h1>PTO Tracker</h1>';
if ($error !== null) {
    echo '<div class="flash flash-err">' . h($error) . '</div>';
}
echo '<form method="post" data-autofocus-first>' . csrf_field();
echo '<label for="email">Email</label><input type="email" id="email" name="email" value="' . h((string) post('email', '')) . '" autocomplete="username" required>';
echo '<label for="password">Password</label><input type="password" id="password" name="password" autocomplete="current-password" required>';
echo '<div class="actions"><button class="btn btn-primary" type="submit">Log in</button></div>';
echo '</form></div>';
layout_footer();

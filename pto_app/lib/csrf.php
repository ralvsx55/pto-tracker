<?php
declare(strict_types=1);

/** CSRF protection (LIB CONTRACT: csrf.php). bootstrap.php calls csrf_verify() on every POST. */

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input for every POST form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Reject a POST whose token does not match the session's (403 + a plain page). */
function csrf_verify(): void
{
    if (!is_post()) {
        return;
    }
    $sent = $_POST['csrf'] ?? '';
    $ok = is_string($sent) && $sent !== '' && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $sent);
    if ($ok) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Form expired</title>'
        . '<p>This form has expired or was submitted from another site. '
        . 'Use your browser\'s Back button and try again.</p>';
    exit;
}

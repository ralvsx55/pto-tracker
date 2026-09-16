<?php
declare(strict_types=1);

/**
 * Viewer-page trust cookie (SPEC section 8).
 * Cookie pto_trust_<group_key> = base64url(payload) . '.' . hmac
 * payload = group_key|password_version|expires_unix ; hmac = hash_hmac('sha256', payload, config secret)
 * 90-day expiry; re-issued on a visit more than 30 days after issue (sliding window).
 */

const VIEWER_TRUST_DAYS = 90;
const VIEWER_REISSUE_AFTER_DAYS = 30;

function viewer_cookie_name(array $group): string
{
    return 'pto_trust_' . $group['group_key'];
}

function viewer_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function viewer_b64url_decode(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/'), true);
}

function viewer_sign(string $payload): string
{
    return hash_hmac('sha256', $payload, (string) config('secret'));
}

/** True when the device carries a valid trust cookie for this group (re-issues it when it is over 30 days old). */
function viewer_trusted(array $group): bool
{
    $raw = $_COOKIE[viewer_cookie_name($group)] ?? null;
    if (!is_string($raw) || !str_contains($raw, '.')) {
        return false;
    }
    [$b64, $mac] = explode('.', $raw, 2);
    $payload = viewer_b64url_decode($b64);
    if ($payload === false || !hash_equals(viewer_sign($payload), $mac)) {
        return false;
    }
    $parts = explode('|', $payload);
    if (count($parts) !== 3) {
        return false;
    }
    [$key, $version, $expires] = $parts;
    if ($key !== $group['group_key'] || (int) $version !== (int) $group['viewer_password_version']) {
        return false;   // wrong group, or the password changed since (version bump logs everyone out)
    }
    $expires = (int) $expires;
    $now = time();
    if ($expires <= $now) {
        return false;
    }
    $issued = $expires - VIEWER_TRUST_DAYS * 86400;
    if ($now - $issued > VIEWER_REISSUE_AFTER_DAYS * 86400) {
        viewer_issue_cookie($group);   // sliding window
    }
    return true;
}

/** Set the trust cookie for 90 days. */
function viewer_issue_cookie(array $group): void
{
    $expires = time() + VIEWER_TRUST_DAYS * 86400;
    $payload = $group['group_key'] . '|' . (int) $group['viewer_password_version'] . '|' . $expires;
    $value = viewer_b64url($payload) . '.' . viewer_sign($payload);
    $_COOKIE[viewer_cookie_name($group)] = $value;
    if (headers_sent()) {
        return;   // too late to set a cookie (call viewer_trusted() before any output); nothing else breaks
    }
    setcookie(viewer_cookie_name($group), $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Check the shared password. A failure sleeps one second and is audited with the IP. */
function viewer_check_password(array $group, string $pw): bool
{
    $hash = $group['viewer_password_hash'] ?? null;
    if (is_string($hash) && $hash !== '' && $pw !== '' && password_verify($pw, $hash)) {
        return true;
    }
    sleep(1);
    audit('viewer_login_failed', 'groups', (int) $group['id'], null, (int) $group['id'], null, null,
        'Wrong viewer password for ' . $group['name']);
    return false;
}

/** Set/change the shared password and bump the version (invalidates every trust cookie of the group). */
function viewer_set_password(int $groupId, string $pw): void
{
    tx(static function () use ($groupId, $pw): void {
        $g = group_by_id($groupId);
        if ($g === null) {
            throw new RuntimeException("Unknown group $groupId");
        }
        update_row('groups', [
            'viewer_password_hash'    => password_hash($pw, PASSWORD_DEFAULT),
            'viewer_password_version' => (int) $g['viewer_password_version'] + 1,
        ], 'id = ?', [$groupId]);
        audit('setting', 'groups', $groupId, null, $groupId, null, null, 'Viewer password changed for ' . $g['name']);
    });
}

function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

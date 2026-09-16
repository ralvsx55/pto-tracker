<?php
declare(strict_types=1);

/** Small helpers used by every page (LIB CONTRACT: helpers.php). */

/** Escape for HTML output. Every piece of dynamic output goes through here. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * URL of a page inside the app, relative to the current host: app_url('dashboard.php') => '/dashboard.php'.
 * Only the path part of config base_url is used so the same code works on 8020, 8021 and the live host.
 */
function app_url(string $path = ''): string
{
    $base = (string) parse_url((string) config('base_url', '/'), PHP_URL_PATH);
    $base = '/' . trim($base, '/');
    $base = $base === '/' ? '' : $base;
    return $base . '/' . ltrim($path, '/');
}

/** Absolute URL (config base_url + path), for emails and links that leave the app. */
function app_abs_url(string $path = ''): string
{
    return rtrim((string) config('base_url', '/'), '/') . '/' . ltrim($path, '/');
}

/**
 * 303 redirect and exit. Accepts three forms:
 *   - a page-relative path ('dashboard.php', 'request.php?id=3'): prefixed with the app base path via app_url();
 *   - an app-absolute path starting with '/' (what app_url() / events_url() / admin_redirect() already build):
 *     used as-is, so the base path is never doubled when the app is served from a sub-path;
 *   - a full http(s) URL: used as-is.
 */
function redirect(string $path): never
{
    $absolute = preg_match('#^https?://#', $path) === 1 || str_starts_with($path, '/');
    $url = $absolute ? $path : app_url($path);
    header('Location: ' . $url, true, 303);
    exit;
}

/** Flash messages live in the session and are rendered once by layout_header(). */
function flash(string $type, string $msg): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'msg' => $msg];
}

/** Returns and clears the pending flashes: [['type'=>'ok'|'err', 'msg'=>...], ...]. */
function flashes(): array
{
    $f = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return is_array($f) ? $f : [];
}

/** Format a Y-m-d string or date object; '' for null/invalid. */
function fmt_date(string|DateTimeInterface|null $d, string $fmt = 'm/d/Y'): string
{
    if ($d === null || $d === '') {
        return '';
    }
    if (is_string($d)) {
        $d = to_date($d);
        if ($d === null) {
            return '';
        }
    }
    return $d->format($fmt);
}

/** "3", "2.5", "-1", "0.25": whole numbers without decimals, others trimmed. */
function fmt_days(int|float $n): string
{
    if (abs($n - round($n)) < 1e-9) {
        return (string) (int) round($n);
    }
    return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Timestamp written by PHP (never MySQL NOW()) in the app timezone. */
function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** POST value (strings trimmed) or the default. */
function post(string $k, mixed $default = null): mixed
{
    if (!array_key_exists($k, $_POST)) {
        return $default;
    }
    $v = $_POST[$k];
    return is_string($v) ? trim($v) : $v;
}

/** GET value (strings trimmed) or the default. */
function get(string $k, mixed $default = null): mixed
{
    if (!array_key_exists($k, $_GET)) {
        return $default;
    }
    $v = $_GET[$k];
    return is_string($v) ? trim($v) : $v;
}

function ymd(DateTimeInterface $d): string
{
    return $d->format('Y-m-d');
}

/** 'Y-m-d' (or 'Y-m-d H:i:s', ISO) string to a midnight DateTimeImmutable; null when empty or invalid. */
function to_date(?string $ymd): ?DateTimeImmutable
{
    if ($ymd === null) {
        return null;
    }
    $ymd = trim($ymd);
    if ($ymd === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    if ($d === false || $d->format('Y-m-d') !== substr($ymd, 0, 10)) {
        return null;
    }
    return $d;
}

/** Client IP for audit rows. */
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
}

/** "3 days" / "1 day". */
function plural(int|float $n, string $singular, ?string $plural = null): string
{
    $plural ??= $singular . 's';
    return fmt_days($n) . ' ' . (abs($n - 1) < 1e-9 ? $singular : $plural);
}

/** 'Y-m-d H:i:s' -> 'm/d/Y H:i' ('' when null); fmt_date() keeps only the date part. */
function fmt_datetime(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt);
    return $d === false ? $dt : $d->format('m/d/Y H:i');
}

/** "+1", "-2.5": adjustment days with their sign. */
function signed_days(int|float $n): string
{
    return ($n > 0 ? '+' : '') . fmt_days($n);
}

/**
 * CSS class for a balance cell (SPEC 7.2): ' neg' below zero, ' low' at 1 or fewer, '' otherwise.
 * Returned with a leading space so it can be appended to an existing class list.
 */
function balance_class(int|float $n): string
{
    if ($n < 0) {
        return ' neg';
    }
    return $n <= 1 ? ' low' : '';
}

/** Month names for the birthday dropdowns, 1 => 'January'. */
function month_names(): array
{
    return [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July',
        8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
}

/** Free-text CSV cell: keeps spreadsheet apps from treating "=..." / "@..." as a formula. */
function csv_text(?string $s): string
{
    $s = (string) $s;
    return $s !== '' && strpbrk($s[0], '=+-@') !== false ? "'" . $s : $s;
}

/**
 * One CSV line. The single place that calls fputcsv, so every export uses the same quoting: comma, double
 * quote, and an empty escape character (the PHP 8.4-recommended form; a backslash escape would write a
 * value containing \" unbalanced). Free-text cells must already be wrapped in csv_text().
 */
function csv_row($fh, array $cells): void
{
    fputcsv($fh, $cells, ',', '"', '');
}

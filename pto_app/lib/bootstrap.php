<?php
declare(strict_types=1);

/**
 * Bootstrap: every web page starts with
 *   require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';
 * and every CLI tool with require __DIR__ . '/../lib/bootstrap.php'.
 * Defines paths, loads config, sets timezone/error handling/security headers/session, loads lib/, verifies CSRF.
 */

define('PTO_APP', dirname(__DIR__));
define('PTO_DATA', dirname(__DIR__, 2) . '/pto_data');
const APP_VERSION = '1.0.2';
// The settings.schema_version the code expects = the number of the newest file in pto_app/migrations/. When the
// database is behind, every logged-in page's footer says so until the missing migrations are pasted into phpMyAdmin.
const SCHEMA_VERSION = '2';
define('PTO_CLI', PHP_SAPI === 'cli');

// --- config ------------------------------------------------------------------------------------
$GLOBALS['config'] = [];
if (is_file(PTO_DATA . '/config.php')) {
    $cfg = require PTO_DATA . '/config.php';
    if (is_array($cfg)) {
        $GLOBALS['config'] = $cfg;
    }
}

/** config('db') or config('db.host'); $default when missing. */
function config(string $key, mixed $default = null): mixed
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) {
            return $default;
        }
        $v = $v[$part];
    }
    return $v;
}

function config_loaded(): bool
{
    return $GLOBALS['config'] !== [];
}

// --- timezone, errors ----------------------------------------------------------------------------
date_default_timezone_set('America/New_York');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!is_dir(PTO_DATA . '/logs')) {
    @mkdir(PTO_DATA . '/logs', 0700, true);
}
ini_set('error_log', PTO_DATA . '/logs/error.log');

// Warnings and notices become exceptions so bugs are loud in the log instead of half-rendered pages.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
        error_log("Deprecated: $str in $file:$line");
        return true;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log(sprintf("[%s] %s: %s in %s:%d\n%s", $ref, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
    if (PTO_CLI) {
        fwrite(STDERR, "Error [$ref]: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $local = config('environment', 'production') !== 'production';
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $css = function_exists('app_url') ? app_url('assets/app.css') : 'assets/app.css';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong</title>'
        . '<link rel="stylesheet" href="' . $esc($css) . '"></head><body><main class="wrap">'
        . '<div class="card"><h1>Something went wrong</h1>'
        . '<p>The error has been logged. Reference <code>' . $esc($ref) . '</code>.</p>';
    if ($local) {
        echo '<pre class="errdetail">' . $esc(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>';
    }
    echo '</div></main></body></html>';
    exit;
});

// --- lib ------------------------------------------------------------------------------------------
require PTO_APP . '/lib/helpers.php';
require PTO_APP . '/lib/db.php';
require PTO_APP . '/lib/policy.php';
require PTO_APP . '/lib/balance.php';
require PTO_APP . '/lib/audit.php';
require PTO_APP . '/lib/csrf.php';
require PTO_APP . '/lib/auth.php';
require PTO_APP . '/lib/groups.php';
require PTO_APP . '/lib/layout.php';
require PTO_APP . '/lib/balance_db.php';
require PTO_APP . '/lib/time_off.php';
require PTO_APP . '/lib/employees.php';
require PTO_APP . '/lib/viewer_auth.php';
require PTO_APP . '/lib/google.php'; require PTO_APP . '/lib/sync.php'; require PTO_APP . '/lib/alerts.php';   // M2 (SPEC 14)

// --- web only: headers, session, CSRF ----------------------------------------------------------------
if (!PTO_CLI) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; frame-src https://calendar.google.com");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    if (!config_loaded()) {
        // Not installed yet: only setup.php may run (it needs config.php too, so say so plainly).
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Not configured</title>'
            . '<p>pto_data/config.php is missing. Copy pto_app/config.example.php there, fill it in, then open setup.php?token=...</p>';
        exit;
    }

    if (!is_dir(PTO_DATA . '/sessions')) {
        @mkdir(PTO_DATA . '/sessions', 0700, true);
    }
    ini_set('session.save_path', PTO_DATA . '/sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (12 * 3600));
    session_name('lsp_pto');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // 12-hour idle timeout.
    $last = $_SESSION['last_seen'] ?? null;
    if (is_int($last) && time() - $last > 12 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();

    csrf_verify();
}

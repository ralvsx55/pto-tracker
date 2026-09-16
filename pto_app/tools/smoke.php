<?php
declare(strict_types=1);

/**
 * Smoke test against a running local server:
 *   php pto_app/tools/smoke.php http://127.0.0.1:8020/ [page ...]
 * Logs in as the local admin (chris@lightsaberpromotions.com / changeme-now) with a cookie jar, GETs every page,
 * reports the HTTP status and any PHP error text in the body, then tails pto_data/logs/error.log.
 * Pages that do not exist yet simply report 404. Exit code 1 when a page returns 5xx or leaks an error.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../lib/bootstrap.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8020/', '/') . '/';
$pages = array_slice($argv, 2);
if ($pages === []) {
    $pages = [
        'dashboard.php', 'requests.php', 'request.php', 'employees.php', 'employee.php?id=1', 'events.php', 'history.php',
        'admin.php', 'view.php?g=us', 'view.php?g=manila',
        'preview.php?employee_id=1&kind=PTO&start=2026-10-05&end=2026-10-07',
    ];
}
$jar = tempnam(sys_get_temp_dir(), 'ptojar');
$errorPattern = '/\b(Warning:|Notice:|Fatal error|Fatal|Deprecated:|Uncaught|Parse error|Something went wrong)\b/';

function http(string $url, ?array $post, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $hsize);
    $body = substr((string) $raw, $hsize);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : null;
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'location' => $loc, 'error' => null];
}

$fail = false;
echo "Base: $base\n";

// 1. login
$r = http($base . 'login.php', null, $jar);
if ($r['status'] !== 200 || !preg_match('/name="csrf" value="([0-9a-f]+)"/', $r['body'], $m)) {
    echo "LOGIN PAGE: status {$r['status']} " . ($r['error'] ?? '') . " - cannot find the CSRF token\n";
    exit(1);
}
$r = http($base . 'login.php', ['csrf' => $m[1], 'email' => 'chris@lightsaberpromotions.com', 'password' => 'changeme-now'], $jar);
$loggedIn = $r['status'] === 303 && $r['location'] !== null && str_contains($r['location'], 'dashboard.php');
printf("%-8s %-70s %s\n", 'login', 'POST login.php', $loggedIn ? "OK -> {$r['location']}" : "FAILED (status {$r['status']})");
if (!$loggedIn) {
    $fail = true;
}

// 2. pages
foreach ($pages as $p) {
    $r = http($base . ltrim($p, '/'), null, $jar);
    $notes = [];
    if ($r['error'] !== null) {
        $notes[] = 'curl: ' . $r['error'];
        $fail = true;
    }
    if ($r['status'] >= 500) {
        $fail = true;
    }
    if ($r['location'] !== null) {
        $notes[] = '-> ' . $r['location'];
    }
    if (preg_match($errorPattern, $r['body'], $em)) {
        $notes[] = 'PHP ERROR TEXT: ' . $em[1];
        $fail = true;
    }
    if (str_starts_with($p, 'preview.php')) {
        $json = json_decode($r['body'], true);
        $notes[] = is_array($json) ? ('json ok: ' . ($json['summary_line'] ?? $json['error'] ?? '?')) : 'NOT JSON';
        if (!is_array($json)) {
            $fail = true;
        }
    } elseif ($r['status'] === 200 && !str_contains($r['body'], '</html>')) {
        $notes[] = 'body has no </html>';
    }
    if ($r['status'] === 404) {
        $notes[] = 'not built yet';
    }
    printf("%-8s %-70s %s\n", (string) $r['status'], $p, implode('; ', $notes));
}

// 3. error log tail
$log = PTO_DATA . '/logs/error.log';
echo "\n--- tail " . $log . " ---\n";
if (is_file($log)) {
    $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
    foreach (array_slice($lines, -15) as $line) {
        echo $line, "\n";
    }
} else {
    echo "(no error log)\n";
}
@unlink($jar);
echo $fail ? "\nSMOKE: FAILED\n" : "\nSMOKE: OK\n";
exit($fail ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * Nightly cron at 03:05 (SPEC 14.4):
 *   5 3 * * * /usr/local/bin/php /home/CPUSER/pto_app/cron/nightly.php >> /home/CPUSER/pto_data/logs/cron.log 2>&1
 * 1. birthday_rows_topup for each group      2. reconcile every active, enabled calendar
 * 3. balance self-test (alert on failure)     4. PTO_DATA/backups/pto-YYYY-MM-DD.json
 * 5. trim sync.log / cron.log entries older than 90 days; keep 60 daily backups plus the first of each month.
 * Prints PHP_VERSION and a one-line summary, exits non-zero on failure, refuses to run twice concurrently.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (!PTO_CLI) {
    exit("CLI only\n");
}
echo 'PHP ' . PHP_VERSION . ' cron/nightly ' . now_str() . "\n";

if ((int) col('SELECT GET_LOCK(?, 0)', ['pto_cron_nightly']) !== 1) {
    echo "SKIPPED: another cron/nightly.php is still running\n";
    exit(0);
}

$t0 = microtime(true);
$problems = [];
$notes = [];

// 1. birthday rows for this year and next
$added = 0;
foreach (groups_all() as $g) {
    try {
        $added += birthday_rows_topup((int) $g['id']);
    } catch (Throwable $e) {
        $problems[] = 'topup ' . $g['group_key'] . ': ' . $e->getMessage();
    }
}
$notes[] = "birthday rows added=$added";

// 2. reconcile every active, enabled calendar
$calResults = [];
foreach (rows('SELECT cal_key FROM calendars WHERE is_active = 1 AND sync_enabled = 1 ORDER BY group_id, sort_order') as $c) {
    $key = (string) $c['cal_key'];
    try {
        $r = sync_calendar($key, 'reconcile', ['trigger' => 'nightly']);
        $locked = $r['aborted'] === 'locked';   // another sync holds this calendar: skipped, not a failure
        $calResults[] = $key . '=' . ($locked ? 'locked' : ($r['aborted'] !== null ? 'aborted' : ($r['ok'] ? ($r['dry_run'] ? 'dry-run' : 'ok') : 'failed')));
        if (!$r['ok'] && !$locked) {
            $problems[] = "$key: " . $r['message'];
        }
    } catch (Throwable $e) {
        $calResults[] = $key . '=error';
        $problems[] = "$key: " . $e->getMessage();
    }
}
$notes[] = 'reconcile [' . implode(' ', $calResults) . ']';

// 3. balance self-test (the engine acceptance suite) in a child process; alert on failure.
//    exec() may be disabled on a shared host (the bootstrap turns that warning into an exception): reported, not fatal.
$testFile = PTO_APP . '/tests/balance_test.php';
if (is_file($testFile)) {
    try {
        $out = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile) . ' 2>&1', $out, $code);
        $last = $out === [] ? '(no output)' : (string) end($out);
        $notes[] = 'self-test ' . ($code === 0 ? 'ok' : "FAILED ($last)");
        if ($code !== 0) {
            $problems[] = 'balance self-test failed: ' . $last;
            alert('nightly self-test failed', "tests/balance_test.php exited with code $code.\n\n" . implode("\n", array_slice($out, -30)));
        }
    } catch (Throwable $e) {
        $problems[] = 'balance self-test could not run: ' . $e->getMessage();
        alert('nightly self-test could not run', $e->getMessage());
    }
} else {
    $notes[] = 'self-test skipped (tests/balance_test.php missing)';
}

// 4. JSON backup (employees, time_off, adjustments, events, groups, engine_version)
$backupDir = PTO_DATA . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0700, true);
}
try {
    $backup = [
        'exported_at'    => now_str(),
        'app_version'    => APP_VERSION,
        'engine_version' => ENGINE_VERSION,
        'schema_version' => setting('schema_version'),
        'groups'         => rows('SELECT * FROM groups ORDER BY id'),
        'employees'      => rows('SELECT * FROM employees ORDER BY id'),
        'time_off'       => rows('SELECT * FROM time_off ORDER BY id'),
        'adjustments'    => rows('SELECT * FROM adjustments ORDER BY id'),
        'events'         => rows('SELECT * FROM events ORDER BY id'),
    ];
    foreach ($backup['groups'] as &$g) {
        unset($g['viewer_password_hash']);          // never export password material
    }
    unset($g);
    $file = $backupDir . '/pto-' . date('Y-m-d') . '.json';
    $json = json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException("cannot write $file");
    }
    $notes[] = 'backup ' . basename($file) . ' (' . number_format(strlen($json) / 1024, 1) . ' KB)';
} catch (Throwable $e) {
    $problems[] = 'backup: ' . $e->getMessage();
    alert('nightly backup failed', $e->getMessage());
}

// 5a. trim log lines older than 90 days (lines carry a leading "Y-m-d H:i:s"; undated lines follow the last dated one)
$cutoff = date('Y-m-d H:i:s', time() - 90 * 86400);
foreach ([PTO_DATA . '/logs/sync.log', PTO_DATA . '/logs/cron.log'] as $log) {
    if (!is_file($log)) {
        continue;
    }
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    $keep = [];
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
            $current = $m[1];
        }
        if ($current === null || $current >= $cutoff) {
            $keep[] = $line;
        }
    }
    if (count($keep) !== count($lines)) {
        file_put_contents($log, $keep === [] ? '' : implode("\n", $keep) . "\n", LOCK_EX);
        $notes[] = basename($log) . ' trimmed ' . (count($lines) - count($keep)) . ' lines';
    }
}

// 5b. keep 60 daily backups plus the first of each month
$files = glob($backupDir . '/pto-????-??-??.json') ?: [];
rsort($files);
$daily = 0;
$removed = 0;
foreach ($files as $f) {
    $isFirst = preg_match('/pto-\d{4}-\d{2}-01\.json$/', $f) === 1;
    if ($isFirst) {
        continue;
    }
    $daily++;
    if ($daily > 60) {
        if (@unlink($f)) {
            $removed++;
        }
    }
}
if ($removed > 0) {
    $notes[] = "old backups removed=$removed";
}

q('SELECT RELEASE_LOCK(?)', ['pto_cron_nightly']);

$summary = sprintf('%s: %s (%.1fs)', $problems === [] ? 'OK' : 'FAILED', implode('; ', $notes), microtime(true) - $t0);
if ($problems !== []) {
    $summary .= ' PROBLEMS: ' . implode(' | ', $problems);
}
echo $summary . "\n";
exit($problems === [] ? 0 : 1);

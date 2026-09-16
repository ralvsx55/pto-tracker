<?php
declare(strict_types=1);

/**
 * Cron every 15 minutes (SPEC 14.4): incremental sync of every active, enabled, dirty calendar.
 *   0,15,30,45 * * * * /usr/local/bin/php /home/CPUSER/pto_app/cron/sync.php >> /home/CPUSER/pto_data/logs/cron.log 2>&1
 * Prints PHP_VERSION and a one-line summary, exits non-zero on failure, refuses to run twice concurrently.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (!PTO_CLI) {
    exit("CLI only\n");
}
echo 'PHP ' . PHP_VERSION . ' cron/sync ' . now_str() . "\n";

if ((int) col('SELECT GET_LOCK(?, 0)', ['pto_cron_sync']) !== 1) {
    echo "SKIPPED: another cron/sync.php is still running\n";
    exit(0);
}

$t0 = microtime(true);
$results = [];
$failed = 0;
$cals = rows('SELECT cal_key FROM calendars WHERE is_active = 1 AND sync_enabled = 1 AND dirty = 1 ORDER BY group_id, sort_order');
foreach ($cals as $c) {
    $key = (string) $c['cal_key'];
    try {
        $r = sync_calendar($key, 'incremental', ['trigger' => 'cron']);
        // 'locked' = the inline hook is syncing this calendar right now: skipped, not failed (the next run retries).
        $locked = $r['aborted'] === 'locked';
        $results[] = $key . '=' . ($locked ? 'locked' : ($r['aborted'] !== null ? 'aborted' : ($r['ok'] ? ($r['dry_run'] ? 'dry-run' : 'ok') : 'failed')));
        if (!$r['ok'] && !$locked) {
            $failed++;
        }
    } catch (Throwable $e) {
        $failed++;
        $results[] = $key . '=error';
        error_log('cron/sync ' . $key . ': ' . $e->getMessage());
    }
}
q('SELECT RELEASE_LOCK(?)', ['pto_cron_sync']);

$summary = sprintf('%s: %d dirty calendar(s) %s (%.1fs)%s', $failed === 0 ? 'OK' : 'FAILED', count($cals),
    $results === [] ? '' : '[' . implode(' ', $results) . ']', microtime(true) - $t0, $failed > 0 ? " $failed failed" : '');
echo $summary . "\n";
exit($failed === 0 ? 0 : 1);

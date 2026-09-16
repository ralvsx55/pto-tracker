<?php
declare(strict_types=1);

/**
 * Command-line front end for the sync engine (SPEC 14). CLI only:
 *   C:/xampp/php/php.exe pto_app/tools/sync_cli.php <command> [args]
 *
 *   status                              Google setup, per-group strip, one line per calendar
 *   preview <cal_key>                   reconcile plan (dry run, no state change): counts + first 50 of each list
 *   sync <cal_key> [--force]            reconcile now (--force: delete guard off, explicit write)
 *   adopt <cal_key> [--commit]          match unmanaged remote events to rows (dry run unless --commit)
 *   reset [group_key]                   forget every Google id / fingerprint (all groups or one)
 *   wipe-birthdays <cal_key> --commit   delete every event on a birthday calendar and regenerate
 *   test-connection <cal_key>           insert + delete a probe event (read-only listing outside production)
 *   topup                               birthday_rows_topup for every group
 *   log [n]                             last n lines of sync.log (default 30)
 *
 * Writes to Google happen only where google_writes_allowed() says so (never locally).
 */

require_once __DIR__ . '/../lib/bootstrap.php';

if (!PTO_CLI) {
    exit("CLI only\n");
}

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'help';
$flags = array_values(array_filter($args, static fn(string $a): bool => str_starts_with($a, '--')));
$pos = array_values(array_filter(array_slice($args, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$has = static fn(string $flag): bool => in_array($flag, $flags, true);

function cli_cal_arg(array $pos): string
{
    $key = $pos[0] ?? '';
    if ($key === '') {
        fwrite(STDERR, "cal_key required (one of: " . implode(', ', array_column(rows('SELECT cal_key FROM calendars ORDER BY group_id, sort_order'), 'cal_key')) . ")\n");
        exit(2);
    }
    sync_calendar_row($key);
    return $key;
}

function cli_print_list(string $name, array $items, int $max = 50): void
{
    printf("  %-17s %d\n", $name . ':', count($items));
    foreach (array_slice($items, 0, $max) as $it) {
        printf("      %-28s %s..%s  %-40s %s%s\n", (string) ($it['key'] ?? '-'), (string) ($it['start'] ?? ''), (string) ($it['end'] ?? ''),
            mb_substr((string) ($it['title'] ?? ''), 0, 40), (string) ($it['reason'] ?? ''), !empty($it['google_event_id']) ? '  [' . $it['google_event_id'] . ']' : '');
    }
    if (count($items) > $max) {
        printf("      ... %d more\n", count($items) - $max);
    }
}

function cli_print_result(array $r): void
{
    printf("%s %s: %s%s (%.1fs)\n", $r['cal_key'], $r['mode'], $r['ok'] ? 'OK' : 'NOT OK', $r['dry_run'] ? ' [dry run]' : '', $r['seconds']);
    echo '  message: ' . $r['message'] . "\n";
    if ($r['aborted'] !== null) {
        echo '  aborted: ' . $r['aborted'] . "\n";
    }
    foreach ($r['plan'] as $name => $items) {
        cli_print_list($name, $items);
    }
    if ($r['executed'] !== []) {
        $ex = $r['executed'];
        printf("  executed: inserts=%d patches=%d deletes=%d departed=%d touched=%d failed=%d skipped=%d\n",
            $ex['inserts'], $ex['patches'], $ex['deletes'], $ex['departed_cleanup'], $ex['touched'], $ex['failed'], $ex['skipped']);
        foreach ($ex['errors'] as $e) {
            echo "    ! $e\n";
        }
    }
}

$exit = 0;
switch ($cmd) {
    case 'status':
        $st = google_status();
        echo "Environment: {$st['environment']}   sync_mode: {$st['sync_mode']}   writes allowed: " . ($st['writes_allowed'] ? 'yes' : 'no') . "\n";
        echo "Key file: " . ($st['key_file'] ? 'present' : 'missing') . " ({$st['key_path']})\n";
        if ($st['client_email'] !== null) {
            echo "Share calendars with: {$st['client_email']}   token: " . ($st['token_ok'] ? 'OK' : 'FAILED') . "\n";
        }
        echo $st['message'] . "\n";
        foreach (groups_all() as $g) {
            $s = sync_status_for_group((int) $g['id']);
            printf("\n%s [%s]: %s\n", $g['name'], $s['state'], $s['summary']);
            foreach ($s['calendars'] as $c) {
                printf("  %-13s %-10s %-9s dirty=%d%s last=%s %s %s\n", $c['cal_key'], $c['kind'], $c['state'], $c['dirty'],
                    $c['minutes_dirty'] !== null ? ' (' . $c['minutes_dirty'] . ' min)' : '',
                    $c['last_sync_at'] ?? 'never', $c['last_sync_ok'] === null ? '' : ($c['last_sync_ok'] ? 'ok' : 'FAILED'),
                    (string) ($c['last_sync_message'] ?? ''));
            }
        }
        break;

    case 'preview':
        $key = cli_cal_arg($pos);
        $r = sync_calendar($key, 'reconcile', ['dry_run' => true, 'trigger' => 'cli-preview']);
        cli_print_result($r);
        $exit = $r['ok'] ? 0 : 1;
        break;

    case 'sync':
        $key = cli_cal_arg($pos);
        $r = sync_calendar($key, 'reconcile', ['force' => $has('--force'), 'trigger' => 'cli']);
        cli_print_result($r);
        $exit = $r['ok'] ? 0 : 1;
        break;

    case 'adopt':
        $key = cli_cal_arg($pos);
        $r = sync_adopt($key, $has('--commit'));
        echo "$key adopt" . ($r['committed'] ? ' (committed)' : ' (dry run)') . "\n";
        if ($r['refused'] !== null) {
            echo '  REFUSED: ' . $r['refused'] . "\n";
            $exit = 1;
        }
        cli_print_list('matched', $r['matched']);
        cli_print_list('unmatched_remote', $r['unmatched_remote']);
        cli_print_list('unmatched_rows', $r['unmatched_rows']);
        break;

    case 'reset':
        $groupId = null;
        if (($pos[0] ?? '') !== '') {
            $g = group_by_key($pos[0]);
            if ($g === null) {
                fwrite(STDERR, "Unknown group '{$pos[0]}'\n");
                exit(2);
            }
            $groupId = (int) $g['id'];
        }
        sync_reset_state($groupId);
        echo 'Sync state reset' . ($groupId === null ? ' for all groups' : " for group {$pos[0]}") . ".\n";
        break;

    case 'wipe-birthdays':
        $key = cli_cal_arg($pos);
        $r = sync_wipe_regenerate_birthdays($key, $has('--commit'));
        echo "$key wipe-birthdays" . ($r['committed'] ? ' (committed)' : ' (dry run; add --commit)') . ': ' . $r['message'] . "\n";
        if ($r['refused'] !== null) {
            $exit = 1;
        }
        break;

    case 'test-connection':
        $key = cli_cal_arg($pos);
        $r = sync_test_connection($key);
        echo "$key: " . ($r['ok'] ? 'OK' : 'FAILED') . ' - ' . $r['message'] . "\n";
        $exit = $r['ok'] ? 0 : 1;
        break;

    case 'topup':
        foreach (groups_all() as $g) {
            printf("%s: %d birthday row(s) added\n", $g['name'], birthday_rows_topup((int) $g['id']));
        }
        break;

    case 'log':
        foreach (sync_log_tail((int) ($pos[0] ?? 30)) as $line) {
            echo $line . "\n";
        }
        break;

    default:
        echo "Usage: sync_cli.php status | preview <cal_key> | sync <cal_key> [--force] | adopt <cal_key> [--commit] | reset [group_key]\n"
            . "       | wipe-birthdays <cal_key> --commit | test-connection <cal_key> | topup | log [n]\n";
        $exit = $cmd === 'help' ? 0 : 2;
}
exit($exit);

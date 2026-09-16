<?php
declare(strict_types=1);

/**
 * Alerts (SPEC 14.3): alert() mails config alert_email from alert_from with PHP mail(), at most once per day
 * (settings.last_alert_sent_at). Raised after 3 consecutive failed sync runs of any calendar
 * (settings.consecutive_sync_failures) and by the nightly self-test failure. alert_test() for Admin.
 * Every alert is also appended to PTO_DATA/logs/alerts.log; outside production nothing is mailed.
 */

const ALERT_MIN_INTERVAL = 24 * 3600;
const ALERT_FAILURE_THRESHOLD = 3;

function alert_log_file(): string
{
    $f = $GLOBALS['alert_log_file'] ?? null;
    return is_string($f) && $f !== '' ? $f : PTO_DATA . '/logs/alerts.log';
}

function alert_log(string $line): void
{
    $file = alert_log_file();
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0700, true);
    }
    @file_put_contents($file, now_str() . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Send one alert now, ignoring the once-per-day limit. ['sent' => bool, 'message' => string].
 * $GLOBALS['alert_mailer'] (callable(to, subject, body, headers): bool) replaces mail() in tests.
 */
function alert_send(string $subject, string $body): array
{
    $to = (string) config('alert_email', '');
    $from = (string) config('alert_from', '');
    $subject = 'PTO Tracker: ' . $subject;
    if ($to === '') {
        alert_log("NOT SENT (no alert_email configured): $subject");
        return ['sent' => false, 'message' => 'alert_email is not configured.'];
    }
    $headers = 'From: ' . ($from !== '' ? $from : $to) . "\r\nContent-Type: text/plain; charset=UTF-8";
    $mailer = $GLOBALS['alert_mailer'] ?? null;
    if (is_callable($mailer)) {
        $ok = (bool) $mailer($to, $subject, $body, $headers);
    } elseif (config('environment', 'local') !== 'production') {
        alert_log("LOGGED ONLY (local environment) to $to: $subject");
        return ['sent' => false, 'message' => "Local environment: alert logged to alerts.log, not mailed ($subject)."];
    } else {
        try {
            $ok = @mail($to, $subject, $body, $headers);
        } catch (Throwable $e) {
            $ok = false;
            alert_log('mail() failed: ' . $e->getMessage());
        }
    }
    alert_log(($ok ? 'SENT' : 'FAILED') . " to $to: $subject");
    return ['sent' => $ok, 'message' => $ok ? "Alert sent to $to." : "mail() failed sending to $to (see alerts.log)."];
}

/** Once-per-day alert (SPEC 14.3). Returns true when a mail went out. */
function alert(string $subject, string $body): bool
{
    $last = setting('last_alert_sent_at');
    if ($last !== null && $last !== '') {
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $last);
        if ($d !== false && time() - $d->getTimestamp() < ALERT_MIN_INTERVAL) {
            alert_log("SUPPRESSED (last alert $last): $subject");
            return false;
        }
    }
    $r = alert_send($subject, $body);
    if ($r['sent']) {
        setting_set('last_alert_sent_at', now_str());
    }
    return $r['sent'];
}

/** Admin > Send test alert: bypasses the daily limit; returns the outcome text. */
function alert_test(): string
{
    $r = alert_send('test alert', "This is a test alert from the PTO Tracker at " . (string) config('base_url', '') . ".\nSent " . now_str() . " from " . (PTO_CLI ? 'the command line' : 'the Admin screen') . '.');
    return $r['message'];
}

/**
 * Called by sync_calendar() after every recorded run: counts consecutive failures across all calendars
 * (settings.consecutive_sync_failures) and raises the alert at 3.
 */
function alert_note_sync_result(string $calKey, bool $ok, string $message): void
{
    if ($ok) {
        if ((int) (setting('consecutive_sync_failures') ?? '0') !== 0) {
            setting_set('consecutive_sync_failures', '0');
        }
        return;
    }
    $n = (int) (setting('consecutive_sync_failures') ?? '0') + 1;
    setting_set('consecutive_sync_failures', (string) $n);
    if ($n >= ALERT_FAILURE_THRESHOLD) {
        alert('calendar sync failing', "Calendar sync has failed $n times in a row.\nLast failure: $calKey: $message\n\nSee Admin > Calendars for the plan and the last 100 lines of sync.log.");
    }
}

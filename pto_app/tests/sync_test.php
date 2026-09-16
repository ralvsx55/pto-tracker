<?php
declare(strict_types=1);

/**
 * Sync engine tests (SPEC 14.6). Hand-rolled runner, no PHPUnit.
 * Run: C:/xampp/php/php.exe pto_app/tests/sync_test.php     (exit code 1 on any failure)
 *
 * Creates the scratch database pto_local_synctest from migrations/001_init.sql, inserts its own fixtures
 * (two groups, a few employees incl. one departed and one Feb-29 birthday, requests, events), points db() at it,
 * installs an in-memory fake Calendar API as $GLOBALS['google_transport'] and a generated RSA key written to a
 * throwaway key file ($GLOBALS['google_key_path'], so the developer's real pto_data key is never read), sets
 * environment 'production' + sync_mode 'live' for the write paths, and drops the database at the end.
 */

require_once __DIR__ . '/../lib/bootstrap.php';

if (!PTO_CLI) {
    exit("CLI only\n");
}
if (!config_loaded()) {
    exit("pto_data/config.php is missing (run tools/dev_reset.php once)\n");
}
if (config('environment') === 'production') {
    exit("Refusing to run the sync tests against a production configuration.\n");
}

const TEST_DB = 'pto_local_synctest';

// --- scratch database, redirected logs, fake key --------------------------------------------------------
$c = config('db');
$server = new PDO(sprintf('mysql:host=%s;charset=utf8mb4', $c['host']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$server->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$GLOBALS['config']['db']['name'] = TEST_DB;          // before the first db() call
$GLOBALS['config']['environment'] = 'production';    // exercise the write paths (the guard test flips it back)
$GLOBALS['config']['alert_email'] = 'alerts@example.test';
$tmp = sys_get_temp_dir() . '/pto_synctest_' . getmypid();
@mkdir($tmp, 0700, true);
$GLOBALS['sync_log_file'] = $tmp . '/sync.log';
$GLOBALS['alert_log_file'] = $tmp . '/alerts.log';
$SLEEPS = [];
$GLOBALS['google_sleep'] = static function (int $s) use (&$SLEEPS): void {
    $SLEEPS[] = $s;
};
$MAILS = [];
$GLOBALS['alert_mailer'] = static function (string $to, string $subject, string $body, string $headers) use (&$MAILS): bool {
    $MAILS[] = $subject;
    return true;
};
// A throwaway RSA key for the JWT. XAMPP's PHP needs an openssl.cnf to generate keys: try the usual places.
$keyOpts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$pk = false;
$cnfs = array_filter([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/openssl/openssl.cnf', dirname(PHP_BINARY, 2) . '/apache/conf/openssl.cnf', dirname(PHP_BINARY, 2) . '/apache/bin/openssl.cnf'], 'is_string');
foreach ([null, ...$cnfs] as $cnf) {
    if ($cnf !== null && !is_file($cnf)) {
        continue;
    }
    $pk = openssl_pkey_new($cnf === null ? $keyOpts : $keyOpts + ['config' => $cnf]);
    if ($pk !== false) {
        $keyOpts = $cnf === null ? $keyOpts : $keyOpts + ['config' => $cnf];
        break;
    }
}
if ($pk === false) {
    exit("Cannot generate an RSA test key (no usable openssl.cnf; set OPENSSL_CONF): " . (string) openssl_error_string() . "\n");
}
$pem = '';
openssl_pkey_export($pk, $pem, null, $keyOpts);
// The throwaway key goes into a temp file and google_key_path() is pointed at it: the real pto_data key is never touched.
$keyFile = $tmp . '/google-service-account.json';
file_put_contents($keyFile, json_encode(['type' => 'service_account', 'client_email' => 'pto-sync@test-project.iam.gserviceaccount.com', 'private_key' => $pem], JSON_THROW_ON_ERROR));
$missingKeyFile = $tmp . '/no-such-key.json';
$GLOBALS['google_key_path'] = $keyFile;

apply_sql_file(PTO_APP . '/migrations/001_init.sql');
setting_set('sync_mode', 'live');

// --- fake Calendar API ---------------------------------------------------------------------------------
/** In-memory Calendar API keyed by calendar id: events.list (privateExtendedProperty + paging), insert, patch, delete, get. */
class FakeGoogle
{
    public array $cals = [];
    public array $calls = [];
    /** @var null|callable(string $method, string $url): ?array  returns [status, body] to inject a failure */
    public $fail = null;
    public int $seq = 0;
    public int $pageSize = 3;
    public int $tokens = 0;

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        if ($url === GOOGLE_TOKEN_URL) {
            parse_str((string) $body, $form);
            if (($form['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || substr_count((string) ($form['assertion'] ?? ''), '.') !== 2) {
                return [400, ['error' => 'invalid_grant', 'error_description' => 'bad assertion']];
            }
            $this->tokens++;
            return [200, ['access_token' => 'tok' . $this->tokens, 'expires_in' => 3600, 'token_type' => 'Bearer']];
        }
        if ($this->fail !== null) {
            $r = ($this->fail)($method, $url);
            if ($r !== null) {
                return $r;
            }
        }
        $auth = '';
        foreach ($headers as $h) {
            if (str_starts_with($h, 'Authorization: Bearer ')) {
                $auth = substr($h, 22);
            }
        }
        if ($auth === '') {
            return [401, ['error' => ['code' => 401, 'message' => 'no token', 'errors' => [['reason' => 'authError']]]]];
        }
        if (!preg_match('#/calendar/v3/calendars/([^/?]+)/events(?:/([^/?]+))?#', $url, $m)) {
            return [404, ['error' => ['code' => 404, 'message' => 'bad path']]];
        }
        $calId = rawurldecode($m[1]);
        $evId = isset($m[2]) ? rawurldecode($m[2]) : null;
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->calls[] = [$method, $calId, $evId];
        $this->cals[$calId] ??= [];
        $json = $body === null ? [] : (json_decode($body, true) ?? []);
        $notFound = [404, ['error' => ['code' => 404, 'message' => 'Not Found', 'errors' => [['reason' => 'notFound']]]]];

        if ($method === 'GET' && $evId === null) {
            $items = array_values($this->cals[$calId]);
            if (isset($query['privateExtendedProperty'])) {
                [$k, $v] = explode('=', (string) $query['privateExtendedProperty'], 2) + [1 => ''];
                $items = array_values(array_filter($items, static fn(array $e): bool => ($e['extendedProperties']['private'][$k] ?? null) === $v));
            }
            $offset = (int) ($query['pageToken'] ?? 0);
            $page = array_slice($items, $offset, $this->pageSize);
            $out = ['items' => $page];
            if ($offset + $this->pageSize < count($items)) {
                $out['nextPageToken'] = (string) ($offset + $this->pageSize);
            }
            return [200, $out];
        }
        if ($method === 'GET') {
            return isset($this->cals[$calId][$evId]) ? [200, $this->cals[$calId][$evId]] : $notFound;
        }
        if ($method === 'POST') {
            $id = 'ev' . (++$this->seq);
            $ev = $json + ['id' => $id, 'status' => 'confirmed'];
            $this->cals[$calId][$id] = $ev;
            return [200, $ev];
        }
        if ($method === 'PATCH') {
            if (!isset($this->cals[$calId][$evId])) {
                return $notFound;
            }
            $this->cals[$calId][$evId] = array_replace_recursive($this->cals[$calId][$evId], $json);
            return [200, $this->cals[$calId][$evId]];
        }
        if ($method === 'DELETE') {
            if (!isset($this->cals[$calId][$evId])) {
                return $notFound;
            }
            unset($this->cals[$calId][$evId]);
            return [204, []];
        }
        return [405, ['error' => ['code' => 405, 'message' => 'nope']]];
    }

    public function count(string $calId): int
    {
        return count($this->cals[$calId] ?? []);
    }

    public function byLsp(string $calId, string $key): ?array
    {
        foreach ($this->cals[$calId] ?? [] as $e) {
            if (($e['extendedProperties']['private']['lsp'] ?? null) === $key) {
                return $e;
            }
        }
        return null;
    }

    public function lspKeys(string $calId): array
    {
        $out = [];
        foreach ($this->cals[$calId] ?? [] as $e) {
            $out[] = $e['extendedProperties']['private']['lsp'] ?? '(none)';
        }
        return $out;
    }

    /** Add a remote event by hand (an old hand-made entry, an orphan, a lost insert). Returns its id. */
    public function add(string $calId, string $title, string $start, string $endExcl, ?string $lsp = null): string
    {
        $id = 'hand' . (++$this->seq);
        $ev = ['id' => $id, 'status' => 'confirmed', 'summary' => $title, 'start' => ['date' => $start], 'end' => ['date' => $endExcl]];
        if ($lsp !== null) {
            $ev['extendedProperties'] = ['private' => ['lsp' => $lsp]];
        }
        $this->cals[$calId][$id] = $ev;
        return $id;
    }

    public function posts(): int
    {
        return count(array_filter($this->calls, static fn(array $c): bool => $c[0] === 'POST'));
    }
}
$G = new FakeGoogle();
$GLOBALS['google_transport'] = $G;

// --- runner ----------------------------------------------------------------------------------------------
$pass = 0;
$fail = 0;
$failures = [];
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    $failures[] = $label . ($detail !== '' ? ' :: ' . $detail : '');
    echo "FAIL: $label" . ($detail !== '' ? " :: $detail" : '') . "\n";
}
function eq(mixed $actual, mixed $expected, string $label): void
{
    check($actual === $expected, $label, 'expected ' . json_encode($expected, JSON_UNESCAPED_SLASHES) . ' got ' . json_encode($actual, JSON_UNESCAPED_SLASHES));
}
function calid(string $calKey): string
{
    return (string) col('SELECT google_calendar_id FROM calendars WHERE cal_key = ?', [$calKey]);
}
function gid(string $table, array $where): ?string
{
    $w = implode(' AND ', array_map(static fn(string $k): string => "$k = ?", array_keys($where)));
    return col("SELECT google_event_id FROM $table WHERE $w", array_values($where));
}
function cal(string $calKey): array
{
    return sync_calendar_row($calKey);
}

// --- fixtures --------------------------------------------------------------------------------------------
$ts = ['created_at' => now_str(), 'updated_at' => now_str()];
$E = [];
$E['rebecca'] = insert('employees', ['group_id' => 1, 'name' => 'Walker, Rebecca', 'hire_date' => '2020-03-08', 'birth_month' => 5, 'birth_day' => 14, 'status' => 'active'] + $ts);
$E['sam']     = insert('employees', ['group_id' => 1, 'name' => 'Catuto, Sam', 'hire_date' => '2019-01-02', 'birth_month' => 2, 'birth_day' => 29, 'status' => 'active'] + $ts);
$E['pat']     = insert('employees', ['group_id' => 1, 'name' => 'Gone, Pat', 'hire_date' => '2018-01-01', 'birth_month' => 7, 'birth_day' => 4, 'status' => 'active'] + $ts);
$E['nobday']  = insert('employees', ['group_id' => 1, 'name' => 'Quiet, Lee', 'hire_date' => '2026-02-02', 'status' => 'active'] + $ts);
$E['myra']    = insert('employees', ['group_id' => 2, 'name' => 'Myra', 'hire_date' => '2015-04-22', 'birth_month' => 12, 'birth_day' => 25, 'status' => 'active'] + $ts);
$E['old']     = insert('employees', ['group_id' => 2, 'name' => 'Old Timer', 'hire_date' => '2012-01-01', 'birth_month' => 1, 'birth_day' => 2, 'status' => 'departed', 'departed_on' => '2024-01-01'] + $ts);
$R = [];
$R[1] = insert('time_off', ['employee_id' => $E['rebecca'], 'kind' => 'Vacation', 'start_date' => '2026-10-11', 'end_date' => '2026-10-13'] + $ts);
$R[2] = insert('time_off', ['employee_id' => $E['rebecca'], 'kind' => 'PTO', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02'] + $ts);
$R[3] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2026-12-24', 'end_date' => '2026-12-24'] + $ts);
$R[4] = insert('time_off', ['employee_id' => $E['pat'], 'kind' => 'Vacation', 'start_date' => '2026-08-03', 'end_date' => '2026-08-07'] + $ts);
$R[5] = insert('time_off', ['employee_id' => $E['old'], 'kind' => 'PTO', 'start_date' => '2023-05-01', 'end_date' => '2023-05-02'] + $ts);
$R[6] = insert('time_off', ['employee_id' => $E['myra'], 'kind' => 'PTO', 'start_date' => '2026-06-01', 'end_date' => '2026-06-05'] + $ts);
$EV = [];
$EV[1] = insert('events', ['cal_key' => 'us_holidays', 'title' => 'Christmas Break', 'start_date' => '2026-12-24', 'end_date' => '2026-12-25', 'is_holiday' => 1] + $ts);
$EV[2] = insert('events', ['cal_key' => 'us_sales', 'title' => 'End of Month Sale', 'start_date' => '2026-09-28', 'end_date' => '2026-09-30'] + $ts);
$EV[3] = insert('events', ['cal_key' => 'mn_events', 'title' => 'Company Outing', 'start_date' => '2026-10-15', 'end_date' => '2026-10-15'] + $ts);
$US = group_by_id(1);
$MN = group_by_id(2);
$usPto = calid('us_pto');
$usBd = calid('us_birthdays');
$usHol = calid('us_holidays');
$usSales = calid('us_sales');
$usFactory = calid('us_factory');
$mnPto = calid('mn_pto');
$mnBd = calid('mn_birthdays');
$Y = (int) group_today($US)->format('Y');

// =========================================================================================================
echo "1. google.php client, guard, token, helpers\n";
$st = google_status();
eq($st['key_path'], $keyFile, 'status: key path follows the test override');
eq($st['key_file'], true, 'status: throwaway key file present');
eq($st['client_email'], 'pto-sync@test-project.iam.gserviceaccount.com', 'status: client_email');
eq($st['token_ok'], true, 'status: token obtained');
eq($G->tokens, 1, 'one token exchange');
google_access_token();
eq($G->tokens, 1, 'token served from the settings cache');
check(setting('google_token_cache') === 'tok1' && (int) setting('google_token_expires_at') > time() + 3000, 'token cached in settings for ~55 min');
eq(google_writes_allowed(), true, 'writes allowed: production + live');
setting_set('sync_mode', 'dry_run');
eq(google_writes_allowed(), false, 'writes refused: production + dry_run');
eq(google_writes_allowed(true), true, 'explicit write allowed in production even in dry_run');
setting_set('sync_mode', 'live');
$GLOBALS['config']['environment'] = 'local';
eq(google_writes_allowed(), false, 'writes refused locally');
eq(google_writes_allowed(true), false, 'explicit writes refused locally too');
try {
    google_insert_event($usPto, google_event_body('x', '2026-01-01', '2026-01-02', 'k'), true);
    check(false, 'local insert throws');
} catch (GoogleApiError $e) {
    eq($e->reason, 'writes_disabled', 'local insert refused with writes_disabled');
}
$GLOBALS['config']['environment'] = 'production';
eq(sync_birthday_date(2026, 2, 29), '2026-03-01', 'Feb 29 -> Mar 1 in 2026');
eq(sync_birthday_date(2028, 2, 29), '2028-02-29', 'Feb 29 stays in 2028');
eq(sync_birthday_date(2027, 12, 25), '2027-12-25', 'ordinary birthday date');
eq(sync_end_exclusive('2026-12-31'), '2027-01-01', 'exclusive end crosses the year');
eq(sync_fingerprint('a', 'b', 'c'), sha1('a|b|c'), 'fingerprint = sha1(title|start|end)');
$n = sync_remote_norm(['id' => 'x', 'summary' => 'Timed', 'start' => ['dateTime' => '2026-01-05T09:00:00-05:00'], 'end' => ['dateTime' => '2026-01-05T17:00:00-05:00']]);
eq([$n['start'], $n['end'], $n['all_day']], ['2026-01-05', '2026-01-06', false], 'timed remote event normalised to an all-day span');
eq(sync_parse_key('birthday:3:2026'), ['table' => 'birthday_events', 'employee_id' => 3, 'year' => 2026], 'parse birthday key');
eq(sync_parse_key('bogus'), null, 'parse unknown key');
eq(google_event_body('T', '2026-01-01', '2026-01-02', 'k', 'Birthday')['description'], 'Birthday', 'event body description');

// =========================================================================================================
echo "2. initial insert of all rows (reconcile), idempotent re-run, birthday top-up + Feb 29\n";
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq($r['ok'], true, 'us_pto first reconcile ok');
eq($r['dry_run'], false, 'not a dry run');
eq(count($r['plan']['inserts']), 4, 'plan: 4 inserts (active US employees only; departed and Manila excluded)');
eq($r['executed']['inserts'], 4, 'executed 4 inserts');
eq($G->count($usPto), 4, 'remote has 4 events');
$ev = $G->byLsp($usPto, 'time_off:' . $R[1]);
eq([$ev['summary'] ?? null, $ev['start']['date'] ?? null, $ev['end']['date'] ?? null], ['Walker, Rebecca - Vacation', '2026-10-11', '2026-10-14'], 'event shape: title, start, exclusive end');
check(gid('time_off', ['id' => $R[1]]) === $ev['id'], 'google_event_id stored');
check(col('SELECT synced_fingerprint FROM time_off WHERE id = ?', [$R[1]]) === sync_fingerprint('Walker, Rebecca - Vacation', '2026-10-11', '2026-10-14'), 'fingerprint stored');
eq(gid('time_off', ['id' => $R[5]]), null, 'departed employee row never inserted');
eq(gid('time_off', ['id' => $R[6]]), null, 'other group row never inserted on us_pto');
$calRow = cal('us_pto');
eq([(int) $calRow['last_sync_ok'], (int) $calRow['dirty']], [1, 0], 'calendars.last_sync_ok=1, dirty=0');
check(str_starts_with((string) $calRow['last_sync_message'], 'ok: inserts=4'), 'last_sync_message', (string) $calRow['last_sync_message']);
$tail = sync_log_tail(1);
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} us_pto reconcile ok inserts=4 patches=0 deletes=0 unmanaged=0 orphaned=0 departed=0 \(\d+\.\ds\) \[test\]$/', $tail[0] ?? '') === 1, 'sync.log line format', $tail[0] ?? '');
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['inserts']), count($r['plan']['patches']), count($r['plan']['deletes']), $G->count($usPto)], [0, 0, 0, 4], 'second reconcile is a no-op');
$r = sync_calendar('us_holidays', 'reconcile', ['trigger' => 'test']);
eq($r['executed']['inserts'] ?? null, 1, 'events calendar insert');
eq($G->byLsp($usHol, 'event:' . $EV[1])['summary'] ?? null, 'Christmas Break', 'event title as typed');

// sync_desired() tops up the birthday rows itself, so the "empty desired set" guard only fires when NO active employee
// of the group has a month/day: blank them out, run, then restore.
$bdays = rows('SELECT id, birth_month, birth_day FROM employees WHERE group_id = 1 AND birth_month IS NOT NULL');
q('UPDATE employees SET birth_month = NULL, birth_day = NULL WHERE group_id = 1');
$r = sync_calendar('us_birthdays', 'reconcile', ['trigger' => 'test']);
check($r['aborted'] !== null && str_contains($r['aborted'], 'empty'), 'birthdays: empty desired set aborts when no active employee has a birthday (guard)', (string) $r['aborted']);
eq((int) col('SELECT COUNT(*) FROM birthday_events'), 0, 'the aborted run created no birthday rows');
eq((int) cal('us_birthdays')['last_sync_ok'], 0, 'birthday abort recorded as a failed run');
foreach ($bdays as $b) {
    update_row('employees', ['birth_month' => (int) $b['birth_month'], 'birth_day' => (int) $b['birth_day']], 'id = ?', [$b['id']]);
}
// first Preview with eligible employees: the rows are created on the fly and Y + Y+1 inserts are planned, no abort
$r = sync_calendar('us_birthdays', 'reconcile', ['dry_run' => true, 'trigger' => 'test']);
eq([$r['aborted'], count($r['plan']['inserts']), $r['executed']], [null, 6, []], 'first preview plans Y and Y+1 inserts without waiting for the nightly top-up');
eq((int) col('SELECT COUNT(*) FROM birthday_events'), 6, 'preview created the 6 birthday rows');
eq(birthday_rows_topup(1), 0, 'top-up is idempotent after the preview');
// the explicit nightly top-up, from a clean slate
q('DELETE FROM birthday_events');
update_row('calendars', ['dirty' => 0], 'cal_key = ?', ['us_birthdays']);
setting_set('dirty_since_us_birthdays', null);
eq(birthday_rows_topup(1), 6, 'top-up adds Y and Y+1 for the 3 US employees with a birthday');
eq(birthday_rows_topup(1), 0, 'top-up is idempotent');
eq((int) cal('us_birthdays')['dirty'], 1, 'top-up marks the birthday calendar dirty');
check(setting('dirty_since_us_birthdays') !== null, 'dirty_since recorded in settings');
$r = sync_calendar('us_birthdays', 'reconcile', ['trigger' => 'test']);
eq($r['executed']['inserts'] ?? null, 6, 'birthday inserts for Y and Y+1');
$bd = $G->byLsp($usBd, 'birthday:' . $E['sam'] . ':' . $Y);
$expectSam = sync_birthday_date($Y, 2, 29);
eq([$bd['summary'] ?? null, $bd['start']['date'] ?? null, $bd['end']['date'] ?? null, $bd['description'] ?? null], ['Catuto, Sam Birthday', $expectSam, sync_end_exclusive($expectSam), 'Birthday'], 'Feb 29 birthday event for this year');
eq($G->byLsp($usBd, 'birthday:' . $E['rebecca'] . ':' . ($Y + 1))['start']['date'] ?? null, ($Y + 1) . '-05-14', 'next year birthday');
eq((int) cal('us_birthdays')['dirty'], 0, 'dirty cleared after a complete run');
eq(setting('dirty_since_us_birthdays'), null, 'dirty_since cleared');

// =========================================================================================================
echo "3. patch after a title change (incremental) and remote drift (reconcile)\n";
update_row('employees', ['name' => 'Walker, Becky'], 'id = ?', [$E['rebecca']]);
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['patches'] ?? null, 2, 'two rows patched after the rename');
eq($G->byLsp($usPto, 'time_off:' . $R[1])['summary'] ?? null, 'Walker, Becky - Vacation', 'remote title updated');
eq($G->count($usPto), 4, 'no new events from a patch');
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['patches'] ?? null, 0, 'nothing to patch afterwards');
$id1 = (string) gid('time_off', ['id' => $R[1]]);
$G->cals[$usPto][$id1]['summary'] = 'Edited by hand in Google';
$G->cals[$usPto][$id1]['end']['date'] = '2026-10-20';
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['patches'] ?? null, 0, 'incremental cannot see remote drift');
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq($r['executed']['patches'] ?? null, 1, 'reconcile patches remote drift back');
eq([$G->cals[$usPto][$id1]['summary'], $G->cals[$usPto][$id1]['end']['date']], ['Walker, Becky - Vacation', '2026-10-14'], 'remote restored to the database values');

// =========================================================================================================
echo "4. delete after an app delete (audit row) but never an orphan; incremental audited delete\n";
$before = row('SELECT * FROM time_off WHERE id = ?', [$R[3]]);
$id3 = (string) $before['google_event_id'];
time_off_delete($US, row('SELECT * FROM employees WHERE id = ?', [$E['sam']]), $before);
$orphanId = $G->add($usPto, 'Someone - PTO', '2026-05-05', '2026-05-06', 'time_off:9999');
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq(count($r['plan']['deletes']), 1, 'one delete planned');
eq($r['plan']['deletes'][0]['reason'] ?? null, 'deleted in app', 'delete reason');
eq($r['plan']['deletes'][0]['google_event_id'] ?? null, $id3, 'delete targets the stored id');
eq($r['executed']['deletes'] ?? null, 1, 'delete executed');
eq(isset($G->cals[$usPto][$id3]), false, 'remote event removed after the app delete');
eq(count($r['plan']['orphaned']), 1, 'orphan reported');
eq($r['plan']['orphaned'][0]['reason'] ?? null, 'no matching time_off row and no delete in history', 'orphan reason');
eq(isset($G->cals[$usPto][$orphanId]), true, 'orphan NOT deleted');
eq($r['ok'], true, 'run still ok with an orphan');
// incremental picks up an app delete through the audit cursor
$before = row('SELECT * FROM time_off WHERE id = ?', [$R[2]]);
$id2 = (string) $before['google_event_id'];
time_off_delete($US, row('SELECT * FROM employees WHERE id = ?', [$E['rebecca']]), $before);
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['deletes'] ?? null, 1, 'incremental deletes the audited row');
eq(isset($G->cals[$usPto][$id2]), false, 'remote event gone after incremental');
check((int) setting('sync_audit_cursor_us_pto') > 0, 'audit cursor advanced');
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['deletes'] ?? null, 0, 'audited delete not repeated');
unset($G->cals[$usPto][$orphanId]);

// =========================================================================================================
echo "5. departed cleanup (step 1), sync_depart_employee, un-depart re-insert without duplicates\n";
// step 1 path: status flipped directly (no depart call); the next run of each calendar cleans up
update_row('employees', ['status' => 'departed', 'departed_on' => '2026-09-01'], 'id = ?', [$E['rebecca']]);
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq(count($r['plan']['departed_cleanup']), 1, 'departed cleanup planned for the remaining request');
eq($r['executed']['departed_cleanup'] ?? null, 1, 'departed cleanup executed');
eq(isset($G->cals[$usPto][$id1]), false, 'departed employee event removed');
eq(gid('time_off', ['id' => $R[1]]), null, 'id cleared, row kept');
eq((int) col('SELECT COUNT(*) FROM time_off WHERE id = ?', [$R[1]]), 1, 'row never removed by the sync');
$r = sync_calendar('us_birthdays', 'reconcile', ['trigger' => 'test']);
eq($r['executed']['departed_cleanup'] ?? null, 2, 'both birthday events of the departed employee removed');
eq((int) col('SELECT COUNT(*) FROM birthday_events WHERE employee_id = ?', [$E['rebecca']]), 0, 'departed birthday rows deleted');
eq($G->count($usBd), 4, 'remote birthdays: 2 employees x 2 years');
// un-depart: topup + reconcile re-inserts exactly once
update_row('employees', ['status' => 'active', 'departed_on' => null], 'id = ?', [$E['rebecca']]);
eq(birthday_rows_topup(1), 2, 'un-depart top-up re-creates the 2 birthday rows');
sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
sync_calendar('us_birthdays', 'reconcile', ['trigger' => 'test']);
eq($G->count($usPto), 2, 'us_pto remote = the 2 live requests (R1 back, R4)');
eq($G->count($usBd), 6, 'birthday remote = 6 again');
$keys = $G->lspKeys($usBd);
eq(count($keys), count(array_unique($keys)), 'no duplicate birthday keys after un-depart');
sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq($G->count($usPto), 2, 'still 2 after another reconcile (no duplicates)');
// sync_depart_employee: immediate deletes + birthday rows gone
update_row('employees', ['status' => 'departed', 'departed_on' => '2026-09-10'], 'id = ?', [$E['pat']]);
$d = sync_depart_employee($E['pat']);
eq([$d['deleted'], $d['failed']], [3, 0], 'depart: 1 request + 2 birthday events deleted');
eq($G->byLsp($usPto, 'time_off:' . $R[4]), null, 'departed request event gone immediately');
eq((int) col('SELECT COUNT(*) FROM birthday_events WHERE employee_id = ?', [$E['pat']]), 0, 'departed birthday rows deleted');
eq(gid('time_off', ['id' => $R[4]]), null, 'departed request id cleared');
check((int) col('SELECT COUNT(*) FROM audit_log WHERE action = ? AND employee_id = ?', ['sync', $E['pat']]) === 1, 'departure audited');
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['departed_cleanup']), count($r['plan']['deletes']), count($r['plan']['orphaned'])], [0, 0, 0], 'nothing left to clean after a departure');
// depart in dry-run mode: nothing touched remotely, rows keep their ids for the next live run
setting_set('sync_mode', 'dry_run');
$d = sync_depart_employee($E['rebecca']);
eq($d['deleted'], 0, 'depart in dry-run deletes nothing');
check(str_contains($d['message'], 'dry-run'), 'depart dry-run message', $d['message']);
check(gid('time_off', ['id' => $R[1]]) !== null, 'ids kept in dry-run for the later cleanup');
setting_set('sync_mode', 'live');

// =========================================================================================================
echo "6. wipe and regenerate birthdays\n";
$G->add($usBd, 'Old hand-made birthday', '2019-05-14', '2019-05-15');
$G->add($usBd, 'Another old one', '2020-07-04', '2020-07-05');
$beforeCount = $G->count($usBd);
$w = sync_wipe_regenerate_birthdays('us_birthdays', false);
eq([$w['committed'], $w['remote_count'], $G->count($usBd)], [false, $beforeCount, $beforeCount], 'wipe dry run touches nothing');
$w = sync_wipe_regenerate_birthdays('us_birthdays', true);
eq($w['committed'], true, 'wipe committed');
eq($w['deleted'], $beforeCount, 'every remote event deleted');
$activeWithBday = (int) col('SELECT COUNT(*) FROM employees WHERE group_id = 1 AND status = ? AND birth_month IS NOT NULL', ['active']);
eq($w['inserted'], $activeWithBday * 2, 'Y and Y+1 inserted for active employees');
eq($G->count($usBd), $activeWithBday * 2, 'remote holds exactly the regenerated set');
eq((int) col('SELECT COUNT(*) FROM birthday_events b JOIN employees e ON e.id = b.employee_id WHERE e.group_id = 1 AND b.google_event_id IS NULL'), 0, 'all birthday rows carry ids');
eq($w['would_insert'], $activeWithBday * 2, 'would_insert reported for the Admin dry-run text');
eq(sync_wipe_regenerate_birthdays('us_pto', true)['refused'], 'not a birthday calendar', 'wipe refused on a non-birthday calendar');

// =========================================================================================================
echo "7. adoption: exact, case-insensitive, refusal when ids exist; unmanaged; adopt-one; explicit delete\n";
$exactId = $G->add($usSales, 'End of Month Sale', '2026-09-28', '2026-10-01');
$a = sync_adopt('us_sales', false);
eq([count($a['matched']), count($a['unmatched_remote']), count($a['unmatched_rows']), $a['refused']], [1, 0, 0, null], 'exact match dry run');
eq($a['matched'][0]['reason'] ?? null, 'exact', 'exact reason');
$G->cals[$usSales][$exactId]['summary'] = '  end OF month SALE ';
$strayId = $G->add($usSales, 'Unrelated sale', '2026-03-01', '2026-03-02');
$a = sync_adopt('us_sales', false);
eq([count($a['matched']), count($a['unmatched_remote']), $a['matched'][0]['reason'] ?? null], [1, 1, 'case-insensitive title'], 'case-insensitive match + unmatched remote');
eq(gid('events', ['id' => $EV[2]]), null, 'dry run stores nothing');
$a = sync_adopt('us_sales', true);
eq([$a['committed'], $a['refused']], [true, null], 'adopt committed');
eq(gid('events', ['id' => $EV[2]]), $exactId, 'adopted id stored');
eq([$G->cals[$usSales][$exactId]['summary'], $G->cals[$usSales][$exactId]['extendedProperties']['private']['lsp'] ?? null], ['End of Month Sale', 'event:' . $EV[2]], 'remote patched with canonical title + lsp');
$a = sync_adopt('us_sales', true);
check($a['refused'] !== null && str_contains($a['refused'], 'already'), 'adopt refused when ids exist', (string) $a['refused']);
check(sync_adopt('us_birthdays', false)['refused'] !== null, 'adopt refused on a birthday calendar');
$um = sync_unmanaged('us_sales');
eq([count($um), $um[0]['id'] ?? null, $um[0]['title'] ?? null], [1, $strayId, 'Unrelated sale'], 'unmanaged list');
$EV[4] = insert('events', ['cal_key' => 'us_sales', 'title' => 'March Sale', 'start_date' => '2026-03-01', 'end_date' => '2026-03-01'] + $ts);
eq(sync_adopt_one('us_sales', $strayId, 'event:' . $EV[4]), true, 'adopt-one ok');
eq([gid('events', ['id' => $EV[4]]), $G->cals[$usSales][$strayId]['extendedProperties']['private']['lsp'] ?? null, $G->cals[$usSales][$strayId]['summary']], [$strayId, 'event:' . $EV[4], 'March Sale'], 'adopt-one stored + patched');
eq(sync_adopt_one('us_sales', $strayId, 'event:424242'), false, 'adopt-one refuses an unknown row');
$junkId = $G->add($usSales, 'Junk', '2026-04-01', '2026-04-02');
eq(sync_delete_remote('us_sales', $junkId), true, 'explicit delete ok');
eq(isset($G->cals[$usSales][$junkId]), false, 'explicit delete removed the event');
check((int) col('SELECT COUNT(*) FROM audit_log WHERE action = ? AND summary LIKE ?', ['sync', '%deleted calendar event%']) === 1, 'explicit delete audited');
$r = sync_calendar('us_sales', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['inserts']), count($r['plan']['patches']), count($r['plan']['unmanaged']), count($r['plan']['orphaned'])], [0, 0, 0, 0], 'us_sales fully consistent after adoption');

// =========================================================================================================
echo "7b. delete all unmanaged events\n";
$managedBefore = $G->count($usSales);
check($managedBefore >= 2, 'us_sales has managed events to protect', (string) $managedBefore);
$junkA = $G->add($usSales, 'Old script copy A', '2026-05-01', '2026-05-02');
$junkB = $G->add($usSales, 'Old script copy B', '2026-05-03', '2026-05-04');
$junkC = $G->add($usSales, 'Old script copy C', '2026-05-05', '2026-05-06');
eq(count(sync_unmanaged('us_sales')), 3, 'three unmanaged events listed');
// refused locally (explicit writes off): nothing deleted, no audit row
$GLOBALS['config']['environment'] = 'local';
$auditBefore = (int) col('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['sync']);
$d = sync_delete_unmanaged_all('us_sales');
eq([$d['deleted'], $d['failed'], $d['skipped']], [0, 0, 0], 'refused locally: counts zero');
check(str_starts_with($d['message'], 'refused'), 'refused locally: message', $d['message']);
eq($G->count($usSales), $managedBefore + 3, 'refused locally: nothing deleted');
eq((int) col('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['sync']), $auditBefore, 'refused locally: no audit row');
$GLOBALS['config']['environment'] = 'production';
// refused without a calendar id
$savedCalId = calid('us_sales');
update_row('calendars', ['google_calendar_id' => null], 'cal_key = ?', ['us_sales']);
$d = sync_delete_unmanaged_all('us_sales');
check(str_starts_with($d['message'], 'refused') && str_contains($d['message'], 'calendar id'), 'refused without a calendar id', $d['message']);
eq($G->count($usSales), $managedBefore + 3, 'refused without id: nothing deleted');
update_row('calendars', ['google_calendar_id' => $savedCalId], 'cal_key = ?', ['us_sales']);
// one per-event failure (500 on B) does not stop the others; 404 counts as done
$G->fail = static function (string $m, string $u) use ($junkB): ?array {
    return $m === 'DELETE' && str_contains($u, rawurlencode($junkB)) ? [500, ['error' => ['code' => 500, 'message' => 'boom']]] : null;
};
$logBefore = count(sync_log_tail(1000));
$d = sync_delete_unmanaged_all('us_sales');
$G->fail = null;
eq([$d['deleted'], $d['failed'], $d['skipped']], [2, 1, 0], 'A and C deleted, B failed, loop continued');
eq([isset($G->cals[$usSales][$junkA]), isset($G->cals[$usSales][$junkB]), isset($G->cals[$usSales][$junkC])], [false, true, false], 'only B remains');
eq($G->count($usSales), $managedBefore + 1, 'managed events untouched');
eq(count(sync_log_tail(1000)) - $logBefore, 1, 'one sync.log line per run');
$lastLine = (string) (sync_log_tail(1)[0] ?? '');
check(str_contains($lastLine, 'us_sales delete-unmanaged-all listed=3 deleted=2 failed=1 skipped=0'), 'sync.log line carries the counts', $lastLine);
eq((int) col('SELECT COUNT(*) FROM audit_log WHERE action = ? AND summary LIKE ?', ['sync', '%deleted all unmanaged%']), 1, 'one audit row with the counts');
$aj = json_decode((string) col('SELECT after_json FROM audit_log WHERE action = ? AND summary LIKE ? ORDER BY id DESC LIMIT 1', ['sync', '%deleted all unmanaged%']), true);
eq([$aj['deleted'] ?? null, $aj['failed'] ?? null, $aj['listed'] ?? null], [2, 1, 3], 'audit after_json counts');
// second run finishes the job
$d = sync_delete_unmanaged_all('us_sales');
eq([$d['deleted'], $d['failed']], [1, 0], 'retry deletes the remaining one');
eq(sync_unmanaged('us_sales'), [], 'no unmanaged events left');
eq($G->count($usSales), $managedBefore, 'managed events all still there');
$r = sync_calendar('us_sales', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['inserts']), count($r['plan']['patches']), count($r['plan']['deletes']), count($r['plan']['unmanaged']), count($r['plan']['orphaned'])], [0, 0, 0, 0, 0], 'us_sales still fully consistent');

// =========================================================================================================
echo "8. guards: empty desired set, delete guard (20% and 25), force\n";
// Manila's only active employee with a birthday is Myra: blank it so nothing is eligible (Old Timer is departed)
update_row('employees', ['birth_month' => null, 'birth_day' => null], 'id = ?', [$E['myra']]);
$r = sync_calendar('mn_birthdays', 'reconcile', ['trigger' => 'test']);
check($r['aborted'] !== null && str_contains($r['aborted'], 'empty'), 'mn_birthdays: empty set aborts (no eligible employee)', (string) $r['aborted']);
eq((int) cal('mn_birthdays')['last_sync_ok'], 0, 'abort recorded as a failed run');
eq((int) col('SELECT COUNT(*) FROM birthday_events b JOIN employees e ON e.id = b.employee_id WHERE e.group_id = 2'), 0, 'no Manila birthday rows created by the abort');
update_row('employees', ['birth_month' => 12, 'birth_day' => 25], 'id = ?', [$E['myra']]);
// us_holidays has 1 mirrored row: 2 audited deletes are an ordinary edit (below the floor of 3) and go through;
// 3 deletes = 300% -> abort; force -> executes
$g1 = $G->add($usHol, 'Gone 1', '2025-01-01', '2025-01-02', 'event:9001');
$g2 = $G->add($usHol, 'Gone 2', '2025-01-03', '2025-01-04', 'event:9002');
audit('delete', 'events', 9001, null, 1, ['id' => 9001, 'cal_key' => 'us_holidays', 'google_event_id' => $g1], null, 'test delete');
audit('delete', 'events', 9002, null, 1, ['id' => 9002, 'cal_key' => 'us_holidays', 'google_event_id' => $g2], null, 'test delete');
$r = sync_calendar('us_holidays', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['deletes']), $r['aborted'], $r['executed']['deletes'] ?? null, $G->count($usHol)], [2, null, 2, 1], 'two deletes on a tiny calendar are below the guard floor');
$gs = [];
for ($i = 1; $i <= 3; $i++) {
    $gs[$i] = $G->add($usHol, "Gone $i", '2025-02-0' . $i, '2025-02-0' . ($i + 1), 'event:' . (9100 + $i));
    audit('delete', 'events', 9100 + $i, null, 1, ['id' => 9100 + $i, 'cal_key' => 'us_holidays', 'google_event_id' => $gs[$i]], null, 'test delete');
}
$r = sync_calendar('us_holidays', 'reconcile', ['trigger' => 'test']);
eq(count($r['plan']['deletes']), 3, 'three deletes planned');
check($r['aborted'] !== null && str_contains($r['aborted'], '20%'), 'ratio guard aborts', (string) $r['aborted']);
eq($G->count($usHol), 4, 'nothing deleted while aborted');
eq($r['executed'], [], 'nothing executed while aborted');
check(str_contains($r['message'], 'aborted'), 'aborted run message', $r['message']);
eq((int) cal('us_holidays')['last_sync_ok'], 0, 'abort recorded');
$r = sync_calendar('us_holidays', 'reconcile', ['trigger' => 'test', 'force' => true]);
eq([$r['ok'], $r['executed']['deletes'] ?? null, $G->count($usHol)], [true, 3, 1], 'force overrides the delete guard');
// > 25 deletes on a calendar whose mirrored count would otherwise allow the ratio
$ids = [];
for ($i = 0; $i < 26; $i++) {
    $ids[] = $G->add($usPto, "Gone $i", '2024-01-01', '2024-01-02', 'time_off:' . (5000 + $i));
    audit('delete', 'time_off', 5000 + $i, $E['sam'], 1, ['id' => 5000 + $i, 'employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2024-01-01', 'end_date' => '2024-01-01', 'google_event_id' => end($ids)], null, 'test delete');
}
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
check($r['aborted'] !== null && str_contains($r['aborted'], '26 deletes'), 'absolute guard aborts at 26', (string) $r['aborted']);
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test', 'force' => true]);
eq($r['executed']['deletes'] ?? null, 26, 'force executes the 26 deletes');
// the incremental audited-delete path also respects the guard: make the cursor see them and ensure nothing planned now
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq(count($r['plan']['deletes']), 0, 'audited deletes already applied are not re-planned (404 would be harmless anyway)');

// =========================================================================================================
echo "9. dry run writes nothing (sync_mode dry_run and non-production environment)\n";
setting_set('sync_mode', 'dry_run');
$R[7] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2027-01-04', 'end_date' => '2027-01-05'] + $ts);
mark_dirty('us_pto');
$posts = $G->posts();
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq([$r['ok'], $r['dry_run'], count($r['plan']['inserts']), $r['executed']], [true, true, 1, []], 'dry run: plan computed, nothing executed');
check(str_starts_with($r['message'], 'dry run (sync_mode is dry_run, nothing written):'), 'dry run message names the reason', $r['message']);
eq($G->posts(), $posts, 'no POST in dry run');
eq(gid('time_off', ['id' => $R[7]]), null, 'no id stored in dry run');
eq((int) cal('us_pto')['dirty'], 1, 'dirty stays set after a dry run');
eq(sync_dirty_inline(1), 'Calendar sync is in dry-run mode.', 'inline hook reports dry-run mode');
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq([$r['dry_run'], $G->posts()], [true, $posts], 'reconcile dry run lists but never writes');
setting_set('sync_mode', 'live');
$GLOBALS['config']['environment'] = 'local';
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test', 'force' => true]);
eq([$r['dry_run'], $G->posts(), gid('time_off', ['id' => $R[7]])], [true, $posts, null], 'environment guard blocks writes locally even with force');
eq(sync_dirty_inline(1), 'Calendar sync is in dry-run mode.', 'inline hook: local environment');
$GLOBALS['config']['environment'] = 'production';
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq([$r['dry_run'], $r['executed']['inserts'] ?? null], [false, 1], 'back in production the insert goes through');
eq(sync_dirty_inline(1), '', 'inline hook: nothing dirty -> empty string');
// Force is an explicit write (SPEC 14.1): in production + dry_run it executes the plan, so every google_* call must carry explicit
setting_set('sync_mode', 'dry_run');
$R[9] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'Vacation', 'start_date' => '2027-03-01', 'end_date' => '2027-03-02'] + $ts);
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test', 'force' => true]);
eq([$r['dry_run'], $r['executed']['inserts'] ?? null, $r['executed']['failed'] ?? null, $r['ok']], [false, 1, 0, true], 'Force writes in dry_run mode (explicit) without per-row writes_disabled failures');
check(gid('time_off', ['id' => $R[9]]) !== null, 'forced insert stored its id');
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
check(str_starts_with($r['message'], 'dry run (sync_mode is dry_run'), 'plain run is a dry run again', $r['message']);
$GLOBALS['config']['environment'] = 'local';
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
check(str_contains($r['message'], 'environment is local, not production'), 'local dry-run message names the environment', $r['message']);
$GLOBALS['config']['environment'] = 'production';
setting_set('sync_mode', 'live');
// listing window (SPEC 14.1: 2021..2040): a row outside it is never listed, so it must not be desired either (or every reconcile re-inserts it)
$R[10] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2019-05-01', 'end_date' => '2019-05-01'] + $ts);
$countBefore = $G->count($usPto);
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['inserts']), $G->count($usPto)], [0, $countBefore], 'a 2019 request is outside the listing window: not planned, not inserted');
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq(count($r['plan']['inserts']), 0, 'incremental skips it too');
eq(gid('time_off', ['id' => $R[10]]), null, 'row untouched');
q('DELETE FROM time_off WHERE id = ?', [$R[10]]);

// =========================================================================================================
echo "10. lost-insert recovery via privateExtendedProperty; paging\n";
$R[8] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2027-02-01', 'end_date' => '2027-02-01'] + $ts);
$lostId = $G->add($usPto, 'Catuto, Sam - PTO', '2027-02-01', '2027-02-02', 'time_off:' . $R[8]);
$posts = $G->posts();
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['inserts'] ?? null, 1, 'row counted as inserted');
eq($G->posts(), $posts, 'no POST: the existing event was recovered by key');
eq(gid('time_off', ['id' => $R[8]]), $lostId, 'recovered id stored');
$G->pageSize = 2;
for ($i = 1; $i <= 5; $i++) {
    $G->add('paging-cal', "Page $i", '2026-01-0' . $i, '2026-01-0' . ($i + 1));
}
$calls = count($G->calls);
$listed = google_list_events('paging-cal');
eq(count($listed), 5, 'paging returns every event');
eq(count($G->calls) - $calls, 3, 'paging followed nextPageToken (3 pages of 2)');
$G->pageSize = 3;

// =========================================================================================================
echo "11. 404 on patch -> clear id, re-insert on the next pass\n";
update_row('time_off', ['google_event_id' => 'vanished', 'synced_fingerprint' => 'stale'], 'id = ?', [$R[7]]);
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['patches'] ?? null, 0, 'patch did not count');
eq($r['executed']['needs_pass'] ?? null, true, 'run asks for another pass');
eq(gid('time_off', ['id' => $R[7]]), null, 'stale id cleared');
eq($r['ok'], true, 'a 404 patch is not a failure');
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq($r['executed']['inserts'] ?? null, 1, 're-inserted on the next pass');
check(gid('time_off', ['id' => $R[7]]) !== null, 'new id stored');
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['inserts']), count($r['plan']['patches']), count($r['plan']['orphaned'])], [0, 0, 0], 'consistent afterwards');

// =========================================================================================================
echo "12. transport retries: 401 refresh once, 5xx/429/403 back-off, other errors throw; per-row failure = partial\n";
$tokensBefore = $G->tokens;
$once = false;
$G->fail = static function (string $m, string $u) use (&$once): ?array {
    if (!$once && $m === 'GET') {
        $once = true;
        return [401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials', 'errors' => [['reason' => 'authError']]]]];
    }
    return null;
};
$listed = google_list_events($usPto);
eq($G->tokens, $tokensBefore + 1, '401 refreshed the token once');
check(count($listed) > 0, 'request succeeded after the refresh');
$SLEEPS = [];
$left = 2;
$G->fail = static function (string $m, string $u) use (&$left): ?array {
    if ($left > 0) {
        $left--;
        return [503, ['error' => ['code' => 503, 'message' => 'Backend Error', 'errors' => [['reason' => 'backendError']]]]];
    }
    return null;
};
google_list_events($usPto);
eq($SLEEPS, [1, 2], '5xx backed off 1 s then 2 s');
$SLEEPS = [];
$left = 1;
$G->fail = static function (string $m, string $u) use (&$left): ?array {
    if ($left > 0) {
        $left--;
        return [403, ['error' => ['code' => 403, 'message' => 'Rate Limit Exceeded', 'errors' => [['reason' => 'rateLimitExceeded']]]]];
    }
    return null;
};
google_list_events($usPto);
eq($SLEEPS, [1], '403 rateLimitExceeded backs off');
$SLEEPS = [];
$G->fail = static fn(string $m, string $u): ?array => [500, ['error' => ['code' => 500, 'message' => 'boom']]];
try {
    google_list_events($usPto);
    check(false, 'persistent 5xx throws');
} catch (GoogleApiError $e) {
    eq([$e->status, $SLEEPS], [500, [1, 2, 4, 8]], 'persistent 5xx: four back-offs then GoogleApiError');
}
$G->fail = static fn(string $m, string $u): ?array => [403, ['error' => ['code' => 403, 'message' => 'Forbidden', 'errors' => [['reason' => 'forbidden']]]]];
$SLEEPS = [];
try {
    google_list_events($usPto);
    check(false, '403 forbidden throws');
} catch (GoogleApiError $e) {
    eq([$e->status, $e->reason, $SLEEPS], [403, 'forbidden', []], '403 forbidden is not retried');
}
$G->fail = null;
// per-row failure -> sync_error, run continues, result partial
$R[9] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2027-03-01', 'end_date' => '2027-03-01'] + $ts);
$R[10] = insert('time_off', ['employee_id' => $E['sam'], 'kind' => 'PTO', 'start_date' => '2027-03-08', 'end_date' => '2027-03-08'] + $ts);
$G->fail = static function (string $m, string $u) use (&$posts): ?array {
    if ($m === 'POST') {
        $body = ['error' => ['code' => 400, 'message' => 'Invalid value', 'errors' => [['reason' => 'invalid']]]];
        static $n = 0;
        return ++$n === 1 ? [400, $body] : null;   // first insert fails, the second goes through
    }
    return null;
};
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
$G->fail = null;
eq([$r['ok'], $r['executed']['inserts'] ?? null, $r['executed']['failed'] ?? null], [false, 1, 1], 'partial run: one insert failed, one succeeded');
check(str_starts_with($r['message'], 'partial:'), 'partial message', $r['message']);
$errs = rows('SELECT id, sync_error FROM time_off WHERE id IN (?, ?) AND sync_error IS NOT NULL', [$R[9], $R[10]]);
eq(count($errs), 1, 'sync_error stored on the failed row only');
check(str_contains((string) $errs[0]['sync_error'], 'Invalid value'), 'sync_error text');
eq((int) cal('us_pto')['last_sync_ok'], 0, 'partial run recorded as not ok');
$r = sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
eq([$r['ok'], $r['executed']['inserts']], [true, 1], 'retry inserts the failed row');
eq((int) col('SELECT COUNT(*) FROM time_off WHERE sync_error IS NOT NULL'), 0, 'sync_error cleared on success');

// =========================================================================================================
echo "13. mark_dirty, inline hook, status strip, test connection, log tail\n";
mark_dirty('us_pto');
eq((int) cal('us_pto')['dirty'], 1, 'mark_dirty sets dirty');
check(setting('dirty_since_us_pto') !== null, 'mark_dirty records dirty_since');
$since = setting('dirty_since_us_pto');
mark_dirty('us_pto');
eq(setting('dirty_since_us_pto'), $since, 'marking again keeps the original dirty_since');
eq(sync_dirty_inline(1), 'Calendar updated.', 'inline hook syncs the dirty calendar');
eq([(int) cal('us_pto')['dirty'], setting('dirty_since_us_pto')], [0, null], 'inline hook cleared dirty + dirty_since');
mark_dirty('us_pto');
eq(sync_dirty_inline(1, 0.5), 'Calendar will update within 15 minutes.', 'inline hook out of budget defers to cron');
eq((int) cal('us_pto')['dirty'], 1, 'still dirty for cron');
sync_calendar('us_pto', 'incremental', ['trigger' => 'test']);
sync_calendar('us_factory', 'reconcile', ['trigger' => 'test']);   // never run before: give it a last_sync_at
$s = sync_status_for_group(1);
eq($s['state'], 'ok', 'status: ok', $s['summary']);
check(str_starts_with($s['summary'], 'All 5 calendars in sync, last run'), 'status summary', $s['summary']);
eq(array_keys($s['calendars']), ['us_pto', 'us_birthdays', 'us_holidays', 'us_factory', 'us_sales'], 'status lists the group calendars in order');
eq($s['calendars']['us_pto']['state'], 'ok', 'per-calendar state');
update_row('calendars', ['last_sync_ok' => 0, 'last_sync_message' => 'failed: boom'], 'cal_key = ?', ['us_sales']);
$s = sync_status_for_group(1);
eq($s['state'], 'failed', 'status: failed');
check(str_contains($s['summary'], 'End of Month Sales'), 'failed summary names the calendar', $s['summary']);
update_row('calendars', ['last_sync_ok' => 1], 'cal_key = ?', ['us_sales']);
update_row('calendars', ['dirty' => 1], 'cal_key = ?', ['us_holidays']);
setting_set('dirty_since_us_holidays', date('Y-m-d H:i:s', time() - 45 * 60));
$s = sync_status_for_group(1);
eq($s['state'], 'stale', 'status: stale after 30+ minutes dirty');
eq($s['calendars']['us_holidays']['minutes_dirty'], 45, 'minutes dirty derived from dirty_since');
setting_set('dirty_since_us_holidays', now_str());
$s = sync_status_for_group(1);
eq([$s['state'], $s['calendars']['us_holidays']['state']], ['ok', 'pending'], 'freshly dirty = pending, strip still ok');
update_row('calendars', ['dirty' => 0], 'cal_key = ?', ['us_holidays']);
setting_set('dirty_since_us_holidays', null);
setting_set('sync_mode', 'dry_run');
eq(sync_status_for_group(1)['state'], 'dry_run', 'status: dry_run');
setting_set('sync_mode', 'live');
// point the key path at a file that does not exist (never at the developer's real pto_data key)
$GLOBALS['google_key_path'] = $missingKeyFile;
eq(google_status(false)['key_file'], false, 'status: missing key file reported');
eq(sync_status_for_group(1)['state'], 'not_configured', 'status: not configured without a key file');
eq(sync_dirty_inline(2), '', 'inline hook with nothing dirty in group 2');
mark_dirty('mn_pto');
eq(sync_dirty_inline(2), 'Calendar sync is not configured.', 'inline hook without a key file');
$r = sync_calendar('mn_pto', 'reconcile', ['dry_run' => true, 'trigger' => 'test']);
eq([$r['ok'], count($r['plan']['inserts'])], [true, 1], 'preview without a key file still shows the full plan');
check(str_contains($r['message'], 'not configured'), 'preview message says Google is not configured', $r['message']);
$r = sync_calendar('mn_pto', 'incremental', ['trigger' => 'test']);
check(!$r['ok'] && str_contains($r['message'], 'not configured'), 'a live run without a key file fails cleanly', $r['message']);
$GLOBALS['google_key_path'] = $keyFile;
$s = sync_status_for_group(2);
eq($s['state'], 'failed', 'group 2: the aborted mn_birthdays run shows as failed', $s['summary']);
// test connection: probe inserted and deleted, count unchanged
$n = $G->count($mnPto);
$t = sync_test_connection('mn_pto');
eq([$t['ok'], $G->count($mnPto)], [true, $n], 'test connection ok, probe removed');
$GLOBALS['config']['environment'] = 'local';
$t = sync_test_connection('mn_pto');
check($t['ok'] && str_contains($t['message'], 'not available in local environment'), 'local test connection is read-only', $t['message']);
$GLOBALS['config']['environment'] = 'production';
$tail = sync_log_tail(3);
eq(count($tail), 3, 'log tail returns the requested number of lines');
check(str_contains($tail[2], 'test-connection'), 'log tail ends with the latest line', $tail[2]);

// =========================================================================================================
echo "14. moved event, reset state, alerts\n";
sync_calendar('us_holidays', 'reconcile', ['trigger' => 'test']);
$holId = (string) gid('events', ['id' => $EV[1]]);
update_row('events', ['cal_key' => 'us_factory'], 'id = ?', [$EV[1]]);
$r = sync_calendar('us_holidays', 'reconcile', ['trigger' => 'test']);
eq([count($r['plan']['deletes']), $r['plan']['deletes'][0]['reason'] ?? null], [1, 'moved to us_factory'], 'moved event deleted from the old calendar');
eq([isset($G->cals[$usHol][$holId]), gid('events', ['id' => $EV[1]]), (int) cal('us_factory')['dirty']], [false, null, 1], 'old copy gone, id cleared, new calendar dirty');
$r = sync_calendar('us_factory', 'incremental', ['trigger' => 'test']);
eq($r['executed']['inserts'] ?? null, 1, 'moved event inserted on the new calendar');
$auditBefore = (int) col('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['sync']);
sync_reset_state(1);
eq((int) col('SELECT COUNT(*) FROM time_off t JOIN employees e ON e.id = t.employee_id WHERE e.group_id = 1 AND t.google_event_id IS NOT NULL'), 0, 'reset(1) cleared US time_off ids');
eq((int) col('SELECT COUNT(*) FROM events WHERE cal_key LIKE ? AND google_event_id IS NOT NULL', ['us_%']), 0, 'reset(1) cleared US event ids');
check(cal('us_pto')['last_sync_at'] === null, 'reset cleared last_sync_at');
eq((int) col('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['sync']), $auditBefore + 1, 'reset audited');
sync_calendar('mn_pto', 'reconcile', ['trigger' => 'test']);
check(gid('time_off', ['id' => $R[6]]) !== null, 'Manila untouched by reset(1) and synced');
sync_reset_state(null);
eq((int) col('SELECT COUNT(*) FROM time_off WHERE google_event_id IS NOT NULL'), 0, 'reset(all) cleared everything');
// reconcile after a reset re-adopts every remote event by key without inserting duplicates
$posts = $G->posts();
$r = sync_calendar('us_pto', 'reconcile', ['trigger' => 'test']);
eq([$G->posts(), $r['executed']['touched'] ?? null, count($r['plan']['inserts'])], [$posts, $G->count($usPto), 0], 'after reset the listing re-adopts by key (no inserts)');
// alerts
$MAILS = [];
setting_set('consecutive_sync_failures', '0');
setting_set('last_alert_sent_at', null);
alert_note_sync_result('us_pto', false, 'failed: x');
alert_note_sync_result('us_pto', false, 'failed: x');
eq($MAILS, [], 'no alert before 3 failures');
alert_note_sync_result('us_pto', false, 'failed: x');
eq(count($MAILS), 1, 'alert after the 3rd consecutive failure');
alert_note_sync_result('us_pto', false, 'failed: x');
eq(count($MAILS), 1, 'once per day: 4th failure suppressed');
check(setting('last_alert_sent_at') !== null, 'last_alert_sent_at recorded');
alert_note_sync_result('us_pto', true, 'ok');
eq(setting('consecutive_sync_failures'), '0', 'counter reset on success');
check(str_contains(alert_test(), 'sent'), 'alert_test bypasses the daily limit');
eq(count($MAILS), 2, 'test alert mailed');
$GLOBALS['config']['environment'] = 'local';
unset($GLOBALS['alert_mailer']);
check(str_contains(alert_send('x', 'y')['message'], 'Local environment'), 'local environment only logs alerts');
$GLOBALS['config']['environment'] = 'production';

// =========================================================================================================
echo "15. lock and the cron scripts load\n";
eq((int) col('SELECT GET_LOCK(?, 0)', ['pto_sync_us_pto']), 1, 'lock acquired (reentrant on one connection)');
q('SELECT RELEASE_LOCK(?)', ['pto_sync_us_pto']);
q('SELECT RELEASE_LOCK(?)', ['pto_sync_us_pto']);
foreach (['cron/sync.php', 'cron/nightly.php', 'tools/sync_cli.php'] as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(PTO_APP . '/' . $f) . ' 2>&1', $out, $code);
    eq($code, 0, "php -l $f");
}

// --- teardown ---------------------------------------------------------------------------------------------
$server->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
@unlink($GLOBALS['sync_log_file']);
@unlink($GLOBALS['alert_log_file']);
@unlink($keyFile);
@rmdir($tmp);

if ($fail === 0) {
    echo "\033[32mOK: $pass assertions\033[0m\n";
    exit(0);
}
echo "\033[31mFAILED: $fail of " . ($pass + $fail) . " assertions\033[0m\n";
exit(1);

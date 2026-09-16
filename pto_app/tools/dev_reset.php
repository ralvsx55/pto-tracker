<?php
declare(strict_types=1);

/**
 * Local reset (SPEC section 12). CLI only:
 *   C:/xampp/php/php.exe pto_app/tools/dev_reset.php
 * 1. writes pto_data/config.php from local defaults when it is missing
 * 2. drops and recreates the pto_local database from every migrations/NNN_*.sql in order
 * 3. creates admin chris@lightsaberpromotions.com / changeme-now, sets both viewer passwords to "staff"
 * 4. imports both fixture snapshots and prints the reports
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$root = dirname(__DIR__, 2);
$dataDir = $root . '/pto_data';
$configFile = $dataDir . '/config.php';
foreach (['', '/logs', '/sessions', '/backups'] as $sub) {
    if (!is_dir($dataDir . $sub)) {
        mkdir($dataDir . $sub, 0700, true);
    }
}
if (!is_file($configFile)) {
    $config = [
        'db'            => ['host' => '127.0.0.1', 'name' => 'pto_local', 'user' => 'root', 'pass' => ''],
        'base_url'      => 'http://127.0.0.1:8020/',
        'environment'   => 'local',
        'secret'        => bin2hex(random_bytes(32)),
        'install_token' => bin2hex(random_bytes(16)),
        'alert_email'   => 'chris@lightsaberpromotions.com',
        'alert_from'    => 'pto@lightsaberpromotions.com',
    ];
    $php = "<?php\ndeclare(strict_types=1);\n// Local config written by tools/dev_reset.php. See pto_app/config.example.php for the keys.\nreturn "
        . var_export($config, true) . ";\n";
    file_put_contents($configFile, $php);
    echo "Wrote $configFile\n";
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/import_sheet.php';

$c = config('db');
if (config('environment') === 'production') {
    exit("Refusing to reset a production configuration.\n");
}

// 1. drop + create the database (server-level connection, no dbname)
$server = new PDO(sprintf('mysql:host=%s;charset=utf8mb4', $c['host']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $c['name']));
$server->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $c['name']));
unset($server);
echo "Database {$c['name']} recreated\n";

// 2. schema + seeds: every migration in order, so local always matches SCHEMA_VERSION
foreach (apply_migrations() as $file => $n) {
    echo "Applied $file ($n statements)\n";
}

// 3. admin + viewer passwords
$adminId = user_create('chris@lightsaberpromotions.com', 'Chris Coleman', 'changeme-now', 'admin', null, false);
echo "Admin user #$adminId chris@lightsaberpromotions.com / changeme-now\n";
foreach (groups_all() as $g) {
    viewer_set_password((int) $g['id'], 'staff');
    echo "Viewer password for {$g['name']} set to \"staff\"\n";
}

// 4. import both fixtures
$fixtures = [
    'us'     => PTO_APP . '/tests/fixtures/us_snapshot_2026-09-14.json',
    'manila' => PTO_APP . '/tests/fixtures/manila_snapshot_2026-09-14.json',
];
$allOk = true;
foreach ($fixtures as $key => $file) {
    $g = group_by_key($key);
    $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    // as_of = the snapshot date in the file name (2026-09-14): the acceptance check against expected_<date>.json
    // stays exact even when Manila is already on the next day.
    $asOf = preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $m) ? $m[1] : date('Y-m-d');
    $report = import_snapshot((int) $g['id'], $json, ['commit' => true, 'replace_all' => false, 'as_of' => $asOf]);
    echo "\n--- import $key (" . basename($file) . ") ---\n";
    foreach ($report['lines'] as $line) {
        echo "  $line\n";
    }
    $allOk = $allOk && $report['committed'];
}

echo "\nSUMMARY\n";
foreach (groups_all() as $g) {
    $emp = (int) col('SELECT COUNT(*) FROM employees WHERE group_id = ?', [$g['id']]);
    $to = (int) col('SELECT COUNT(*) FROM time_off t JOIN employees e ON e.id = t.employee_id WHERE e.group_id = ?', [$g['id']]);
    $adj = (int) col('SELECT COUNT(*) FROM adjustments a JOIN employees e ON e.id = a.employee_id WHERE e.group_id = ?', [$g['id']]);
    $ev = (int) col('SELECT COUNT(*) FROM events ev JOIN calendars c ON c.cal_key = ev.cal_key WHERE c.group_id = ?', [$g['id']]);
    printf("  %-22s employees %2d, time_off %3d, adjustments %d, events %2d\n", $g['name'], $emp, $to, $adj, $ev);
}
echo '  schema_version: ' . (string) setting('schema_version', '?') . ' (code expects ' . SCHEMA_VERSION . ")\n";
echo '  audit_log rows: ' . (int) col('SELECT COUNT(*) FROM audit_log') . "\n";
echo $allOk ? "OK: reset complete\n" : "FAILED: an import did not commit\n";
exit($allOk ? 0 : 1);

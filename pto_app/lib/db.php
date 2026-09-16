<?php
declare(strict_types=1);

/** PDO access (LIB CONTRACT: db.php). Prepared statements only; ERRMODE_EXCEPTION. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Business dates are compared as strings; keep the connection's zone equal to PHP's for DATETIME safety.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

/** Prepare + execute; returns the statement. */
function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function row(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}

/** First column of the first row (or null). */
function col(string $sql, array $p = []): mixed
{
    $v = q($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}

/** INSERT one row from an assoc array; returns the new id (0 for tables without AUTO_INCREMENT). */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(static fn(string $c): string => "`$c`", $cols)),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** UPDATE `table` SET data WHERE where (where uses ? placeholders; $p are its parameters). */
function update_row(string $table, array $data, string $where, array $p): void
{
    if ($data === []) {
        return;
    }
    $set = implode(', ', array_map(static fn(string $c): string => "`$c` = ?", array_keys($data)));
    q("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $p));
}

/** Run $fn inside a transaction (nested calls join the outer one). Returns $fn's result. */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** settings table accessors. */
function setting(string $name, ?string $default = null): ?string
{
    $v = col('SELECT value FROM settings WHERE name = ?', [$name]);
    return $v === null ? $default : (string) $v;
}

function setting_set(string $name, ?string $value): void
{
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

/**
 * Split a .sql file into statements at semicolons outside quotes and comments.
 * Handles 'single' and "double" quoted strings (with '' and backslash escapes), backticks,
 * "-- " line comments, "#" line comments and slash-star block comments.
 */
function sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        $two = substr($sql, $i, 2);
        if ($two === '--' || $ch === '#') {
            // line comment: skip to end of line
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl + 1;
            $buf .= "\n";
            continue;
        }
        if ($two === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buf .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $c . $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {   // doubled quote inside string
                        $buf .= $c . $c;
                        $i += 2;
                        continue;
                    }
                    $buf .= $c;
                    $i++;
                    break;
                }
                $buf .= $c;
                $i++;
            }
            continue;
        }
        if ($ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            $i++;
            continue;
        }
        $buf .= $ch;
        $i++;
    }
    $stmt = trim($buf);
    if ($stmt !== '') {
        $out[] = $stmt;
    }
    return $out;
}

/** Apply a migration file statement by statement (DDL auto-commits in MariaDB, so no transaction). */
function apply_sql_file(string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read $path");
    }
    $n = 0;
    foreach (sql_statements($sql) as $stmt) {
        db()->exec($stmt);
        $n++;
    }
    return $n;
}

<?php
declare(strict_types=1);

/**
 * Google Calendar sync engine (SPEC section 14.2 - 14.3): the database is the source of truth and the ten
 * calendars are a mirror. Desired-set builders, planner, guards, executor, lock, log line, the hooks the
 * screens call (mark_dirty / sync_dirty_inline / sync_depart_employee / birthday_rows_topup), and the Admin
 * tools (reset, adopt, unmanaged, orphan delete, wipe-and-regenerate birthdays, test connection, status).
 *
 * Nothing here writes to Google unless google_writes_allowed() (or the explicit variant) says so.
 * Rows are never removed by the sync; a successful DELETE only clears google_event_id / synced_fingerprint.
 */

const SYNC_DELETE_GUARD_MAX   = 25;      // SPEC 14.3 step 4: never more than 25 deletes without Force
const SYNC_DELETE_GUARD_RATIO = 0.20;    // SPEC 14.3 step 4: nor more than 20% of the mirrored rows ...
const SYNC_DELETE_GUARD_FLOOR = 3;       // ... once at least 3 deletes are planned (1-2 deletes on a 5-event calendar is an ordinary edit)
const SYNC_STALE_MINUTES      = 30;      // SPEC 14.5: dirty for 30+ minutes = red banner
const SYNC_MODES              = ['incremental', 'reconcile'];

// --- small helpers -------------------------------------------------------------------------------------

/** The calendars row, or an InvalidArgumentException for an unknown key. */
function sync_calendar_row(string $calKey): array
{
    $c = row('SELECT * FROM calendars WHERE cal_key = ?', [$calKey]);
    if ($c === null) {
        throw new InvalidArgumentException("Unknown calendar '$calKey'.");
    }
    return $c;
}

/** Google all-day end date = inclusive end + 1 day (SPEC 14.1). */
function sync_end_exclusive(string $endInclusive): string
{
    $d = to_date($endInclusive);
    return $d === null ? $endInclusive : $d->modify('+1 day')->format('Y-m-d');
}

/** Fingerprint = sha1(title|start|end_exclusive) (SPEC 14.2). */
function sync_fingerprint(string $title, string $start, string $endExclusive): string
{
    return sha1($title . '|' . $start . '|' . $endExclusive);
}

/** Birthday date for a year; Feb 29 -> Mar 1 in non-leap years (SPEC 14.2). */
function sync_birthday_date(int $year, int $month, int $day): string
{
    if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
        return sprintf('%04d-03-01', $year);
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** PTO_DATA/logs/sync.log ($GLOBALS['sync_log_file'] overrides it; the test suite uses a scratch file). */
function sync_log_file(): string
{
    $f = $GLOBALS['sync_log_file'] ?? null;
    return is_string($f) && $f !== '' ? $f : PTO_DATA . '/logs/sync.log';
}

/** Append one timestamped line to sync.log (SPEC 14.3 step 5). */
function sync_log(string $line): void
{
    $file = sync_log_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($file, now_str() . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** The last $lines lines of sync.log (oldest first). */
function sync_log_tail(int $lines = 100): array
{
    $file = sync_log_file();
    if (!is_file($file)) {
        return [];
    }
    $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($all === false) {
        return [];
    }
    return array_values(array_slice($all, -$lines));
}

/** Normalise a Google event to ['id','title','start','end'(exclusive),'lsp','all_day']. */
function sync_remote_norm(array $ev): array
{
    $allDay = isset($ev['start']['date']);
    if ($allDay) {
        $start = (string) $ev['start']['date'];
        $end = (string) ($ev['end']['date'] ?? sync_end_exclusive($start));
    } else {
        // Timed events (old hand-made entries): the date part; an end at midnight is already exclusive.
        $sdt = (string) ($ev['start']['dateTime'] ?? '');
        $edt = (string) ($ev['end']['dateTime'] ?? $sdt);
        $start = substr($sdt, 0, 10);
        $end = substr($edt, 0, 10);
        if (substr($edt, 11, 8) !== '00:00:00' || $end === $start) {
            $end = sync_end_exclusive($end);
        }
    }
    $lsp = $ev['extendedProperties']['private']['lsp'] ?? null;
    return [
        'id'      => (string) $ev['id'],
        'title'   => (string) ($ev['summary'] ?? ''),
        'start'   => $start,
        'end'     => $end,
        'lsp'     => is_string($lsp) && $lsp !== '' ? $lsp : null,
        'all_day' => $allDay,
    ];
}

/** 'time_off:12' / 'event:5' / 'birthday:3:2026' -> ['table','row_id'|'employee_id'+'year'] or null. */
function sync_parse_key(string $key): ?array
{
    if (preg_match('/^time_off:(\d+)$/', $key, $m)) {
        return ['table' => 'time_off', 'row_id' => (int) $m[1]];
    }
    if (preg_match('/^event:(\d+)$/', $key, $m)) {
        return ['table' => 'events', 'row_id' => (int) $m[1]];
    }
    if (preg_match('/^birthday:(\d+):(\d{4})$/', $key, $m)) {
        return ['table' => 'birthday_events', 'employee_id' => (int) $m[1], 'year' => (int) $m[2]];
    }
    return null;
}

// --- desired set (SPEC 14.2) ---------------------------------------------------------------------------

/** One desired row. $r carries the DB columns google_event_id / synced_fingerprint / sync_error. */
function sync_desired_item(string $table, string $key, string $title, string $start, string $endExclusive, array $r, ?string $description): array
{
    return [
        'key'                => $key,
        'table'              => $table,
        'row_id'             => isset($r['id']) ? (int) $r['id'] : null,
        'employee_id'        => isset($r['employee_id']) ? (int) $r['employee_id'] : null,
        'year'               => isset($r['year']) ? (int) $r['year'] : null,
        'title'              => $title,
        'start'              => $start,
        'end'                => $endExclusive,
        'description'        => $description,
        'fingerprint'        => sync_fingerprint($title, $start, $endExclusive),
        'google_event_id'    => $r['google_event_id'] ?? null,
        'synced_fingerprint' => $r['synced_fingerprint'] ?? null,
        'sync_error'         => $r['sync_error'] ?? null,
    ];
}

/**
 * Is the date inside the listing window google_list_events() reads (SPEC 14.1: 2021-01-01 .. 2040-01-01)?
 * A row outside it is left off the desired set: it would never appear in the listing, so every reconcile
 * would plan it as "missing" and insert a duplicate.
 */
function sync_in_window(string $date): bool
{
    return $date >= substr(GOOGLE_LIST_MIN, 0, 10) && $date < substr(GOOGLE_LIST_MAX, 0, 10);
}

/** The desired set of a calendar keyed by lsp key, from the database only (SPEC 14.2 table; see sync_in_window). */
function sync_desired(array $cal): array
{
    $out = [];
    $groupId = (int) $cal['group_id'];
    switch ($cal['kind']) {
        case 'pto':
            // time_off rows of ACTIVE employees of the group; title "<name> - <kind>"
            $sql = 'SELECT t.id, t.employee_id, t.kind, t.start_date, t.end_date, t.google_event_id, t.synced_fingerprint, t.sync_error, e.name
                    FROM time_off t JOIN employees e ON e.id = t.employee_id
                    WHERE e.group_id = ? AND e.status = ? ORDER BY t.start_date, t.id';
            foreach (rows($sql, [$groupId, 'active']) as $r) {
                if (!sync_in_window((string) $r['start_date'])) {
                    continue;
                }
                $key = 'time_off:' . (int) $r['id'];
                $out[$key] = sync_desired_item('time_off', $key, $r['name'] . ' - ' . $r['kind'], (string) $r['start_date'], sync_end_exclusive((string) $r['end_date']), $r, null);
            }
            break;
        case 'birthdays':
            // Make sure this year's and next year's rows exist before building the set, so a first Sync now or
            // Preview never aborts with "desired set is empty" just because the nightly top-up has not run yet.
            birthday_rows_topup($groupId);
            // birthday_events rows whose employee is ACTIVE and has a month/day; title "<name> Birthday"
            $sql = 'SELECT b.employee_id, b.year, b.google_event_id, b.synced_fingerprint, b.sync_error, e.name, e.birth_month, e.birth_day
                    FROM birthday_events b JOIN employees e ON e.id = b.employee_id
                    WHERE e.group_id = ? AND e.status = ? AND e.birth_month IS NOT NULL AND e.birth_day IS NOT NULL
                    ORDER BY b.year, e.name';
            foreach (rows($sql, [$groupId, 'active']) as $r) {
                $key = 'birthday:' . (int) $r['employee_id'] . ':' . (int) $r['year'];
                $start = sync_birthday_date((int) $r['year'], (int) $r['birth_month'], (int) $r['birth_day']);
                if (!sync_in_window($start)) {
                    continue;
                }
                $out[$key] = sync_desired_item('birthday_events', $key, $r['name'] . ' Birthday', $start, sync_end_exclusive($start), $r, 'Birthday');
            }
            break;
        case 'events':
            // events with that cal_key; title as typed
            foreach (rows('SELECT * FROM events WHERE cal_key = ? ORDER BY start_date, id', [$cal['cal_key']]) as $r) {
                if (!sync_in_window((string) $r['start_date'])) {
                    continue;
                }
                $key = 'event:' . (int) $r['id'];
                $out[$key] = sync_desired_item('events', $key, (string) $r['title'], (string) $r['start_date'], sync_end_exclusive((string) $r['end_date']), $r, null);
            }
            break;
    }
    return $out;
}

/** Write google_event_id / synced_fingerprint / sync_error on the row a desired item points at. */
function sync_row_set(array $d, array $data): void
{
    switch ($d['table']) {
        case 'time_off':
            update_row('time_off', $data, 'id = ?', [(int) $d['row_id']]);
            break;
        case 'events':
            update_row('events', $data, 'id = ?', [(int) $d['row_id']]);
            break;
        case 'birthday_events':
            update_row('birthday_events', $data, 'employee_id = ? AND year = ?', [(int) $d['employee_id'], (int) $d['year']]);
            break;
    }
}

/** The public plan item for a desired row (SPEC 14.3: key, title, start, end, google_event_id, reason). */
function sync_plan_item(array $d, string $reason, ?string $googleEventId): array
{
    return [
        'key'             => $d['key'],
        'title'           => $d['title'],
        'start'           => $d['start'],
        'end'             => $d['end'],
        'google_event_id' => $googleEventId,
        'reason'          => $reason,
        'table'           => $d['table'],
        'row_id'          => $d['row_id'],
        '_d'              => $d,
    ];
}

/** The public plan item for a remote event. */
function sync_remote_item(array $n, string $reason): array
{
    return [
        'key'             => $n['lsp'],
        'title'           => $n['title'],
        'start'           => $n['start'],
        'end'             => $n['end'],
        'google_event_id' => $n['id'],
        'reason'          => $reason,
        'table'           => null,
        'row_id'          => null,
    ];
}

function sync_empty_plan(): array
{
    return ['inserts' => [], 'patches' => [], 'deletes' => [], 'unmanaged' => [], 'orphaned' => [], 'departed_cleanup' => []];
}

/** Strip the internal (_-prefixed) keys off the work lists: what the UI and CLI show. */
function sync_plan_public(array $work): array
{
    $plan = sync_empty_plan();
    foreach (array_keys($plan) as $list) {
        foreach ($work[$list] ?? [] as $item) {
            $plan[$list][] = array_filter($item, static fn(string $k): bool => $k[0] !== '_', ARRAY_FILTER_USE_KEY);
        }
    }
    return $plan;
}

/** Rows of departed employees that still hold a Google id (SPEC 14.3 step 1), as desired-like items. */
function sync_departed_rows(array $cal): array
{
    $out = [];
    $groupId = (int) $cal['group_id'];
    if ($cal['kind'] === 'pto') {
        $sql = 'SELECT t.id, t.employee_id, t.kind, t.start_date, t.end_date, t.google_event_id, t.synced_fingerprint, t.sync_error, e.name
                FROM time_off t JOIN employees e ON e.id = t.employee_id
                WHERE e.group_id = ? AND e.status = ? AND t.google_event_id IS NOT NULL';
        foreach (rows($sql, [$groupId, 'departed']) as $r) {
            $out[] = sync_desired_item('time_off', 'time_off:' . (int) $r['id'], $r['name'] . ' - ' . $r['kind'], (string) $r['start_date'], sync_end_exclusive((string) $r['end_date']), $r, null);
        }
    } elseif ($cal['kind'] === 'birthdays') {
        $sql = 'SELECT b.employee_id, b.year, b.google_event_id, b.synced_fingerprint, b.sync_error, e.name, e.birth_month, e.birth_day
                FROM birthday_events b JOIN employees e ON e.id = b.employee_id
                WHERE e.group_id = ? AND e.status = ? AND b.google_event_id IS NOT NULL';
        foreach (rows($sql, [$groupId, 'departed']) as $r) {
            $start = sync_birthday_date((int) $r['year'], (int) ($r['birth_month'] ?? 1), (int) ($r['birth_day'] ?? 1));
            $out[] = sync_desired_item('birthday_events', 'birthday:' . (int) $r['employee_id'] . ':' . (int) $r['year'], $r['name'] . ' Birthday', $start, sync_end_exclusive($start), $r, 'Birthday');
        }
    }
    return $out;
}

/** Is there an audit_log 'delete' for this table/row (SPEC 14.3 step 3a)? */
function sync_audit_delete_exists(string $table, int $rowId): bool
{
    return col('SELECT 1 FROM audit_log WHERE action = ? AND table_name = ? AND row_id = ? LIMIT 1', ['delete', $table, $rowId]) !== null;
}

/**
 * Incremental deletes (addition to SPEC 14.3 step 2, documented in README): audit_log 'delete' rows for this
 * calendar's table written after the last live run (cursor in settings sync_audit_cursor_<cal_key>) whose
 * before-image carried a Google id -> DELETE that id. Reconcile remains the safety net when a run fails.
 * Returns [items, maxAuditId].
 */
function sync_audited_deletes(array $cal): array
{
    $table = $cal['kind'] === 'pto' ? 'time_off' : ($cal['kind'] === 'events' ? 'events' : null);
    if ($table === null) {
        return [[], 0];
    }
    $cursor = (int) (setting('sync_audit_cursor_' . $cal['cal_key']) ?? '0');
    $items = [];
    $max = $cursor;
    $sql = 'SELECT id, row_id, before_json FROM audit_log WHERE action = ? AND table_name = ? AND id > ? ORDER BY id';
    foreach (rows($sql, ['delete', $table, $cursor]) as $a) {
        $max = max($max, (int) $a['id']);
        $before = json_decode((string) $a['before_json'], true);
        if (!is_array($before) || empty($before['google_event_id'])) {
            continue;
        }
        $gid = (string) $before['google_event_id'];
        if ($table === 'events') {
            if (($before['cal_key'] ?? null) !== $cal['cal_key']) {
                continue;
            }
            $title = (string) ($before['title'] ?? '');
            $key = 'event:' . (int) $a['row_id'];
            // A restored row (History) keeps its id: it is desired again, so leave the remote event alone.
            if (col('SELECT 1 FROM events WHERE id = ? AND google_event_id = ?', [(int) $a['row_id'], $gid]) !== null) {
                continue;
            }
        } else {
            $empGroup = col('SELECT group_id FROM employees WHERE id = ?', [(int) ($before['employee_id'] ?? 0)]);
            if ((int) $empGroup !== (int) $cal['group_id']) {
                continue;
            }
            $title = (string) col('SELECT name FROM employees WHERE id = ?', [(int) $before['employee_id']]) . ' - ' . (string) ($before['kind'] ?? '');
            $key = 'time_off:' . (int) $a['row_id'];
            if (col('SELECT 1 FROM time_off WHERE id = ? AND google_event_id = ?', [(int) $a['row_id'], $gid]) !== null) {
                continue;
            }
        }
        $items[$gid] = [
            'key'             => $key,
            'title'           => $title,
            'start'           => (string) ($before['start_date'] ?? ''),
            'end'             => isset($before['end_date']) ? sync_end_exclusive((string) $before['end_date']) : '',
            'google_event_id' => $gid,
            'reason'          => 'deleted in app',
            'table'           => $table,
            'row_id'          => (int) $a['row_id'],
            '_clear'          => null,
        ];
    }
    return [array_values($items), $max];
}

/**
 * Reconcile step 3a: a remote event with an lsp key that no desired row claims.
 * Returns [verdict 'delete'|'orphan', reason, ?desired-like row whose stored id must be cleared].
 */
function sync_classify_remote(array $cal, array $n, array $desired): array
{
    $key = (string) $n['lsp'];
    if ($key === 'probe') {
        return ['orphan', 'leftover connection test probe', null];
    }
    $k = sync_parse_key($key);
    if ($k === null) {
        return ['orphan', 'unrecognised key', null];
    }
    $dup = isset($desired[$key]) ? 'duplicate of ' . (string) ($desired[$key]['google_event_id'] ?? '?') : null;
    switch ($k['table']) {
        case 'time_off':
            $r = row('SELECT t.id, t.google_event_id, e.status, e.group_id FROM time_off t JOIN employees e ON e.id = t.employee_id WHERE t.id = ?', [$k['row_id']]);
            if ($r === null) {
                return sync_audit_delete_exists('time_off', $k['row_id'])
                    ? ['delete', 'deleted in app', null]
                    : ['orphan', 'no matching time_off row and no delete in history', null];
            }
            $clear = $r['google_event_id'] === $n['id'] ? ['table' => 'time_off', 'row_id' => (int) $r['id']] : null;
            if ($r['status'] === 'departed') {
                return ['delete', 'employee departed', $clear];
            }
            if ((int) $r['group_id'] !== (int) $cal['group_id']) {
                return ['orphan', 'row belongs to another group', null];
            }
            return ['orphan', $dup ?? 'row not in desired set', null];
        case 'events':
            $r = row('SELECT id, cal_key, google_event_id FROM events WHERE id = ?', [$k['row_id']]);
            if ($r === null) {
                return sync_audit_delete_exists('events', $k['row_id'])
                    ? ['delete', 'deleted in app', null]
                    : ['orphan', 'no matching events row and no delete in history', null];
            }
            if ($r['cal_key'] !== $cal['cal_key']) {
                // Moved to another calendar: remove it here; clearing the stale id lets the new calendar insert it.
                $clear = $r['google_event_id'] === $n['id'] ? ['table' => 'events', 'row_id' => (int) $r['id'], '_dirty' => (string) $r['cal_key']] : null;
                return ['delete', 'moved to ' . $r['cal_key'], $clear];
            }
            return ['orphan', $dup ?? 'row not in desired set', null];
        case 'birthday_events':
            $e = row('SELECT id, status, group_id FROM employees WHERE id = ?', [$k['employee_id']]);
            if ($e === null) {
                return ['orphan', 'no such employee', null];
            }
            $b = row('SELECT google_event_id FROM birthday_events WHERE employee_id = ? AND year = ?', [$k['employee_id'], $k['year']]);
            $clear = $b !== null && $b['google_event_id'] === $n['id'] ? ['table' => 'birthday_events', 'employee_id' => $k['employee_id'], 'year' => $k['year']] : null;
            if ($e['status'] === 'departed') {
                return ['delete', 'employee departed', $clear];
            }
            if ((int) $e['group_id'] !== (int) $cal['group_id']) {
                return ['orphan', 'employee belongs to another group', null];
            }
            if ($b === null) {
                return ['orphan', 'no birthday row for that year', null];
            }
            return ['orphan', $dup ?? 'employee has no birthday on file', null];
    }
    return ['orphan', 'unrecognised key', null];
}

// --- planner (SPEC 14.3 steps 1-3) ---------------------------------------------------------------------

/**
 * Build the work lists for one calendar. $remote = the full listing (reconcile) or null (incremental).
 * Returns the six plan lists (items carry '_d' / '_clear' / '_lookup' internals) plus 'touch',
 * 'desired_count', 'mirrored_count', 'audit_cursor'.
 */
function sync_plan(array $cal, string $mode, ?array $remote): array
{
    $desired = sync_desired($cal);
    $work = sync_empty_plan() + ['touch' => [], 'desired_count' => count($desired), 'mirrored_count' => 0, 'audit_cursor' => 0];
    foreach ($desired as $d) {
        if ($d['google_event_id'] !== null) {
            $work['mirrored_count']++;
        }
    }

    // Step 1: departed cleanup (both modes, exempt from the delete guard).
    $departedIds = [];
    foreach (sync_departed_rows($cal) as $d) {
        $work['departed_cleanup'][] = sync_plan_item($d, 'employee departed', $d['google_event_id']);
        $departedIds[(string) $d['google_event_id']] = true;
    }

    if ($mode === 'incremental') {
        // Step 2: NULL id -> insert (after a lookup by key); changed fingerprint -> patch.
        foreach ($desired as $d) {
            if ($d['google_event_id'] === null) {
                $item = sync_plan_item($d, 'no Google id yet', null);
                $item['_lookup'] = true;
                $work['inserts'][] = $item;
            } elseif ($d['synced_fingerprint'] !== $d['fingerprint']) {
                $work['patches'][] = sync_plan_item($d, 'changed since last sync', $d['google_event_id']);
            }
        }
        [$dels, $cursor] = sync_audited_deletes($cal);
        $work['deletes'] = $dels;
        $work['audit_cursor'] = $cursor;
        return $work;
    }

    // Step 3: reconcile against one full listing.
    $byId = [];
    $byKey = [];
    foreach ($remote ?? [] as $ev) {
        $n = sync_remote_norm($ev);
        if ($n['lsp'] === null) {
            $work['unmanaged'][] = sync_remote_item($n, 'no lsp property');        // 3d
            continue;
        }
        $byId[$n['id']] = $n;
        $byKey[$n['lsp']][] = $n['id'];
    }
    $claimed = [];
    foreach ($desired as $key => $d) {
        $match = null;
        $gid = $d['google_event_id'];
        if ($gid !== null && isset($byId[$gid]) && $byId[$gid]['lsp'] === $key) {
            $match = $byId[$gid];
        } else {
            foreach ($byKey[$key] ?? [] as $id) {
                if (!isset($claimed[$id])) {
                    $match = $byId[$id];
                    break;
                }
            }
        }
        if ($match === null) {                                                     // 3b
            $work['inserts'][] = sync_plan_item($d, $gid === null ? 'not on the calendar' : 'missing from the calendar (stale id)', null);
            continue;
        }
        $claimed[$match['id']] = true;
        $differs = $match['title'] !== $d['title'] || $match['start'] !== $d['start'] || $match['end'] !== $d['end'];
        if ($differs) {                                                            // 3c
            $work['patches'][] = sync_plan_item($d, $gid === $match['id'] ? 'differs from the database' : 'recovered by key; differs from the database', $match['id']);
        } elseif ($gid !== $match['id'] || $d['synced_fingerprint'] !== $d['fingerprint'] || $d['sync_error'] !== null) {
            $work['touch'][] = ['_d' => $d, 'google_event_id' => $match['id']];     // local bookkeeping only
        }
    }
    foreach ($byId as $id => $n) {                                                 // 3a
        if (isset($claimed[$id]) || isset($departedIds[$id])) {
            continue;
        }
        [$verdict, $reason, $clear] = sync_classify_remote($cal, $n, $desired);
        $item = sync_remote_item($n, $reason);
        if ($verdict === 'delete') {
            $item['_clear'] = $clear;
            $work['deletes'][] = $item;
        } else {
            $work['orphaned'][] = $item;
        }
    }
    return $work;
}

/** SPEC 14.3 step 4 guards. Returns the abort reason or null. $force overrides the delete guard only. */
function sync_guard(array $cal, array $work, bool $force): ?string
{
    if (in_array($cal['kind'], ['pto', 'birthdays'], true) && $work['desired_count'] === 0) {
        return 'the desired set for this ' . $cal['kind'] . ' calendar is empty; refusing to sync (check employees / birthday rows)';
    }
    $n = count($work['deletes']);
    if ($n === 0 || $force) {
        return null;
    }
    $mirrored = (int) $work['mirrored_count'];
    if ($n > SYNC_DELETE_GUARD_MAX) {
        return "$n deletes planned (limit " . SYNC_DELETE_GUARD_MAX . '); use Force after reviewing the plan';
    }
    if ($n >= SYNC_DELETE_GUARD_FLOOR && $n > SYNC_DELETE_GUARD_RATIO * $mirrored) {
        return "$n deletes planned against $mirrored mirrored rows (limit 20%); use Force after reviewing the plan";
    }
    return null;
}

// --- executor (SPEC 14.3 step 5) -----------------------------------------------------------------------

/** Past the inline-sync deadline? */
function sync_deadline_hit(array $opts): bool
{
    return $opts['deadline'] !== null && microtime(true) >= (float) $opts['deadline'];
}

/** Store a per-row failure (SPEC 14.3 step 5: the run continues). */
function sync_row_fail(array &$ex, ?array $d, string $what, Throwable $e): void
{
    $msg = mb_substr($what . ': ' . $e->getMessage(), 0, 300);
    $ex['failed']++;
    $ex['errors'][] = $msg;
    if ($d !== null) {
        sync_row_set($d, ['sync_error' => $msg]);
    }
}

/** Inserts, then patches, then deletes, updating each row after each call. */
function sync_execute(array $cal, array &$work, array $opts): array
{
    $calId = (string) $cal['google_calendar_id'];
    $explicit = (bool) ($opts['force'] ?? false);   // Force is an explicit write (SPEC 14.1): writes even when sync_mode is dry_run
    $ex = ['inserts' => 0, 'patches' => 0, 'deletes' => 0, 'departed_cleanup' => 0, 'touched' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [], 'needs_pass' => false];

    // Step 1: departed cleanup.
    foreach ($work['departed_cleanup'] as $item) {
        if (sync_deadline_hit($opts)) {
            $ex['skipped']++;
            continue;
        }
        $d = $item['_d'];
        try {
            google_delete_event($calId, (string) $d['google_event_id'], $explicit);
            if ($d['table'] === 'birthday_events') {
                q('DELETE FROM birthday_events WHERE employee_id = ? AND year = ?', [(int) $d['employee_id'], (int) $d['year']]);
            } else {
                sync_row_set($d, ['google_event_id' => null, 'synced_fingerprint' => null, 'sync_error' => null]);
            }
            $ex['departed_cleanup']++;
        } catch (Throwable $e) {
            sync_row_fail($ex, $d, 'delete ' . $d['key'], $e);
        }
    }
    if ($cal['kind'] === 'birthdays') {
        // Departed employees' birthday rows without a Google id have nothing to delete remotely: drop them.
        q('DELETE b FROM birthday_events b JOIN employees e ON e.id = b.employee_id WHERE e.group_id = ? AND e.status = ? AND b.google_event_id IS NULL', [(int) $cal['group_id'], 'departed']);
    }

    // Local bookkeeping for rows that already match remotely (reconcile).
    foreach ($work['touch'] as $t) {
        $d = $t['_d'];
        sync_row_set($d, ['google_event_id' => $t['google_event_id'], 'synced_fingerprint' => $d['fingerprint'], 'sync_error' => null]);
        $ex['touched']++;
    }

    // Inserts (incremental: look the key up first to recover a lost insert response).
    foreach ($work['inserts'] as $item) {
        if (sync_deadline_hit($opts)) {
            $ex['skipped']++;
            continue;
        }
        $d = $item['_d'];
        $body = google_event_body($d['title'], $d['start'], $d['end'], $d['key'], $d['description']);
        try {
            $gid = null;
            if (!empty($item['_lookup'])) {
                $found = google_list_events($calId, $d['key']);
                if ($found !== []) {
                    $n = sync_remote_norm($found[0]);
                    $gid = $n['id'];
                    if ($n['title'] !== $d['title'] || $n['start'] !== $d['start'] || $n['end'] !== $d['end']) {
                        google_patch_event($calId, $gid, $body, $explicit);
                    }
                }
            }
            if ($gid === null) {
                $created = google_insert_event($calId, $body, $explicit);
                $gid = (string) ($created['id'] ?? '');
                if ($gid === '') {
                    throw new GoogleApiError(0, 'no_id', 'insert returned no event id');
                }
            }
            sync_row_set($d, ['google_event_id' => $gid, 'synced_fingerprint' => $d['fingerprint'], 'sync_error' => null]);
            $ex['inserts']++;
        } catch (Throwable $e) {
            sync_row_fail($ex, $d, 'insert ' . $d['key'], $e);
        }
    }

    // Patches (404 -> clear the id; the next pass re-inserts).
    foreach ($work['patches'] as $item) {
        if (sync_deadline_hit($opts)) {
            $ex['skipped']++;
            continue;
        }
        $d = $item['_d'];
        $gid = (string) $item['google_event_id'];
        try {
            google_patch_event($calId, $gid, google_event_body($d['title'], $d['start'], $d['end'], $d['key'], $d['description']), $explicit);
            sync_row_set($d, ['google_event_id' => $gid, 'synced_fingerprint' => $d['fingerprint'], 'sync_error' => null]);
            $ex['patches']++;
        } catch (GoogleApiError $e) {
            if ($e->status === 404 || $e->status === 410) {
                sync_row_set($d, ['google_event_id' => null, 'synced_fingerprint' => null, 'sync_error' => null]);
                $ex['needs_pass'] = true;
                $ex['errors'][] = 'patch ' . $d['key'] . ': event gone (404); will re-insert on the next pass';
                continue;
            }
            sync_row_fail($ex, $d, 'patch ' . $d['key'], $e);
        } catch (Throwable $e) {
            sync_row_fail($ex, $d, 'patch ' . $d['key'], $e);
        }
    }

    // Deletes (remote events no live row owns; 404 = done).
    foreach ($work['deletes'] as $item) {
        if (sync_deadline_hit($opts)) {
            $ex['skipped']++;
            continue;
        }
        $clear = $item['_clear'] ?? null;
        try {
            google_delete_event($calId, (string) $item['google_event_id'], $explicit);
            if ($clear !== null) {
                sync_row_set($clear, ['google_event_id' => null, 'synced_fingerprint' => null, 'sync_error' => null]);
                if (!empty($clear['_dirty'])) {
                    mark_dirty((string) $clear['_dirty']);
                }
            }
            $ex['deletes']++;
        } catch (Throwable $e) {
            sync_row_fail($ex, $clear, 'delete ' . (string) $item['key'] . ' (' . (string) $item['google_event_id'] . ')', $e);
        }
    }
    return $ex;
}

/** Why a run is a dry run: "environment is local, not production" / "sync_mode is dry_run" (SPEC 14.1 write guard). */
function sync_dry_run_reason(): string
{
    $env = (string) config('environment', 'local');
    if ($env !== 'production') {
        return "environment is $env, not production";
    }
    return 'sync_mode is dry_run';
}

/** Counts for the log line and messages. */
function sync_counts_line(array $plan, ?array $ex = null): string
{
    if ($ex !== null) {
        return sprintf('inserts=%d patches=%d deletes=%d unmanaged=%d orphaned=%d departed=%d', $ex['inserts'], $ex['patches'], $ex['deletes'], count($plan['unmanaged']), count($plan['orphaned']), $ex['departed_cleanup'])
            . ($ex['touched'] > 0 ? ' touched=' . $ex['touched'] : '')
            . ($ex['failed'] > 0 ? ' failed=' . $ex['failed'] : '')
            . ($ex['skipped'] > 0 ? ' skipped=' . $ex['skipped'] : '');
    }
    return sprintf('inserts=%d patches=%d deletes=%d unmanaged=%d orphaned=%d departed=%d', count($plan['inserts']), count($plan['patches']), count($plan['deletes']), count($plan['unmanaged']), count($plan['orphaned']), count($plan['departed_cleanup']));
}

/**
 * SPEC 14.3: one sync run for a calendar.
 * $mode 'incremental' | 'reconcile'; $opts: dry_run (preview: plan only, no state change), force (delete guard
 * off, explicit write), trigger (log tag), deadline (microtime; the inline hook's budget).
 * Returns ['ok','plan','executed','message','aborted', 'dry_run','mode','cal_key','seconds'].
 */
function sync_calendar(string $calKey, string $mode, array $opts = []): array
{
    if (!in_array($mode, SYNC_MODES, true)) {
        throw new InvalidArgumentException("Unknown sync mode '$mode'.");
    }
    $opts += ['dry_run' => false, 'force' => false, 'trigger' => 'manual', 'deadline' => null];
    $t0 = microtime(true);
    $startedAt = now_str();
    $cal = sync_calendar_row($calKey);
    $preview = (bool) $opts['dry_run'];
    $writes = !$preview && google_writes_allowed((bool) $opts['force']);
    $result = ['ok' => false, 'plan' => sync_empty_plan(), 'executed' => [], 'message' => '', 'aborted' => null,
        'dry_run' => !$writes, 'mode' => $mode, 'cal_key' => $calKey, 'seconds' => 0.0];

    if (($cal['google_calendar_id'] ?? '') === '' || $cal['google_calendar_id'] === null) {
        $result['aborted'] = 'no Google calendar id configured';
    } elseif (!$preview && ((int) $cal['is_active'] !== 1 || (int) $cal['sync_enabled'] !== 1)) {
        $result['aborted'] = 'calendar is inactive or sync is disabled';
    }
    if ($result['aborted'] !== null) {
        $result['message'] = 'aborted: ' . $result['aborted'];
        return $result;
    }

    // Lock: skip when another process is syncing this calendar (GET_LOCK is per connection, reentrant).
    $lockName = 'pto_sync_' . $calKey;
    if ((int) col('SELECT GET_LOCK(?, 0)', [$lockName]) !== 1) {
        $result['aborted'] = 'locked';
        $result['message'] = 'another sync is already running for this calendar';
        return $result;
    }
    $work = null;
    $ex = null;
    try {
        // Every app delete audited up to here is either in the listing (handled by step 3a) or already gone,
        // so a complete reconcile may advance the incremental audit cursor to this id.
        $auditMax = (int) col('SELECT COALESCE(MAX(id), 0) FROM audit_log');
        // Without a key file (local machine, fresh server) a dry run still shows the full plan, computed against
        // an empty listing; a run that would write aborts instead of failing row by row.
        $configured = google_key() !== null;
        if (!$configured && $writes) {
            throw new GoogleApiError(0, 'no_key', 'Google is not configured (no service account key file)');
        }
        $remote = $mode === 'reconcile' ? ($configured ? google_list_events((string) $cal['google_calendar_id']) : []) : null;
        $work = sync_plan($cal, $mode, $remote);
        if ($mode === 'reconcile') {
            $work['audit_cursor'] = $auditMax;
        }
        $result['plan'] = sync_plan_public($work);
        $abort = sync_guard($cal, $work, (bool) $opts['force']);
        if ($abort !== null) {
            $result['aborted'] = $abort;
            $result['message'] = 'aborted: ' . $abort;
        } elseif (!$writes) {
            $result['ok'] = true;
            $result['message'] = 'dry run' . ($preview ? '' : ' (' . sync_dry_run_reason() . ', nothing written)') . ': ' . sync_counts_line($result['plan'])
                . ($configured ? '' : ' (Google not configured: planned against an empty calendar)');
        } else {
            $ex = sync_execute($cal, $work, $opts);
            $result['executed'] = $ex;
            $result['ok'] = $ex['failed'] === 0;
            $status = $ex['failed'] > 0 ? 'partial' : 'ok';
            $result['message'] = $status . ': ' . sync_counts_line($result['plan'], $ex);
            if ($ex['errors'] !== []) {
                $result['message'] .= '; ' . implode('; ', array_slice($ex['errors'], 0, 3));
            }
        }
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['message'] = 'failed: ' . $e->getMessage();
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
    $result['seconds'] = round(microtime(true) - $t0, 1);

    // Record the run (not for previews), then the log line.
    $complete = $result['ok'] && $writes && $ex !== null && !$ex['needs_pass'] && $ex['skipped'] === 0;
    if (!$preview) {
        $data = ['last_sync_at' => $startedAt, 'last_sync_ok' => $result['ok'] ? 1 : 0, 'last_sync_message' => mb_substr($result['message'], 0, 300)];
        if ($complete) {
            $data['dirty'] = 0;
        }
        update_row('calendars', $data, 'cal_key = ?', [$calKey]);
        if ($complete) {
            setting_set('dirty_since_' . $calKey, null);
            $cursor = $work === null ? 0 : (int) $work['audit_cursor'];
            if ($cursor > (int) (setting('sync_audit_cursor_' . $calKey) ?? '0')) {
                setting_set('sync_audit_cursor_' . $calKey, (string) $cursor);
            }
        }
        if (function_exists('alert_note_sync_result')) {
            alert_note_sync_result($calKey, $result['ok'], $result['message']);
        }
    }
    $tag = $result['aborted'] !== null ? 'aborted' : ($writes ? ($result['ok'] ? 'ok' : 'partial') : 'dry-run');
    $line = sprintf('%s %s %s %s (%.1fs) [%s]', $calKey, $mode, $tag,
        $ex !== null ? sync_counts_line($result['plan'], $ex) : sync_counts_line($result['plan']), $result['seconds'], $opts['trigger']);
    if ($result['aborted'] !== null || (!$result['ok'] && $ex === null)) {
        $line .= ' ' . $result['message'];
    }
    sync_log($line);
    return $result;
}

// --- hooks (SPEC 14.3) -----------------------------------------------------------------------------------

/** Flag a calendar for the next incremental pass; remembers since when in settings dirty_since_<cal_key>. */
function mark_dirty(string $calKey): void
{
    $was = col('SELECT dirty FROM calendars WHERE cal_key = ?', [$calKey]);
    if ($was === null) {
        return;
    }
    q('UPDATE calendars SET dirty = 1 WHERE cal_key = ?', [$calKey]);
    if ((int) $was !== 1 || setting('dirty_since_' . $calKey) === null) {
        setting_set('dirty_since_' . $calKey, now_str());
    }
}

/**
 * Run incremental passes on the group's dirty calendars within $budgetSeconds; returns the flash text:
 * "Calendar updated." / "Calendar will update within 15 minutes." / "Calendar sync is in dry-run mode." /
 * "Calendar sync is not configured." Never throws (the screen's own save already succeeded).
 */
function sync_dirty_inline(int $groupId, float $budgetSeconds = 10.0): string
{
    $cals = rows('SELECT cal_key FROM calendars WHERE group_id = ? AND is_active = 1 AND sync_enabled = 1 AND dirty = 1 ORDER BY sort_order', [$groupId]);
    if ($cals === []) {
        return '';
    }
    if (google_key() === null) {
        return 'Calendar sync is not configured.';
    }
    if (!google_writes_allowed()) {
        return 'Calendar sync is in dry-run mode.';
    }
    $deadline = microtime(true) + $budgetSeconds;
    $pending = false;
    foreach ($cals as $c) {
        if (microtime(true) + 1.0 >= $deadline) {
            $pending = true;
            break;
        }
        try {
            $r = sync_calendar((string) $c['cal_key'], 'incremental', ['trigger' => 'inline', 'deadline' => $deadline]);
            $done = $r['ok'] && $r['aborted'] === null && ($r['executed']['skipped'] ?? 0) === 0 && !($r['executed']['needs_pass'] ?? false);
            if (!$done) {
                $pending = true;
            }
        } catch (Throwable $e) {
            error_log('sync_dirty_inline ' . $c['cal_key'] . ': ' . $e->getMessage());
            $pending = true;
        }
    }
    return $pending ? 'Calendar will update within 15 minutes.' : 'Calendar updated.';
}

/**
 * Mark as departed (SPEC 14.3): delete every calendar event of the employee by stored id (pto rows and birthday
 * rows), clear the ids, delete the employee's birthday_events rows. Leftovers are finished by the departed
 * cleanup step of later runs. Returns ['deleted','failed','message'].
 */
function sync_depart_employee(int $employeeId): array
{
    $emp = row('SELECT * FROM employees WHERE id = ?', [$employeeId]);
    if ($emp === null) {
        return ['deleted' => 0, 'failed' => 0, 'message' => 'Unknown employee.'];
    }
    $groupId = (int) $emp['group_id'];
    $pto = row('SELECT * FROM calendars WHERE group_id = ? AND kind = ?', [$groupId, 'pto']);
    $bd = row('SELECT * FROM calendars WHERE group_id = ? AND kind = ?', [$groupId, 'birthdays']);
    $withIds = (int) col('SELECT COUNT(*) FROM time_off WHERE employee_id = ? AND google_event_id IS NOT NULL', [$employeeId])
        + (int) col('SELECT COUNT(*) FROM birthday_events WHERE employee_id = ? AND google_event_id IS NOT NULL', [$employeeId]);
    // Birthday rows with no Google id have nothing on the calendar: drop them now in every environment.
    q('DELETE FROM birthday_events WHERE employee_id = ? AND google_event_id IS NULL', [$employeeId]);
    if (!google_writes_allowed()) {
        $msg = $withIds > 0
            ? "Calendar sync is in dry-run mode: $withIds calendar event(s) will be removed by the next live sync."
            : 'No calendar events to remove.';
        return ['deleted' => 0, 'failed' => 0, 'message' => $msg];
    }
    $deleted = 0;
    $failed = 0;
    $errors = [];
    if ($pto !== null) {
        foreach (rows('SELECT id, google_event_id FROM time_off WHERE employee_id = ? AND google_event_id IS NOT NULL', [$employeeId]) as $r) {
            try {
                google_delete_event((string) $pto['google_calendar_id'], (string) $r['google_event_id']);
                update_row('time_off', ['google_event_id' => null, 'synced_fingerprint' => null, 'sync_error' => null], 'id = ?', [(int) $r['id']]);
                $deleted++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
                update_row('time_off', ['sync_error' => mb_substr('delete: ' . $e->getMessage(), 0, 300)], 'id = ?', [(int) $r['id']]);
            }
        }
    }
    if ($bd !== null) {
        foreach (rows('SELECT year, google_event_id FROM birthday_events WHERE employee_id = ? AND google_event_id IS NOT NULL', [$employeeId]) as $r) {
            try {
                google_delete_event((string) $bd['google_calendar_id'], (string) $r['google_event_id']);
                q('DELETE FROM birthday_events WHERE employee_id = ? AND year = ?', [$employeeId, (int) $r['year']]);
                $deleted++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = $e->getMessage();
                update_row('birthday_events', ['sync_error' => mb_substr('delete: ' . $e->getMessage(), 0, 300)], 'employee_id = ? AND year = ?', [$employeeId, (int) $r['year']]);
            }
        }
    }
    $msg = "Removed $deleted calendar event(s) for {$emp['name']}" . ($failed > 0 ? "; $failed failed (the next sync retries): " . implode('; ', array_slice($errors, 0, 2)) : '') . '.';
    audit('sync', 'employees', $employeeId, $employeeId, $groupId, null, ['deleted' => $deleted, 'failed' => $failed], $msg);
    sync_log(sprintf('depart employee=%d deleted=%d failed=%d', $employeeId, $deleted, $failed));
    return ['deleted' => $deleted, 'failed' => $failed, 'message' => $msg];
}

/**
 * SPEC 14.2: birthday_events rows for Y and Y+1 (Y = the group's today) for every active employee with a
 * month and day. Marks the birthday calendar dirty when it added rows. Returns the number of rows added.
 */
function birthday_rows_topup(int $groupId): int
{
    $g = group_by_id($groupId);
    if ($g === null) {
        return 0;
    }
    $y = (int) group_today($g)->format('Y');
    $have = [];
    foreach (rows('SELECT b.employee_id, b.year FROM birthday_events b JOIN employees e ON e.id = b.employee_id WHERE e.group_id = ?', [$groupId]) as $r) {
        $have[(int) $r['employee_id'] . ':' . (int) $r['year']] = true;
    }
    $n = 0;
    $emps = rows('SELECT id FROM employees WHERE group_id = ? AND status = ? AND birth_month IS NOT NULL AND birth_day IS NOT NULL', [$groupId, 'active']);
    foreach ($emps as $e) {
        foreach ([$y, $y + 1] as $year) {
            if (!isset($have[(int) $e['id'] . ':' . $year])) {
                insert('birthday_events', ['employee_id' => (int) $e['id'], 'year' => $year]);
                $n++;
            }
        }
    }
    if ($n > 0) {
        $bd = row('SELECT cal_key FROM calendars WHERE group_id = ? AND kind = ?', [$groupId, 'birthdays']);
        if ($bd !== null) {
            mark_dirty((string) $bd['cal_key']);
        }
    }
    return $n;
}

// --- admin tools ------------------------------------------------------------------------------------------

/** Forget every Google id / fingerprint / error (optionally one group) and the calendars' last-run state. Audited. */
function sync_reset_state(?int $groupId = null): void
{
    tx(static function () use ($groupId): void {
        if ($groupId === null) {
            q('UPDATE time_off SET google_event_id = NULL, synced_fingerprint = NULL, sync_error = NULL');
            q('UPDATE events SET google_event_id = NULL, synced_fingerprint = NULL, sync_error = NULL');
            q('UPDATE birthday_events SET google_event_id = NULL, synced_fingerprint = NULL, sync_error = NULL');
            $cals = rows('SELECT cal_key FROM calendars');
        } else {
            q('UPDATE time_off t JOIN employees e ON e.id = t.employee_id SET t.google_event_id = NULL, t.synced_fingerprint = NULL, t.sync_error = NULL WHERE e.group_id = ?', [$groupId]);
            q('UPDATE events ev JOIN calendars c ON c.cal_key = ev.cal_key SET ev.google_event_id = NULL, ev.synced_fingerprint = NULL, ev.sync_error = NULL WHERE c.group_id = ?', [$groupId]);
            q('UPDATE birthday_events b JOIN employees e ON e.id = b.employee_id SET b.google_event_id = NULL, b.synced_fingerprint = NULL, b.sync_error = NULL WHERE e.group_id = ?', [$groupId]);
            $cals = rows('SELECT cal_key FROM calendars WHERE group_id = ?', [$groupId]);
        }
        foreach ($cals as $c) {
            update_row('calendars', ['last_sync_at' => null, 'last_sync_ok' => null, 'last_sync_message' => null, 'dirty' => 0], 'cal_key = ?', [$c['cal_key']]);
            setting_set('dirty_since_' . $c['cal_key'], null);
            setting_set('sync_audit_cursor_' . $c['cal_key'], null);
        }
        audit('sync', 'calendars', null, null, $groupId, null, null, 'Sync state reset' . ($groupId === null ? ' (all groups)' : " (group $groupId)"));
    });
    sync_log('reset' . ($groupId === null ? ' all' : " group=$groupId"));
}

/** Remote events without an lsp property: [['id','title','start','end'], ...]. */
function sync_unmanaged(string $calKey): array
{
    $cal = sync_calendar_row($calKey);
    $out = [];
    foreach (google_list_events((string) $cal['google_calendar_id']) as $ev) {
        $n = sync_remote_norm($ev);
        if ($n['lsp'] === null) {
            $out[] = ['id' => $n['id'], 'title' => $n['title'], 'start' => $n['start'], 'end' => $n['end']];
        }
    }
    return $out;
}

/**
 * SPEC 14.3 adopt (pto and events calendars only): match remote events without lsp to desired rows by exact
 * fingerprint, then by same dates + case-insensitive trimmed title. Refuses when any row already has an id.
 * $commit stores the id and PATCHes lsp + the canonical title (explicit write).
 * Returns ['matched'=>[], 'unmatched_remote'=>[], 'unmatched_rows'=>[], 'refused'=>?string, 'committed'=>bool].
 */
function sync_adopt(string $calKey, bool $commit): array
{
    $cal = sync_calendar_row($calKey);
    $res = ['matched' => [], 'unmatched_remote' => [], 'unmatched_rows' => [], 'refused' => null, 'committed' => false];
    if (!in_array($cal['kind'], ['pto', 'events'], true)) {
        $res['refused'] = 'adoption applies to PTO and events calendars only';
        return $res;
    }
    $desired = sync_desired($cal);
    foreach ($desired as $d) {
        if ($d['google_event_id'] !== null) {
            $res['refused'] = 'some rows of this calendar already have a Google id; reset the sync state first if you really want to re-adopt';
            return $res;
        }
    }
    $remote = [];
    foreach (google_list_events((string) $cal['google_calendar_id']) as $ev) {
        $n = sync_remote_norm($ev);
        if ($n['lsp'] === null) {
            $remote[$n['id']] = $n;
        }
    }
    $byFp = [];
    $byLoose = [];
    foreach ($remote as $id => $n) {
        $byFp[sync_fingerprint($n['title'], $n['start'], $n['end'])][] = $id;
        $byLoose[mb_strtolower(trim($n['title'])) . '|' . $n['start'] . '|' . $n['end']][] = $id;
    }
    $taken = [];
    $pairs = [];
    foreach ($desired as $d) {                       // pass 1: exact fingerprint
        foreach ($byFp[$d['fingerprint']] ?? [] as $id) {
            if (!isset($taken[$id])) {
                $taken[$id] = true;
                $pairs[$d['key']] = $id;
                break;
            }
        }
    }
    foreach ($desired as $d) {                       // pass 2: same dates, case-insensitive trimmed title
        if (isset($pairs[$d['key']])) {
            continue;
        }
        foreach ($byLoose[mb_strtolower(trim($d['title'])) . '|' . $d['start'] . '|' . $d['end']] ?? [] as $id) {
            if (!isset($taken[$id])) {
                $taken[$id] = true;
                $pairs[$d['key']] = $id;
                break;
            }
        }
    }
    foreach ($desired as $d) {
        if (isset($pairs[$d['key']])) {
            $n = $remote[$pairs[$d['key']]];
            $res['matched'][] = ['key' => $d['key'], 'title' => $d['title'], 'start' => $d['start'], 'end' => $d['end'], 'google_event_id' => $n['id'], 'remote_title' => $n['title'], 'reason' => $n['title'] === $d['title'] ? 'exact' : 'case-insensitive title'];
        } else {
            $res['unmatched_rows'][] = ['key' => $d['key'], 'title' => $d['title'], 'start' => $d['start'], 'end' => $d['end'], 'google_event_id' => null, 'reason' => 'no remote match (will be inserted by the next sync)'];
        }
    }
    foreach ($remote as $id => $n) {
        if (!isset($taken[$id])) {
            $res['unmatched_remote'][] = ['key' => null, 'title' => $n['title'], 'start' => $n['start'], 'end' => $n['end'], 'google_event_id' => $id, 'reason' => 'no matching row'];
        }
    }
    if (!$commit) {
        return $res;
    }
    if (!google_writes_allowed(true)) {
        $res['refused'] = 'Google writes are not allowed in this environment';
        return $res;
    }
    $n = 0;
    $failed = 0;
    foreach ($res['matched'] as $i => $m) {
        $d = $desired[$m['key']];
        try {
            google_patch_event((string) $cal['google_calendar_id'], (string) $m['google_event_id'], ['summary' => $d['title'], 'extendedProperties' => ['private' => ['lsp' => $d['key']]]], true);
            sync_row_set($d, ['google_event_id' => $m['google_event_id'], 'synced_fingerprint' => $d['fingerprint'], 'sync_error' => null]);
            $n++;
        } catch (Throwable $e) {
            $failed++;
            $res['matched'][$i]['error'] = $e->getMessage();
            sync_row_set($d, ['sync_error' => mb_substr('adopt: ' . $e->getMessage(), 0, 300)]);
        }
    }
    $res['committed'] = true;
    audit('sync', 'calendars', null, null, (int) $cal['group_id'], null, ['matched' => $n, 'failed' => $failed], "$calKey: adopted $n existing calendar event(s)" . ($failed > 0 ? ", $failed failed" : ''));
    sync_log(sprintf('%s adopt matched=%d failed=%d unmatched_remote=%d unmatched_rows=%d', $calKey, $n, $failed, count($res['unmatched_remote']), count($res['unmatched_rows'])));
    return $res;
}

/** Adopt one remote event as one desired row ('time_off:12', 'event:5', 'birthday:3:2026'). Explicit write, audited. */
function sync_adopt_one(string $calKey, string $googleEventId, string $rowKey): bool
{
    $cal = sync_calendar_row($calKey);
    $desired = sync_desired($cal);
    $d = $desired[$rowKey] ?? null;
    if ($d === null || !google_writes_allowed(true)) {
        return false;
    }
    try {
        google_patch_event((string) $cal['google_calendar_id'], $googleEventId, google_event_body($d['title'], $d['start'], $d['end'], $d['key'], $d['description']), true);
        sync_row_set($d, ['google_event_id' => $googleEventId, 'synced_fingerprint' => $d['fingerprint'], 'sync_error' => null]);
    } catch (Throwable $e) {
        sync_log("$calKey adopt-one $rowKey <- $googleEventId failed: " . $e->getMessage());
        return false;
    }
    audit('sync', $d['table'], $d['row_id'], $d['employee_id'], (int) $cal['group_id'], null, ['google_event_id' => $googleEventId], "$calKey: adopted calendar event as $rowKey ({$d['title']})");
    sync_log("$calKey adopt-one $rowKey <- $googleEventId ok");
    return true;
}

/** Explicitly delete an unmanaged / orphaned remote event; clears any row holding that id. Explicit write, audited. */
function sync_delete_remote(string $calKey, string $googleEventId): bool
{
    $cal = sync_calendar_row($calKey);
    if (!google_writes_allowed(true)) {
        return false;
    }
    try {
        google_delete_event((string) $cal['google_calendar_id'], $googleEventId, true);
    } catch (Throwable $e) {
        sync_log("$calKey delete-remote $googleEventId failed: " . $e->getMessage());
        return false;
    }
    foreach (['time_off', 'events', 'birthday_events'] as $t) {
        q("UPDATE `$t` SET google_event_id = NULL, synced_fingerprint = NULL WHERE google_event_id = ?", [$googleEventId]);
    }
    audit('sync', 'calendars', null, null, (int) $cal['group_id'], ['google_event_id' => $googleEventId], null, "$calKey: deleted calendar event $googleEventId (explicit)");
    sync_log("$calKey delete-remote $googleEventId ok");
    return true;
}

/**
 * SPEC 14.3: delete EVERY event on a birthday calendar, top the rows up, insert Y and Y+1 for active employees.
 * Explicit write. Returns ['deleted','inserted','failed','remote_count','would_insert','message','refused','committed'].
 */
function sync_wipe_regenerate_birthdays(string $calKey, bool $commit): array
{
    $cal = sync_calendar_row($calKey);
    $res = ['deleted' => 0, 'inserted' => 0, 'failed' => 0, 'remote_count' => 0, 'would_insert' => 0, 'message' => '', 'refused' => null, 'committed' => false];
    if ($cal['kind'] !== 'birthdays') {
        $res['refused'] = 'not a birthday calendar';
        $res['message'] = $res['refused'];
        return $res;
    }
    $calId = (string) $cal['google_calendar_id'];
    $remote = google_list_events($calId);
    $res['remote_count'] = count($remote);
    $active = (int) col('SELECT COUNT(*) FROM employees WHERE group_id = ? AND status = ? AND birth_month IS NOT NULL AND birth_day IS NOT NULL', [(int) $cal['group_id'], 'active']);
    $res['would_insert'] = $active * 2;
    if (!$commit) {
        $res['message'] = sprintf('would delete %d event(s) and insert %d birthday event(s) (this year and next)', count($remote), $res['would_insert']);
        return $res;
    }
    if (!google_writes_allowed(true)) {
        $res['refused'] = 'Google writes are not allowed in this environment';
        $res['message'] = $res['refused'];
        return $res;
    }
    foreach ($remote as $ev) {
        try {
            google_delete_event($calId, (string) $ev['id'], true);
            $res['deleted']++;
        } catch (Throwable $e) {
            $res['failed']++;
        }
    }
    // Every stored id on this calendar is now stale.
    q('UPDATE birthday_events b JOIN employees e ON e.id = b.employee_id SET b.google_event_id = NULL, b.synced_fingerprint = NULL, b.sync_error = NULL WHERE e.group_id = ?', [(int) $cal['group_id']]);
    q('DELETE b FROM birthday_events b JOIN employees e ON e.id = b.employee_id WHERE e.group_id = ? AND e.status = ?', [(int) $cal['group_id'], 'departed']);
    birthday_rows_topup((int) $cal['group_id']);
    $g = group_by_id((int) $cal['group_id']);
    $y = $g === null ? (int) date('Y') : (int) group_today($g)->format('Y');
    foreach (sync_desired($cal) as $d) {
        if ($d['year'] !== $y && $d['year'] !== $y + 1) {
            continue;                              // older rows re-insert only through a normal sync, if ever wanted
        }
        try {
            $created = google_insert_event($calId, google_event_body($d['title'], $d['start'], $d['end'], $d['key'], $d['description']), true);
            sync_row_set($d, ['google_event_id' => (string) $created['id'], 'synced_fingerprint' => $d['fingerprint'], 'sync_error' => null]);
            $res['inserted']++;
        } catch (Throwable $e) {
            $res['failed']++;
            sync_row_set($d, ['sync_error' => mb_substr('insert: ' . $e->getMessage(), 0, 300)]);
        }
    }
    $res['committed'] = true;
    $res['message'] = sprintf('deleted %d, inserted %d, failed %d', $res['deleted'], $res['inserted'], $res['failed']);
    update_row('calendars', ['last_sync_at' => now_str(), 'last_sync_ok' => $res['failed'] === 0 ? 1 : 0, 'last_sync_message' => 'wipe and regenerate: ' . $res['message'], 'dirty' => $res['failed'] === 0 ? 0 : 1], 'cal_key = ?', [$calKey]);
    if ($res['failed'] === 0) {
        setting_set('dirty_since_' . $calKey, null);
    }
    audit('sync', 'calendars', null, null, (int) $cal['group_id'], null, $res, "$calKey: birthdays wiped and regenerated (" . $res['message'] . ')');
    sync_log("$calKey wipe-regenerate " . $res['message']);
    return $res;
}

/**
 * SPEC 14.3 test connection: insert a throwaway all-day event on 2000-01-01 ("LSP connection test", lsp=probe)
 * and delete it; OK only if both succeed. Explicit write. Where writes are not allowed it lists events instead.
 */
function sync_test_connection(string $calKey): array
{
    $cal = sync_calendar_row($calKey);
    $calId = (string) ($cal['google_calendar_id'] ?? '');
    if ($calId === '') {
        return ['ok' => false, 'message' => 'No Google calendar id configured.'];
    }
    if (!google_writes_allowed(true)) {
        try {
            $n = count(google_list_events($calId));
            $r = ['ok' => true, 'message' => "Write test not available in local environment; read-only check OK ($n events listed)."];
        } catch (Throwable $e) {
            $r = ['ok' => false, 'message' => 'Write test not available in local environment; read-only check failed: ' . $e->getMessage()];
        }
        sync_log("$calKey test-connection " . ($r['ok'] ? 'ok' : 'failed') . ' (read-only)');
        return $r;
    }
    try {
        $created = google_insert_event($calId, google_event_body('LSP connection test', '2000-01-01', '2000-01-02', 'probe'), true);
        $id = (string) ($created['id'] ?? '');
        if ($id === '') {
            throw new GoogleApiError(0, 'no_id', 'insert returned no event id');
        }
        google_delete_event($calId, $id, true);
        $r = ['ok' => true, 'message' => 'Connection OK: a test event was inserted and deleted.'];
    } catch (Throwable $e) {
        $r = ['ok' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
    }
    sync_log("$calKey test-connection " . ($r['ok'] ? 'ok' : 'failed: ' . $r['message']));
    return $r;
}

/** Minutes between a 'Y-m-d H:i:s' timestamp and now (null when the timestamp is null). */
function sync_minutes_since(?string $ts): ?int
{
    if ($ts === null || $ts === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts);
    return $d === false ? null : max(0, (int) floor((time() - $d->getTimestamp()) / 60));
}

/**
 * SPEC 14.5 dashboard strip. ['state' => not_configured|dry_run|ok|stale|failed, 'summary' => text,
 * 'calendars' => [cal_key => [label, kind, is_active, sync_enabled, last_sync_at, last_sync_ok, last_sync_message,
 * dirty, dirty_since, minutes_dirty, minutes_since_sync, state]]].
 */
function sync_status_for_group(int $groupId): array
{
    $cals = [];
    $failing = [];
    $stale = [];
    $lastRun = null;
    $countActive = 0;
    foreach (group_calendars($groupId) as $c) {
        $enabled = (int) $c['is_active'] === 1 && (int) $c['sync_enabled'] === 1;
        $dirtySince = (int) $c['dirty'] === 1 ? setting('dirty_since_' . $c['cal_key']) : null;
        $minutesDirty = (int) $c['dirty'] === 1 ? (sync_minutes_since($dirtySince) ?? 0) : null;
        $state = 'ok';
        if (!$enabled) {
            $state = 'disabled';
        } elseif ($c['last_sync_ok'] !== null && (int) $c['last_sync_ok'] === 0) {
            $state = 'failed';
            $failing[] = $c['label'];
        } elseif ($minutesDirty !== null && $minutesDirty >= SYNC_STALE_MINUTES) {
            $state = 'stale';
            $stale[] = $c['label'];
        } elseif ((int) $c['dirty'] === 1) {
            $state = 'pending';
        }
        if ($enabled) {
            $countActive++;
            if ($c['last_sync_at'] !== null && ($lastRun === null || $c['last_sync_at'] > $lastRun)) {
                $lastRun = (string) $c['last_sync_at'];
            }
        }
        $cals[$c['cal_key']] = [
            'cal_key'            => $c['cal_key'],
            'label'              => $c['label'],
            'kind'               => $c['kind'],
            'is_active'          => (int) $c['is_active'],
            'sync_enabled'       => (int) $c['sync_enabled'],
            'last_sync_at'       => $c['last_sync_at'],
            'last_sync_ok'       => $c['last_sync_ok'] === null ? null : (int) $c['last_sync_ok'],
            'last_sync_message'  => $c['last_sync_message'],
            'minutes_since_sync' => sync_minutes_since($c['last_sync_at']),
            'dirty'              => (int) $c['dirty'],
            'dirty_since'        => $dirtySince,
            'minutes_dirty'      => $minutesDirty,
            'state'              => $state,
        ];
    }
    if (google_key() === null) {
        $state = 'not_configured';
        $summary = 'Google not configured (service account key file missing).';
    } elseif (!google_writes_allowed()) {
        $state = 'dry_run';
        $summary = config('environment', 'local') !== 'production'
            ? 'Calendar sync is in dry-run mode (not a production environment).'
            : 'Calendar sync is in dry-run mode.';
    } elseif ($failing !== []) {
        $state = 'failed';
        $summary = 'Calendar sync failed: ' . implode(', ', $failing) . '.';
    } elseif ($stale !== []) {
        $state = 'stale';
        $summary = 'Calendar changes pending for 30+ minutes: ' . implode(', ', $stale) . '.';
    } elseif ($lastRun === null) {
        $state = 'stale';
        $summary = 'Calendar sync has not run yet.';
    } else {
        $state = 'ok';
        $m = sync_minutes_since($lastRun) ?? 0;
        $ago = $m < 1 ? 'just now' : ($m < 120 ? "$m min ago" : ($m < 48 * 60 ? floor($m / 60) . ' h ago' : floor($m / 1440) . ' days ago'));
        $summary = "All $countActive calendars in sync, last run $ago.";
    }
    return ['state' => $state, 'summary' => $summary, 'calendars' => $cals];
}

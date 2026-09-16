<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * History (SPEC section 7.8): the append-only audit_log with filters (group, employee, table, user, date range).
 * Master admins only (an Admin gets a 403 and no nav link). before_json / after_json are shown as a readable
 * field-by-field diff. "Restore" re-inserts a deleted time_off or events row from its before_json (same id, the
 * Google-sync columns cleared) and writes a new 'restore' audit row, then marks the row's calendar dirty and runs the
 * inline sync pass (SPEC 14.3).
 *
 * Private helpers are prefixed history_ (candidates for lib promotion).
 */

$user = require_role('admin');
$groups = groups_all();   // id => row (a master admin sees every active group)

const HISTORY_PAGE_SIZE = 100;

/** Columns a restore may write, per table (everything else in before_json is ignored). */
const HISTORY_RESTORE_COLUMNS = [
    'time_off' => ['id', 'employee_id', 'kind', 'start_date', 'end_date', 'note', 'legacy_row',
                   'google_event_id', 'synced_fingerprint', 'sync_error', 'created_at', 'created_by', 'updated_at', 'updated_by'],
    'events'   => ['id', 'cal_key', 'title', 'start_date', 'end_date', 'is_holiday', 'legacy_row',
                   'google_event_id', 'synced_fingerprint', 'sync_error', 'created_at', 'created_by', 'updated_at', 'updated_by'],
];

// ------------------------------------------------------------------ helpers

/** Same value for diff purposes (DB rows give ints, JSON images may give strings). */
function history_same(mixed $a, mixed $b): bool
{
    if (is_scalar($a) && is_scalar($b)) {
        return (string) $a === (string) $b;
    }
    return json_encode($a) === json_encode($b);
}

/** One value of a before/after image as HTML; secrets are never shown. */
function history_val(string $key, mixed $v): string
{
    if (preg_match('/password|hash|token|secret/i', $key) === 1) {
        return $v === null ? '<span class="muted">-</span>' : '<span class="muted">(hidden)</span>';
    }
    if ($v === null || $v === '') {
        return '<span class="muted">-</span>';
    }
    if (is_bool($v)) {
        return $v ? 'yes' : 'no';
    }
    if (is_array($v)) {
        return '<code>' . h((string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</code>';
    }
    return h((string) $v);
}

/**
 * Readable diff of the before/after images: an update shows only the changed fields (before in red, after in
 * green) plus the list of unchanged ones; an insert or delete lists every field of the one image it has.
 */
function history_diff(?array $before, ?array $after): string
{
    if ($before === null && $after === null) {
        return '';
    }
    $mode = $before !== null && $after !== null ? 'update' : ($before !== null ? 'before' : 'after');
    $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
    $html = '<table class="diff"><thead><tr><th>Field</th>';
    $html .= $mode === 'update' ? '<th>Before</th><th>After</th>' : '<th>' . ($mode === 'before' ? 'Value before the delete' : 'Value') . '</th>';
    $html .= '</tr></thead><tbody>';
    $unchanged = [];
    $shown = 0;
    foreach ($keys as $k) {
        $k = (string) $k;
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if ($mode === 'update') {
            if (history_same($a, $b)) {
                $unchanged[] = $k;
                continue;
            }
            $html .= '<tr class="changed"><th>' . h($k) . '</th><td class="old">' . history_val($k, $b) . '</td><td class="new">' . history_val($k, $a) . '</td></tr>';
        } else {
            $html .= '<tr><th>' . h($k) . '</th><td>' . history_val($k, $mode === 'before' ? $b : $a) . '</td></tr>';
        }
        $shown++;
    }
    if ($shown === 0) {
        $html .= '<tr><td colspan="3" class="muted">No field changed.</td></tr>';
    }
    $html .= '</tbody></table>';
    if ($unchanged !== []) {
        $html .= '<p class="help">Unchanged: ' . h(implode(', ', $unchanged)) . '</p>';
    }
    return $html;
}

/** "time_off #12" with a link to the row's screen when it still exists. */
function history_row_ref(array $a, bool $exists): string
{
    if ($a['table_name'] === null) {
        return '<span class="muted">-</span>';
    }
    $label = (string) $a['table_name'] . ($a['row_id'] !== null ? ' #' . (string) $a['row_id'] : '');
    if ($a['row_id'] === null || !$exists) {
        return h($label);
    }
    $id = (int) $a['row_id'];
    $href = match ((string) $a['table_name']) {
        'time_off'  => app_url('request.php?id=' . $id),
        'employees' => app_url('employee.php?id=' . $id),
        'events'    => app_url('events.php?edit=' . $id),
        default     => null,
    };
    return $href === null ? h($label) : '<a href="' . h($href) . '">' . h($label) . '</a>';
}

/**
 * Restore a deleted time_off / events row from the before_json of audit row $auditId.
 * Returns [ok, message]. The parent rows (employee / calendar) must still exist; the id must be free (the row was
 * not restored already).
 */
function history_restore(int $auditId, array $user): array
{
    $a = row('SELECT * FROM audit_log WHERE id = ?', [$auditId]);
    if ($a === null || $a['action'] !== 'delete' || !isset(HISTORY_RESTORE_COLUMNS[(string) $a['table_name']])) {
        return [false, 'Only deleted time-off and event rows can be restored.'];
    }
    $table = (string) $a['table_name'];
    $before = json_decode((string) $a['before_json'], true);
    if (!is_array($before) || !isset($before['id'])) {
        return [false, 'This audit row has no before-image to restore from.'];
    }
    $data = array_intersect_key($before, array_flip(HISTORY_RESTORE_COLUMNS[$table]));
    $id = (int) $data['id'];
    $data['id'] = $id;

    if ($table === 'time_off') {
        foreach (['employee_id', 'kind', 'start_date', 'end_date'] as $k) {
            if (!isset($data[$k]) || $data[$k] === '') {
                return [false, 'The before-image is incomplete (missing ' . $k . ').'];
            }
        }
        $emp = row('SELECT * FROM employees WHERE id = ?', [(int) $data['employee_id']]);
        if ($emp === null) {
            return [false, 'The employee of this row no longer exists.'];
        }
        if (!user_can_group($user, (int) $emp['group_id'])) {
            return [false, 'You do not have access to that group.'];
        }
        $groupId = (int) $emp['group_id'];
        $employeeId = (int) $emp['id'];
        $summary = $emp['name'] . ': ' . $data['kind'] . ' ' . $data['start_date'] . '..' . $data['end_date'] . ' restored';
        $dirtyKeys = array_map(static fn(array $c): string => (string) $c['cal_key'], group_calendars($groupId, 'pto'));
    } else {
        foreach (['cal_key', 'title', 'start_date', 'end_date'] as $k) {
            if (!isset($data[$k]) || $data[$k] === '') {
                return [false, 'The before-image is incomplete (missing ' . $k . ').'];
            }
        }
        $cal = row('SELECT * FROM calendars WHERE cal_key = ?', [(string) $data['cal_key']]);
        if ($cal === null) {
            return [false, 'The calendar of this event no longer exists.'];
        }
        if (!user_can_group($user, (int) $cal['group_id'])) {
            return [false, 'You do not have access to that group.'];
        }
        $groupId = (int) $cal['group_id'];
        $employeeId = null;
        $summary = $cal['label'] . ': ' . $data['title'] . ' ' . $data['start_date'] . '..' . $data['end_date'] . ' restored';
        $dirtyKeys = [(string) $cal['cal_key']];
    }
    if (row("SELECT id FROM `$table` WHERE id = ?", [$id]) !== null) {
        return [false, ucfirst(str_replace('_', ' ', $table)) . ' row #' . $id . ' already exists (restored already?).'];
    }
    // The Google mirror of a deleted row is gone (Milestone 2 deletes it with the row), so the sync columns start clean.
    $data['google_event_id'] = null;
    $data['synced_fingerprint'] = null;
    $data['sync_error'] = null;
    $data['created_at'] = (string) ($data['created_at'] ?? now_str());
    $data['updated_at'] = now_str();
    $data['updated_by'] = (int) $user['id'];

    tx(static function () use ($table, $data, $id, $employeeId, $groupId, $summary, $auditId): void {
        insert($table, $data);
        $after = row("SELECT * FROM `$table` WHERE id = ?", [$id]);
        audit('restore', $table, $id, $employeeId, $groupId, null, $after, $summary . ' (from audit #' . $auditId . ')');
    });
    group_holidays_reset();   // a restored events row may be a company holiday
    // SPEC 14.3 hook: the restored row has no Google event yet; mark its calendar dirty and try the inline pass.
    foreach ($dirtyKeys as $k) {
        mark_dirty($k);
    }
    return [true, rtrim('Restored: ' . $summary . '. ' . sync_dirty_inline($groupId))];
}

/** <option> list from id => label pairs. */
function history_options(array $pairs, string $selected): string
{
    $html = '';
    foreach ($pairs as $val => $label) {
        $val = (string) $val;
        $html .= '<option value="' . h($val) . '"' . ($val === $selected ? ' selected' : '') . '>' . h((string) $label) . '</option>';
    }
    return $html;
}

// ------------------------------------------------------------------ filters (validated; anything odd becomes "any")

$f = [
    'group'    => (string) get('group', ''),
    'employee' => (string) get('employee', ''),
    'table'    => (string) get('table', ''),
    'user'     => (string) get('user', ''),
    'from'     => (string) get('from', ''),
    'to'       => (string) get('to', ''),
];
if ($f['group'] !== '' && !isset($groups[(int) $f['group']])) {
    $f['group'] = '';
}
if ($f['employee'] !== '' && !ctype_digit($f['employee'])) {
    $f['employee'] = '';
}
if ($f['table'] !== '' && preg_match('/^[a-z_]{1,30}$/', $f['table']) !== 1) {
    $f['table'] = '';
}
if ($f['user'] !== '' && $f['user'] !== 'system' && !ctype_digit($f['user'])) {
    $f['user'] = '';
}
foreach (['from', 'to'] as $k) {
    if ($f[$k] !== '' && to_date($f[$k]) === null) {
        $f[$k] = '';
    }
}
$page = max(1, (int) get('page', 1));
$qs = array_filter($f, static fn(string $v): bool => $v !== '');
$here = app_url('history.php') . ($qs === [] && $page === 1 ? '' : '?' . http_build_query($qs + ($page > 1 ? ['page' => $page] : [])));

// ------------------------------------------------------------------ POST: restore

if (is_post()) {
    if (post('action') === 'restore') {
        [$ok, $msg] = history_restore((int) post('audit_id', 0), $user);
        flash($ok ? 'ok' : 'err', $msg);
    } else {
        flash('err', 'Unknown action.');
    }
    redirect($here);
}

// ------------------------------------------------------------------ query

$where = [];
$p = [];
if ($f['group'] !== '') {
    $where[] = 'a.group_id = ?';
    $p[] = (int) $f['group'];
}
if ($f['employee'] !== '') {
    $where[] = 'a.employee_id = ?';
    $p[] = (int) $f['employee'];
}
if ($f['table'] !== '') {
    $where[] = 'a.table_name = ?';
    $p[] = $f['table'];
}
if ($f['user'] === 'system') {
    $where[] = 'a.user_id IS NULL';
} elseif ($f['user'] !== '') {
    $where[] = 'a.user_id = ?';
    $p[] = (int) $f['user'];
}
if ($f['from'] !== '') {
    $where[] = 'a.at >= ?';
    $p[] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
    $where[] = 'a.at < ?';
    $p[] = ymd(to_date($f['to'])->modify('+1 day')) . ' 00:00:00';
}
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$total = (int) col('SELECT COUNT(*) FROM audit_log a' . $whereSql, $p);
$offset = ($page - 1) * HISTORY_PAGE_SIZE;
// LIMIT/OFFSET are PHP ints formatted into the SQL (native prepares reject string-bound LIMIT values).
$sql = 'SELECT a.*, u.display_name AS user_name, e.name AS employee_name, g.name AS group_name
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN groups g ON g.id = a.group_id' . $whereSql . '
        ORDER BY a.id DESC' . sprintf(' LIMIT %d OFFSET %d', HISTORY_PAGE_SIZE, $offset);
$list = rows($sql, $p);

// Which referenced rows still exist (links, and whether a delete can be restored) - one query per table.
$exists = [];
$byTable = [];
foreach ($list as $a) {
    if ($a['table_name'] !== null && $a['row_id'] !== null && in_array($a['table_name'], ['time_off', 'events', 'employees'], true)) {
        $byTable[$a['table_name']][] = (int) $a['row_id'];
    }
}
foreach ($byTable as $table => $ids) {
    $ids = array_values(array_unique($ids));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    foreach (rows("SELECT id FROM `$table` WHERE id IN ($marks)", $ids) as $r) {
        $exists[$table . '#' . (int) $r['id']] = true;
    }
}

// Filter option lists.
$groupOptions = [];
foreach ($groups as $gid => $g) {
    $groupOptions[$gid] = $g['name'];
}
$empSql = 'SELECT e.id, e.name, e.status, g.name AS group_name FROM employees e JOIN groups g ON g.id = e.group_id';
$empP = [];
if ($f['group'] !== '') {
    $empSql .= ' WHERE e.group_id = ?';
    $empP[] = (int) $f['group'];
} elseif ($groups !== [] && count($groups) < (int) col('SELECT COUNT(*) FROM groups')) {
    $empSql .= ' WHERE e.group_id IN (' . implode(',', array_fill(0, count($groups), '?')) . ')';
    $empP = array_map('intval', array_keys($groups));
}
$empOptions = [];
foreach (rows($empSql . ' ORDER BY g.sort_order, e.name', $empP) as $e) {
    $empOptions[(int) $e['id']] = $e['name'] . ($f['group'] === '' ? ' (' . $e['group_name'] . ')' : '') . ($e['status'] === 'departed' ? ' - former' : '');
}
$tableOptions = [];
foreach (rows('SELECT DISTINCT table_name FROM audit_log WHERE table_name IS NOT NULL ORDER BY table_name') as $t) {
    $tableOptions[(string) $t['table_name']] = str_replace('_', ' ', (string) $t['table_name']);
}
$userOptions = ['system' => 'System (import / cron)'];
foreach (rows('SELECT id, display_name, is_active FROM users ORDER BY display_name') as $u) {
    $userOptions[(int) $u['id']] = $u['display_name'] . ((int) $u['is_active'] === 1 ? '' : ' (inactive)');
}

// ------------------------------------------------------------------ page

layout_header('History', ['group_tabs' => false, 'css' => ['assets/events.css']]);
echo '<div class="toolbar"><h1>History</h1><span class="muted">Every change, login and import, newest first.</span></div>';

echo '<div class="card"><form method="get" action="' . h(app_url('history.php')) . '" class="filters">';
echo '<div><label for="f-group">Group</label><select name="group" id="f-group"><option value="">Any</option>' . history_options($groupOptions, $f['group']) . '</select></div>';
echo '<div><label for="f-employee">Employee</label><select name="employee" id="f-employee"><option value="">Any</option>' . history_options($empOptions, $f['employee']) . '</select></div>';
echo '<div><label for="f-table">Table</label><select name="table" id="f-table"><option value="">Any</option>' . history_options($tableOptions, $f['table']) . '</select></div>';
echo '<div><label for="f-user">User</label><select name="user" id="f-user"><option value="">Any</option>' . history_options($userOptions, $f['user']) . '</select></div>';
echo '<div><label for="f-from">From</label><input type="date" name="from" id="f-from" value="' . h($f['from']) . '"></div>';
echo '<div><label for="f-to">To</label><input type="date" name="to" id="f-to" value="' . h($f['to']) . '"></div>';
echo '<div class="actions"><button class="btn btn-primary" type="submit">Filter</button> <a class="btn" href="' . h(app_url('history.php')) . '">Clear</a></div>';
echo '</form></div>';

$first = $total === 0 ? 0 : $offset + 1;
$last = min($total, $offset + count($list));
echo '<p class="help">' . h(plural($total, 'entry', 'entries')) . ($total > 0 ? ', showing ' . h((string) $first) . '-' . h((string) $last) : '') . '.</p>';

// Sortable within the page (app.js): When carries the full timestamp in data-v, What sorts by employee name.
echo '<div class="table-wrap"><table class="sortable"><thead><tr><th data-sort="date">When</th><th data-sort="text">Who</th><th data-sort="text">Action</th><th data-sort="text">What</th><th>Summary</th><th></th></tr></thead><tbody>';
foreach ($list as $a) {
    $table = $a['table_name'] === null ? null : (string) $a['table_name'];
    $rowExists = $table !== null && $a['row_id'] !== null && isset($exists[$table . '#' . (int) $a['row_id']]);
    $before = $a['before_json'] === null ? null : json_decode((string) $a['before_json'], true);
    $after = $a['after_json'] === null ? null : json_decode((string) $a['after_json'], true);
    $before = is_array($before) ? $before : null;
    $after = is_array($after) ? $after : null;
    $restorable = $a['action'] === 'delete' && isset(HISTORY_RESTORE_COLUMNS[(string) $table]) && $before !== null && !$rowExists;

    echo '<tr>';
    echo '<td class="audit-when" data-v="' . h((string) $a['at']) . '">' . h(fmt_date(substr((string) $a['at'], 0, 10))) . '<br><small class="muted">' . h(substr((string) $a['at'], 11, 5)) . '</small></td>';
    echo '<td class="audit-who" data-v="' . h($a['user_id'] === null ? 'System' : (string) ($a['user_name'] ?? ('user #' . (string) $a['user_id']))) . '">' . ($a['user_id'] === null ? '<span class="muted">System</span>' : h((string) ($a['user_name'] ?? ('user #' . (string) $a['user_id']))))
        . ($a['ip'] !== null ? '<small>' . h((string) $a['ip']) . '</small>' : '') . '</td>';
    echo '<td><span class="badge action-' . h((string) $a['action']) . '">' . h((string) $a['action']) . '</span></td>';
    echo '<td data-v="' . h((string) ($a['employee_name'] ?? '')) . '">' . history_row_ref($a, $rowExists);
    if ($a['employee_name'] !== null) {
        echo '<br><small><a href="' . h(app_url('employee.php?id=' . (int) $a['employee_id'])) . '">' . h((string) $a['employee_name']) . '</a></small>';
    }
    if ($a['group_name'] !== null) {
        echo '<br><small class="muted">' . h((string) $a['group_name']) . '</small>';
    }
    echo '</td>';
    echo '<td>' . h((string) $a['summary']);
    if ($before !== null || $after !== null) {
        echo '<details class="audit-diff"><summary>' . ($before !== null && $after !== null ? 'Show changes' : 'Show ' . ($before !== null ? 'deleted row' : 'row')) . '</summary>'
            . history_diff($before, $after) . '</details>';
    }
    echo '</td>';
    echo '<td>';
    if ($restorable) {
        echo '<form method="post" action="' . h($here) . '" class="inline-form" data-confirm="Restore this deleted row?">' . csrf_field()
            . '<input type="hidden" name="action" value="restore"><input type="hidden" name="audit_id" value="' . h((string) $a['id']) . '">'
            . '<button class="btn btn-sm" type="submit">Restore</button></form>';
    } elseif ($a['action'] === 'delete' && $rowExists) {
        echo '<span class="badge badge-ok">restored</span>';
    }
    echo '</td></tr>';
}
if ($list === []) {
    echo '<tr><td colspan="6" class="muted">Nothing matches these filters.</td></tr>';
}
echo '</tbody></table></div>';

// Pager.
$pages = (int) ceil($total / HISTORY_PAGE_SIZE);
if ($pages > 1) {
    echo '<div class="pager">';
    if ($page > 1) {
        echo '<a class="btn btn-sm" href="' . h(app_url('history.php') . '?' . http_build_query($qs + ['page' => $page - 1])) . '">Newer</a>';
    }
    echo '<span class="muted">Page ' . h((string) $page) . ' of ' . h((string) $pages) . '</span>';
    if ($page < $pages) {
        echo '<a class="btn btn-sm" href="' . h(app_url('history.php') . '?' . http_build_query($qs + ['page' => $page + 1])) . '">Older</a>';
    }
    echo '</div>';
}

layout_footer();

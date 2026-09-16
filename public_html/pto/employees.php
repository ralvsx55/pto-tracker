<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Employees (SPEC section 7.5): the active list, the Former employees tab and the Add employee form.
 * - Active: name, hire date, years of service, birthday MM/DD, current-cycle allotment per kind, Total.
 * - Former: departed people with hire/departure dates, a link to their ledger and Export CSV per person.
 * - Add: US composes "Last, First" from two fields (live preview in assets/employee.js); Manila has one Name field.
 *   Hire date; birthday month/day dropdowns with an optional year. Warns on a duplicate name (active or former);
 *   a rehire is a new row (SPEC section 6), so the warning can be overridden with a checkbox.
 * Every write goes through tx() with an audit() row. After an add, birthday_rows_topup() and mark_dirty() on the
 * group's PTO and birthday calendars (SPEC 14.3), then the inline sync pass.
 * The profile fields (read / validate / render) live in lib/employees.php and are shared with employee.php.
 */

$user = require_login();
$group = current_group();
$policy = group_policy($group);
$kinds = $policy['kinds'];
$today = group_today($group);
$groupId = (int) $group['id'];
$tab = get('tab') === 'former' ? 'former' : 'active';

// ---- Add employee (POST) ----------------------------------------------------------------------------------
$showForm = get('add') === '1';
$errors = [];
$dupRows = [];
$formValues = employee_blank_values();

if (is_post() && post('action') === 'add') {
    $showForm = true;
    $in = employee_read_profile($group, true);
    $formValues = $in['values'];
    $errors = $in['errors'];
    if ($errors === []) {
        $row = $in['row'];
        // Duplicate-name warning (active or former). Not unique by design: a rehire is a new row.
        $dupRows = rows('SELECT id, name, status, hire_date, departed_on FROM employees WHERE group_id = ? AND name = ? ORDER BY hire_date',
            [$groupId, $row['name']]);
        if ($dupRows === [] || post('dup_ok') === '1') {
            $newId = tx(static function () use ($row, $groupId, $user): int {
                $now = now_str();
                $id = insert('employees', $row + [
                    'group_id'    => $groupId,
                    'status'      => 'active',
                    'departed_on' => null,
                    'created_at'  => $now,
                    'created_by'  => (int) $user['id'],
                    'updated_at'  => $now,
                    'updated_by'  => (int) $user['id'],
                ]);
                $after = row('SELECT * FROM employees WHERE id = ?', [$id]);
                audit('insert', 'employees', $id, $id, $groupId, null, $after,
                    $row['name'] . ': added (hired ' . $row['hire_date'] . ')');
                return $id;
            });
            // SPEC 14.3 hook: birthday rows for this year and next, both mirrored calendars dirty, inline pass.
            birthday_rows_topup($groupId);
            foreach (array_merge(group_calendars($groupId, 'pto'), group_calendars($groupId, 'birthdays')) as $c) {
                mark_dirty((string) $c['cal_key']);
            }
            flash('ok', rtrim('Added ' . $row['name'] . '. ' . sync_dirty_inline($groupId)));
            redirect('employee.php?id=' . $newId);
        }
    }
}

// ---- Data for the lists --------------------------------------------------------------------------------------
$all = group_summaries($groupId, $today);
$active = [];
$former = [];
foreach ($all as $entry) {
    if ($entry['employee']['status'] === 'departed') {
        $former[] = $entry;
    } else {
        $active[] = $entry;
    }
}
$byName = static fn(array $a, array $b): int => strnatcasecmp($a['employee']['name'], $b['employee']['name']);
usort($active, $byName);
usort($former, $byName);
// array_values: $all is keyed by employee id and json_encode must produce a JSON list for employee.js.
$existingNames = array_values(array_map(static fn(array $entry): string => (string) $entry['employee']['name'], $all));

// ---- Page ----------------------------------------------------------------------------------------------------
layout_header('Employees', ['css' => ['assets/employees.css'], 'js' => ['assets/employee.js']]);

$g = '?g=' . rawurlencode((string) $group['group_key']);
echo '<div class="toolbar"><h1>Employees</h1><span class="muted">' . h($group['name']) . '</span><span class="spacer"></span>';
echo '<a class="btn btn-primary" href="' . h(app_url('employees.php') . $g . '&add=1') . '#add">+ Add employee</a> ';
echo '<a class="btn" href="' . h(app_url('request.php') . $g) . '">+ Time off</a></div>';

// Add form (open on ?add=1, or after a failed save).
echo '<div class="card" id="add"' . ($showForm ? '' : ' hidden') . '>';
echo '<h2>Add employee to ' . h($group['name']) . '</h2>';
if ($errors !== []) {
    echo '<div class="inline-err"><ul>';
    foreach ($errors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
echo '<form method="post" data-name-form data-existing-names="' . h(json_encode($existingNames, JSON_UNESCAPED_UNICODE) ?: '[]') . '"' . ($showForm ? ' data-autofocus-first' : '') . '>';
echo csrf_field() . '<input type="hidden" name="action" value="add">';
employee_render_profile_fields($group, $formValues, true);
if ($dupRows !== []) {
    echo '<div class="inline-warn"><b>This name already exists in ' . h($group['name']) . ':</b><ul>';
    foreach ($dupRows as $d) {
        $state = $d['status'] === 'departed' ? 'former, ' . fmt_date($d['hire_date']) . ' - ' . fmt_date($d['departed_on']) : 'active since ' . fmt_date($d['hire_date']);
        echo '<li><a href="' . h(app_url('employee.php?id=' . (int) $d['id'])) . '">' . h($d['name']) . '</a> (' . h($state) . ')</li>';
    }
    echo '</ul><label class="check"><input type="checkbox" name="dup_ok" value="1"> Add anyway: this is a rehire or a different person with the same name (a rehire is a new row; history stays with the old one).</label></div>';
}
echo '<div class="actions"><button class="btn btn-primary" type="submit">Save employee</button>';
echo '<a class="btn" href="' . h(app_url('employees.php') . $g) . '">Cancel</a></div>';
echo '</form></div>';

// Tabs
echo '<nav class="subtabs">';
echo '<a' . ($tab === 'active' ? ' class="active"' : '') . ' href="' . h(app_url('employees.php') . $g) . '">Active (' . count($active) . ')</a>';
echo '<a' . ($tab === 'former' ? ' class="active"' : '') . ' href="' . h(app_url('employees.php') . $g . '&tab=former') . '">Former employees (' . count($former) . ')</a>';
echo '</nav>';

if ($tab === 'active') {
    // Sortable (app.js): hire date data-v Y-m-d; birthday sorts by month then day through data-v MM-DD.
    echo '<div class="table-wrap"><table class="sortable"><thead><tr><th data-sort="text">Employee</th><th data-sort="date">Hire date</th><th class="num" data-sort="num">Years</th><th data-sort="month">Birthday</th>';
    foreach ($kinds as $k) {
        echo '<th class="num" data-sort="num">' . h($k) . ' allot.</th>';
    }
    echo '<th class="num" data-sort="num">Total</th><th></th></tr></thead><tbody>';
    if ($active === []) {
        echo '<tr><td colspan="' . (6 + count($kinds)) . '" class="muted">No active employees. Use + Add employee.</td></tr>';
    }
    foreach ($active as $entry) {
        $e = $entry['employee'];
        $cur = $entry['summary']['current'];
        $id = (int) $e['id'];
        $total = 0;
        echo '<tr><td><a href="' . h(app_url('employee.php?id=' . $id)) . '">' . h($e['name']) . '</a></td>';
        // "renews" comes from the engine's next cycle start, not the hire date: a Feb 29 hire renews on 03/01 in a non-leap year.
        echo '<td data-v="' . h((string) $e['hire_date']) . '">' . h(fmt_date($e['hire_date'])) . ' <span class="help">renews ' . h($entry['summary']['next']['start']->format('m/d')) . '</span></td>';
        echo '<td class="num">' . h((string) years_of_service(to_date($e['hire_date']), $today)) . '</td>';
        $bdayKey = $e['birth_month'] === null || $e['birth_day'] === null ? '' : sprintf('%02d-%02d', (int) $e['birth_month'], (int) $e['birth_day']);
        echo '<td data-v="' . h($bdayKey) . '">' . h(employee_birthday($e) ?: '-') . '</td>';
        foreach ($kinds as $k) {
            // Allotment for the current cycle including adjustments (SPEC section 4).
            $allot = $cur['allotment'][$k] + $cur['adjusted'][$k];
            $total += $allot;
            echo '<td class="num" data-v="' . h(fmt_days($allot)) . '">' . h(fmt_days($allot)) . ($cur['adjusted'][$k] != 0 ? ' <span class="badge badge-warn" title="includes adjustments">adj</span>' : '') . '</td>';
        }
        echo '<td class="num">' . h(fmt_days($total)) . '</td>';
        echo '<td class="num"><a class="btn btn-sm" href="' . h(app_url('employee.php?id=' . $id)) . '">Ledger</a> '
            . '<a class="btn btn-sm" href="' . h(app_url('employee.php?id=' . $id . '&export=csv')) . '">CSV</a></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="help">Allotments are for each person\'s current cycle (renews on the hire-date anniversary). Balances are on the Dashboard; open a person for the full ledger.</p>';
} else {
    echo '<div class="table-wrap"><table class="sortable"><thead><tr><th data-sort="text">Employee</th><th data-sort="date">Hire date</th><th data-sort="date">Departed on</th><th class="num" data-sort="num">Years served</th><th></th></tr></thead><tbody>';
    if ($former === []) {
        echo '<tr><td colspan="5" class="muted">No former employees.</td></tr>';
    }
    foreach ($former as $entry) {
        $e = $entry['employee'];
        $id = (int) $e['id'];
        $dep = to_date($e['departed_on']);
        $served = $dep === null ? '' : (string) years_of_service(to_date($e['hire_date']), $dep);
        echo '<tr><td><a href="' . h(app_url('employee.php?id=' . $id)) . '">' . h($e['name']) . '</a></td>';
        echo '<td data-v="' . h((string) $e['hire_date']) . '">' . h(fmt_date($e['hire_date'])) . '</td>'
            . '<td data-v="' . h((string) ($e['departed_on'] ?? '')) . '">' . h(fmt_date($e['departed_on'])) . '</td>';
        echo '<td class="num">' . h($served) . '</td>';
        echo '<td class="num"><a class="btn btn-sm" href="' . h(app_url('employee.php?id=' . $id)) . '">Ledger</a> '
            . '<a class="btn btn-sm" href="' . h(app_url('employee.php?id=' . $id . '&export=csv')) . '">Export CSV</a></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="help">Former employees are off the viewer page and their calendar events are removed; their history is kept here. Open a person to un-depart them.</p>';
}
layout_footer();

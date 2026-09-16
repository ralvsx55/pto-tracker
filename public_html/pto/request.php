<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Time-off form (SPEC section 7.3): the 10-second form.
 *  - employee select: active employees of the current group, the last-used one first (remembered in the session)
 *  - kind as segmented buttons showing only the group's kinds (Manila shows one)
 *  - start date, end date auto-filled (app.js data-follow), note
 *  - live preview line from preview.php via assets/request.js on every change (working days, holidays skipped,
 *    remaining per kind, non-blocking warnings); a US request spanning an anniversary offers "Split into two rows"
 *    which posts two requests; a Manila straddle explains how the days split (the preview's warning)
 *  - edit mode via ?id=; after a save the employee stays selected for the next entry
 * Delete lives in requests.php (one handler); the edit view links to it. The writes are lib/time_off.php.
 * After every save the group's PTO calendar is marked dirty and sync_dirty_inline() runs (SPEC 14.3); its sentence
 * is appended to the flash. The edit view shows the row's calendar state (SPEC 14.5).
 */
$user = require_login();

// ---- which group, which row --------------------------------------------------------------------------
$editId = get('id');
if ($editId === null && is_post()) {
    $editId = post('id');   // the edit form also carries its id as a hidden field
}
$editId = is_string($editId) && ctype_digit($editId) ? (int) $editId : null;
$editRow = null;
if ($editId !== null) {
    $editRow = row('SELECT * FROM time_off WHERE id = ?', [$editId]);
    if ($editRow === null) {
        layout_error_page(404, 'Not found', 'There is no time-off request #' . $editId . '.', 'requests.php', 'All time off');
    }
    $eg = employee_with_group((int) $editRow['employee_id']);
    if ($eg === null) {
        layout_error_page(404, 'Not found', 'The employee of request #' . $editId . ' no longer exists.', 'requests.php', 'All time off');
    }
    $group = $eg['group'];
    if (!user_can_group($user, (int) $group['id'])) {
        layout_error_page(403, 'Not allowed', 'This request belongs to a group your account cannot edit.', 'requests.php', 'All time off');
    }
    // The row decides the group in edit mode; a conflicting ?g= is dropped so the tabs and the row agree.
    if (get('g') !== null && get('g') !== $group['group_key']) {
        redirect('request.php?id=' . $editId);
    }
    $_SESSION['group_key'] = $group['group_key'];
} else {
    $group = current_group();
}
$gid = (int) $group['id'];
$kinds = group_kinds($group);
$policy = group_policy($group);
$sessEmpKey = 'last_employee_' . $group['group_key'];
$sessKindKey = 'last_kind_' . $group['group_key'];

// ---- form state -------------------------------------------------------------------------------------------
$errors = [];
if ($editRow !== null) {
    $form = [
        'employee_id' => (int) $editRow['employee_id'],
        'kind'        => (string) $editRow['kind'],
        'start'       => (string) $editRow['start_date'],
        'end'         => (string) $editRow['end_date'],
        'note'        => (string) ($editRow['note'] ?? ''),
    ];
} else {
    $preselect = get('employee');
    $lastEmp = $_SESSION[$sessEmpKey] ?? null;
    $lastKind = $_SESSION[$sessKindKey] ?? null;
    $form = [
        'employee_id' => is_string($preselect) && ctype_digit($preselect) ? (int) $preselect : (is_int($lastEmp) ? $lastEmp : 0),
        'kind'        => is_string($lastKind) && in_array($lastKind, $kinds, true) ? $lastKind : $kinds[0],
        'start'       => '',
        'end'         => '',
        'note'        => '',
    ];
}

// ---- save ---------------------------------------------------------------------------------------------------
if (is_post()) {
    $action = (string) post('action', 'save');
    $form = [
        'employee_id' => (int) post('employee_id', 0),
        'kind'        => (string) post('kind', ''),
        'start'       => (string) post('start', ''),
        'end'         => (string) post('end', ''),
        'note'        => (string) post('note', ''),
    ];
    $employee = $form['employee_id'] > 0 ? row('SELECT * FROM employees WHERE id = ? AND group_id = ?', [$form['employee_id'], $gid]) : null;
    if ($employee === null) {
        $errors[] = 'Pick an employee.';
    } elseif ($employee['status'] !== 'active' && ($editRow === null || (int) $editRow['employee_id'] !== (int) $employee['id'])) {
        $errors[] = $employee['name'] . ' has departed; new time off cannot be entered for a former employee.';
    }
    if (!in_array($form['kind'], $kinds, true)) {
        $errors[] = 'Pick a kind (' . implode(' or ', $kinds) . ').';
    }
    $s = to_date($form['start']);
    $en = to_date($form['end']);
    if ($s === null) {
        $errors[] = 'Enter a start date.';
    }
    if ($en === null) {
        $errors[] = 'Enter an end date.';
    } elseif ($s !== null && $en < $s) {
        $errors[] = 'The end date is before the start date.';
    }
    if (mb_strlen($form['note']) > 255) {
        $errors[] = 'The note is longer than 255 characters.';
    }

    $preview = null;
    if ($errors === [] && $employee !== null && $s !== null && $en !== null) {
        // The same engine call the live preview uses; it validates the kind against the group's policy too.
        $preview = request_preview((int) $employee['id'], $form['kind'], ymd($s), ymd($en), $editRow === null ? null : (int) $editRow['id']);
        if (isset($preview['error'])) {
            $errors[] = (string) $preview['error'];
        }
    }

    if ($errors === [] && $employee !== null && $preview !== null && $s !== null && $en !== null) {
        $note = $form['note'] === '' ? null : $form['note'];
        $base = ['employee_id' => (int) $employee['id'], 'kind' => $form['kind'], 'start_date' => ymd($s), 'end_date' => ymd($en), 'note' => $note];
        $userId = (int) $user['id'];
        $split = $preview['straddle']['split'] ?? null;   // US start_cycle policy only: [[s1,e1],[s2,e2]]

        if ($action === 'split' && $split === null) {
            $errors[] = 'This request does not span an anniversary, so there is nothing to split.';
        } elseif ($action === 'split') {
            // SPEC 7.3: "split into two rows" posts two requests, one per cycle. The ranges come from the
            // engine (never from the browser). In edit mode the existing row becomes the first part.
            $first = $base;
            $first['start_date'] = $split[0][0];
            $first['end_date'] = $split[0][1];
            $second = $base;
            $second['start_date'] = $split[1][0];
            $second['end_date'] = $split[1][1];
            tx(static function () use ($group, $employee, $first, $second, $editRow, $userId): void {
                if ($editRow === null) {
                    time_off_insert($group, $employee, $first, $userId);
                } else {
                    time_off_update($group, $employee, $editRow, $first, $userId);
                }
                time_off_insert($group, $employee, $second, $userId);
            });
            $saved = sprintf('%s: %s split into two rows, %s - %s and %s - %s.', $employee['name'], $form['kind'],
                fmt_date($split[0][0]), fmt_date($split[0][1]), fmt_date($split[1][0]), fmt_date($split[1][1]));
        } elseif ($editRow === null) {
            $days = (int) $preview['working_days'];
            tx(static function () use ($group, $employee, $base, $userId): void {
                time_off_insert($group, $employee, $base, $userId);
            });
            $saved = sprintf('%s: %s %s - %s (%s) added.', $employee['name'], $form['kind'], fmt_date($s), fmt_date($en), plural($days, 'working day'));
        } else {
            tx(static function () use ($group, $employee, $editRow, $base, $userId): void {
                time_off_update($group, $employee, $editRow, $base, $userId);
            });
            $saved = sprintf('%s: %s %s - %s saved.', $employee['name'], $form['kind'], fmt_date($s), fmt_date($en));
        }

        if ($errors === []) {
            // SPEC 14.3 hook: the group's PTO calendar is dirty; sync it now within the budget (or cron does within 15 min).
            foreach (group_calendars($gid, 'pto') as $ptoCal) {
                mark_dirty((string) $ptoCal['cal_key']);
            }
            flash('ok', rtrim($saved . ' ' . sync_dirty_inline($gid)));
            // Save keeps the employee (and kind) selected for quick repeated entry.
            $_SESSION[$sessEmpKey] = (int) $employee['id'];
            $_SESSION[$sessKindKey] = $form['kind'];
            redirect($editRow === null ? 'request.php' : 'requests.php');
        }
    }
}

// ---- employee options: active employees of the group, last-used first, then by name ----------------------
$employees = rows("SELECT id, name, status FROM employees WHERE group_id = ? AND status = 'active' ORDER BY name, id", [$gid]);
if ($editRow !== null) {
    $own = row('SELECT id, name, status FROM employees WHERE id = ?', [(int) $editRow['employee_id']]);
    if ($own !== null && $own['status'] !== 'active') {
        $employees[] = $own;   // a departed employee's own row stays editable
    }
}
$lastUsed = $_SESSION[$sessEmpKey] ?? null;
if (is_int($lastUsed)) {
    usort($employees, static function (array $a, array $b) use ($lastUsed): int {
        $aFirst = (int) $a['id'] === $lastUsed ? 0 : 1;
        $bFirst = (int) $b['id'] === $lastUsed ? 0 : 1;
        return [$aFirst, $a['name'], (int) $a['id']] <=> [$bFirst, $b['name'], (int) $b['id']];
    });
}
$employeeIds = array_map(static fn(array $e): int => (int) $e['id'], $employees);
if (!in_array($form['employee_id'], $employeeIds, true)) {
    $form['employee_id'] = 0;   // force a choice rather than silently picking someone
}

// A server-rendered first preview for edit mode (and after a failed post), so the page is useful before JS runs.
$initialPreview = null;
if ($form['employee_id'] > 0 && in_array($form['kind'], $kinds, true) && to_date($form['start']) !== null && to_date($form['end']) !== null) {
    $initialPreview = request_preview($form['employee_id'], $form['kind'], $form['start'], $form['end'], $editRow === null ? null : (int) $editRow['id']);
}

// ---- render ---------------------------------------------------------------------------------------------------
$title = $editRow === null ? 'Time off' : 'Edit time off';
layout_header($title, ['css' => ['assets/requests.css'], 'js' => ['assets/request.js']]);
echo '<div class="toolbar"><h1>' . h($editRow === null ? 'Add time off' : 'Edit time off #' . (int) $editRow['id']) . '</h1>';
echo '<span class="muted">' . h($group['name']) . '</span><span class="spacer"></span>';
echo '<a class="btn" href="' . h(app_url('requests.php')) . '">All time off</a></div>';

echo '<div class="card">';
foreach ($errors as $err) {
    echo '<div class="flash flash-err">' . h($err) . '</div>';
}
echo '<form method="post" id="request-form" action="' . h(app_url('request.php') . ($editRow === null ? '' : '?id=' . (int) $editRow['id']))
    . '" data-preview-url="' . h(app_url('preview.php'))
    . '" data-exclude="' . ($editRow === null ? '' : (int) $editRow['id']) . '">';
echo csrf_field();
if ($editRow !== null) {
    echo '<input type="hidden" name="id" value="' . (int) $editRow['id'] . '">';
}

echo '<div class="form-row">';
// Employee. Autofocus the employee select when nothing is preselected, else the start date (fast repeated entry).
$focusEmployee = $form['employee_id'] === 0;
echo '<div><label for="employee_id">Employee</label><select id="employee_id" name="employee_id" required' . ($focusEmployee ? ' data-autofocus' : '') . '>';
echo '<option value="">Choose...</option>';
foreach ($employees as $e) {
    $sel = (int) $e['id'] === $form['employee_id'] ? ' selected' : '';
    $label = $e['name'] . ($e['status'] !== 'active' ? ' (departed)' : '');
    echo '<option value="' . (int) $e['id'] . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select></div>';

// Kind: segmented buttons, only the group's kinds (SPEC 7.3). One kind still renders as a single button.
echo '<div><span class="field-label">Kind</span><div class="segmented" role="radiogroup" aria-label="Kind">';
foreach ($kinds as $k) {
    $chk = $k === $form['kind'] ? ' checked' : '';
    echo '<label><input type="radio" name="kind" value="' . h($k) . '"' . $chk . ' required><span>' . h($k) . '</span></label>';
}
echo '</div></div>';
echo '</div>';

echo '<div class="form-row">';
echo '<div><label for="start">Start date</label><input type="date" id="start" name="start" value="' . h($form['start']) . '" required'
    . ($focusEmployee ? '' : ' data-autofocus') . '></div>';
echo '<div><label for="end">End date</label><input type="date" id="end" name="end" value="' . h($form['end']) . '" data-follow="start" required></div>';
echo '</div>';

echo '<label for="note">Note <span class="muted">(optional)</span></label>';
echo '<input type="text" id="note" name="note" maxlength="255" value="' . h($form['note']) . '" autocomplete="off">';

// Live preview box. request.js replaces its content on every change; the server fills it once for edit mode.
echo '<div class="preview" id="preview" aria-live="polite" data-empty="Pick the employee and dates to see the working days and the resulting balance.">';
$splitInitial = null;
if ($initialPreview === null) {
    echo 'Pick the employee and dates to see the working days and the resulting balance.';
} elseif (isset($initialPreview['error'])) {
    echo '<div class="err">' . h((string) $initialPreview['error']) . '</div>';
} else {
    echo '<div class="line">' . h((string) $initialPreview['summary_line']) . '</div>';
    $parts = [];
    foreach ($kinds as $k) {
        $parts[] = $k . ' ' . fmt_days($initialPreview['remaining_after'][$k] ?? 0);
    }
    echo '<div class="muted">Remaining after this request: ' . h(implode(', ', $parts)) . '</div>';
    if ($initialPreview['warnings'] !== []) {
        echo '<ul class="warnings">';
        foreach ($initialPreview['warnings'] as $w) {
            echo '<li class="warn">' . h((string) $w) . '</li>';
        }
        echo '</ul>';
    }
    $splitInitial = $initialPreview['straddle']['split'] ?? null;
}
echo '</div>';

echo '<div class="actions">';
echo '<button class="btn btn-primary" type="submit" name="action" value="save">' . ($editRow === null ? 'Save time off' : 'Save changes') . '</button>';
// Shown by request.js only when the preview reports a US-style straddle (straddle.split).
$splitLabel = 'Split into two rows';
if ($splitInitial !== null) {
    $splitLabel .= sprintf(' (%s - %s and %s - %s)', fmt_date($splitInitial[0][0]), fmt_date($splitInitial[0][1]), fmt_date($splitInitial[1][0]), fmt_date($splitInitial[1][1]));
}
echo '<button class="btn" type="submit" name="action" value="split" id="split-btn"' . ($splitInitial === null ? ' hidden' : '') . '>' . h($splitLabel) . '</button>';
echo '<a class="btn" href="' . h(app_url('requests.php')) . '">Cancel</a>';
echo '</div>';
echo '</form>';

if ($editRow !== null) {
    // Delete is handled by requests.php (one handler, full before-image to audit_log); this is just the button.
    $own = row('SELECT name FROM employees WHERE id = ?', [(int) $editRow['employee_id']]);
    // Calendar state (SPEC 14.5): synced / pending / error with the sync_error text (lib/layout.php sync_badge()).
    $calState = sync_badge($editRow, true);
    $confirm = sprintf('Delete %s: %s %s - %s? It can be restored from History.', $own['name'] ?? '', $editRow['kind'],
        fmt_date($editRow['start_date']), fmt_date($editRow['end_date']));
    echo '<form method="post" action="' . h(app_url('requests.php')) . '" class="delete-form" data-confirm="' . h($confirm) . '">'
        . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $editRow['id'] . '">'
        . '<div class="actions"><button class="btn btn-danger" type="submit">Delete this request</button>'
        . '<span class="help">Created ' . h(substr((string) $editRow['created_at'], 0, 16)) . ', last changed ' . h(substr((string) $editRow['updated_at'], 0, 16))
        . ' &middot; calendar: ' . $calState . '</span></div></form>';
}
echo '</div>';

echo '<p class="help">Working days are Monday to Friday minus company holidays'
    . ($policy['straddle'] === 'split'
        ? '; a request that spans the anniversary is charged day by day to the cycle each day falls in.'
        : '; a request is charged to the cycle containing its start date (spanning requests can be split into two rows).')
    . ' Nothing here blocks a save; warnings are advice.</p>';
layout_footer();

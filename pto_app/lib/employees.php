<?php
declare(strict_types=1);

/**
 * Employee profile fields shared by employees.php (add) and employee.php (edit):
 * reading + validating the posted fields, rendering them, and the birthday label.
 * US (group_key 'us') composes "Last, First" from two fields (SPEC section 6: the exact display name and
 * calendar title prefix); Manila uses one Name field. Birthday = month + day together or both blank; year optional.
 */

/** "02/18" or "02/18/1972"; '' when no birthday is stored. */
function employee_birthday(array $e): string
{
    if ($e['birth_month'] === null || $e['birth_day'] === null) {
        return '';
    }
    $s = sprintf('%02d/%02d', (int) $e['birth_month'], (int) $e['birth_day']);
    if ($e['birth_year'] !== null) {
        $s .= '/' . (int) $e['birth_year'];
    }
    return $s;
}

/** Empty form values (the add form). */
function employee_blank_values(): array
{
    return ['last' => '', 'first' => '', 'name' => '', 'hire_date' => '', 'birth_month' => '', 'birth_day' => '', 'birth_year' => '', 'notes' => ''];
}

/** Form values from the DB row: US splits "Last, First" at the first comma; Manila keeps the name whole. */
function employee_values_from_row(array $group, array $e): array
{
    $v = ['last' => '', 'first' => '', 'name' => (string) $e['name'], 'hire_date' => (string) $e['hire_date'],
        'birth_month' => $e['birth_month'] === null ? '' : (string) (int) $e['birth_month'],
        'birth_day'   => $e['birth_day'] === null ? '' : (string) (int) $e['birth_day'],
        'birth_year'  => $e['birth_year'] === null ? '' : (string) (int) $e['birth_year'],
        'notes'       => (string) ($e['notes'] ?? '')];
    if ($group['group_key'] === 'us') {
        $parts = explode(',', (string) $e['name'], 2);
        $v['last'] = trim($parts[0]);
        $v['first'] = trim($parts[1] ?? '');
    }
    return $v;
}

/**
 * Reads the profile fields from POST and validates them.
 * Returns ['values' => form values for re-display, 'row' => employees columns, 'errors' => [...]].
 * $requireFirst: the add form insists on a first name for US; the edit form tolerates a lone last name
 * (a few imported names have no comma).
 */
function employee_read_profile(array $group, bool $requireFirst): array
{
    $us = $group['group_key'] === 'us';
    $v = [
        'last'        => (string) preg_replace('/\s+/', ' ', (string) post('last', '')),
        'first'       => (string) preg_replace('/\s+/', ' ', (string) post('first', '')),
        'name'        => (string) preg_replace('/\s+/', ' ', (string) post('name', '')),
        'hire_date'   => (string) post('hire_date', ''),
        'birth_month' => (string) post('birth_month', ''),
        'birth_day'   => (string) post('birth_day', ''),
        'birth_year'  => (string) post('birth_year', ''),
        'notes'       => (string) post('notes', ''),
    ];
    $errors = [];
    if ($us) {
        if ($v['last'] === '') {
            $errors[] = 'Last name is required.';
        }
        if ($requireFirst && $v['first'] === '') {
            $errors[] = 'First name is required.';
        }
        $name = $v['first'] === '' ? $v['last'] : $v['last'] . ', ' . $v['first'];
    } else {
        $name = $v['name'];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
    }
    if (mb_strlen($name) > 80) {
        $errors[] = 'Name is too long (80 characters max).';
    }
    $hire = to_date($v['hire_date']);
    if ($hire === null) {
        $errors[] = 'Enter the hire date as YYYY-MM-DD.';
    }
    $bm = $v['birth_month'] === '' ? null : (int) $v['birth_month'];
    $bd = $v['birth_day'] === '' ? null : (int) $v['birth_day'];
    $by = $v['birth_year'] === '' ? null : (int) $v['birth_year'];
    if (($bm === null) !== ($bd === null)) {
        $errors[] = 'Birthday needs both a month and a day (or leave both blank).';
    } elseif ($bm !== null) {
        // checkdate against a leap year so Feb 29 birthdays are accepted.
        if ($bm < 1 || $bm > 12 || $bd < 1 || $bd > 31 || !checkdate($bm, (int) $bd, 2024)) {
            $errors[] = 'That birthday is not a valid month/day.';
        } elseif ($by !== null) {
            if ($by < 1900 || $by > (int) date('Y')) {
                $errors[] = 'Birth year must be between 1900 and this year.';
            } elseif (!checkdate($bm, (int) $bd, $by)) {
                $errors[] = sprintf('%02d/%02d does not exist in %d.', $bm, $bd, $by);
            }
        }
    } elseif ($by !== null) {
        $errors[] = 'A birth year needs a month and day too.';
    }
    if (mb_strlen($v['notes']) > 255) {
        $errors[] = 'Notes are too long (255 characters max).';
    }
    $row = [
        'name'        => $name,
        'hire_date'   => $hire === null ? null : ymd($hire),
        'birth_month' => $bm,
        'birth_day'   => $bd,
        'birth_year'  => $bm === null ? null : $by,
        'notes'       => $v['notes'] === '' ? null : $v['notes'],
    ];
    return ['values' => $v, 'row' => $row, 'errors' => $errors];
}

/**
 * The name, hire date, birthday and notes fields. The surrounding <form> carries data-name-form (assets/employee.js
 * composes the "Last, First" preview and shows the duplicate / name-change warnings).
 */
function employee_render_profile_fields(array $group, array $v, bool $requireFirst): void
{
    echo '<div class="form-row">';
    if ($group['group_key'] === 'us') {
        echo '<div><label for="last">Last name</label><input type="text" id="last" name="last" maxlength="80" required data-name-part="last" value="' . h($v['last']) . '"></div>';
        echo '<div><label for="first">First name</label><input type="text" id="first" name="first" maxlength="80"' . ($requireFirst ? ' required' : '') . ' data-name-part="first" value="' . h($v['first']) . '"></div>';
    } else {
        echo '<div><label for="name">Name</label><input type="text" id="name" name="name" maxlength="80" required data-name-single value="' . h($v['name']) . '"></div>';
    }
    echo '<div><label for="hire_date">Hire date</label><input type="date" id="hire_date" name="hire_date" required value="' . h($v['hire_date']) . '"></div>';
    echo '</div>';
    if ($group['group_key'] === 'us') {
        echo '<p class="name-preview">Saved as: <output data-name-preview>' . h($v['first'] === '' ? $v['last'] : $v['last'] . ', ' . $v['first']) . '</output> <span class="help">(the calendar title prefix)</span></p>';
    } else {
        echo '<p class="help">Shown exactly like this on the viewer page and as the calendar title prefix.</p>';
    }
    echo '<div class="inline-warn" data-dup-warning hidden>An employee with this name already exists in this group (active or former). Double-check before saving; a rehire is a new row.</div>';
    echo '<div class="inline-warn" data-name-change-warning hidden>Changing the name changes the calendar event titles for this employee (applied when calendar sync runs, Milestone 2).</div>';

    echo '<div class="form-row">';
    echo '<div><label>Birthday <span class="help">(month/day; year optional)</span></label><div class="birthday-row">';
    echo '<select name="birth_month" aria-label="Birthday month"><option value="">Month</option>';
    foreach (month_names() as $n => $label) {
        echo '<option value="' . $n . '"' . ((string) $n === $v['birth_month'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</select><select name="birth_day" aria-label="Birthday day"><option value="">Day</option>';
    for ($d = 1; $d <= 31; $d++) {
        echo '<option value="' . $d . '"' . ((string) $d === $v['birth_day'] ? ' selected' : '') . '>' . $d . '</option>';
    }
    echo '</select><input type="number" name="birth_year" aria-label="Birth year" placeholder="Year" min="1900" max="' . h(date('Y')) . '" value="' . h($v['birth_year']) . '">';
    echo '</div></div>';
    echo '<div><label for="notes">Notes <span class="help">(optional, 255 characters)</span></label><input type="text" id="notes" name="notes" maxlength="255" value="' . h($v['notes']) . '"></div>';
    echo '</div>';
}

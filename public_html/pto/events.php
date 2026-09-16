<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Events (SPEC section 7.7): one tab per events-kind calendar of the selected group.
 * - Inline add row: title, start, end, "Company holiday (does not count against PTO)" checkbox (SPEC section 5).
 * - Edit / delete per row, audited with the full before/after row images so History can restore a delete.
 * - "Copy <year> events to <year+1>": every event of the selected year is copied one year forward.
 * - "Possibly misfiled" flag: importer-flagged ids (settings.misfiled_events, SPEC section 6) plus a title
 *   heuristic for the factory-closings calendars, with a one-click move to another calendar of the same group.
 *   Moving a row clears its id from settings.misfiled_events; "Keep here" records it in settings.misfiled_dismissed.
 *
 * - Calendar column (SPEC 14.5): synced / pending / error per row; every write marks the calendar dirty and runs
 *   sync_dirty_inline() (SPEC 14.3), whose sentence is appended to the flash.
 * Private helpers are prefixed events_ (candidates for lib promotion).
 */

$user = require_login();
$group = current_group();
$groupId = (int) $group['id'];
$userId = (int) $user['id'];

// ------------------------------------------------------------------ helpers

/** The group's events-kind calendars keyed by cal_key, sorted (SPEC section 6: events live on 'events' calendars). */
function events_calendars(int $groupId): array
{
    $out = [];
    foreach (group_calendars($groupId, 'events') as $c) {
        $out[$c['cal_key']] = $c;
    }
    return $out;
}

/** A settings row holding a JSON list of event ids (misfiled_events, misfiled_dismissed) as int[]. */
function events_setting_ids(string $name): array
{
    $v = json_decode(setting($name, '[]') ?? '[]', true);
    return is_array($v) ? array_values(array_unique(array_map('intval', $v))) : [];
}

function events_setting_ids_save(string $name, array $ids): void
{
    setting_set($name, json_encode(array_values(array_unique(array_map('intval', $ids)))));
}

/** Remove one id from a JSON id list setting (no write when it was not there). */
function events_setting_ids_remove(string $name, int $id): void
{
    $ids = events_setting_ids($name);
    $left = array_values(array_filter($ids, static fn(int $x): bool => $x !== $id));
    if (count($left) !== count($ids)) {
        events_setting_ids_save($name, $left);
    }
}

/** SPEC section 6: the Events screen clears an id from settings.misfiled_events when the row is moved (or deleted). */
function events_misfiled_clear(int $id): void
{
    events_setting_ids_remove('misfiled_events', $id);
    events_setting_ids_remove('misfiled_dismissed', $id);
}

/**
 * SPEC section 7.7 heuristic: a factory-closings calendar should only hold factory closings, so party / luncheon /
 * lunch / sale titles are suspicious there; the Manila "Office Closings" sheet also received the 2023-24 US office
 * closures (office closed / Thanksgiving / Christmas / Memorial Day / 4th of July).
 */
function events_title_looks_misfiled(string $calKey, string $title, array $group): bool
{
    if (!str_ends_with($calKey, '_factory')) {
        return false;
    }
    if (preg_match('/party|luncheon|lunch|sale/i', $title) === 1) {
        return true;
    }
    return $group['group_key'] === 'manila'
        && preg_match('/office closed|thanksgiving|christmas|memorial|4th of july/i', $title) === 1;
}

/** Flagged = importer-flagged or title heuristic, unless the user clicked "Keep here" for that row. */
function events_is_flagged(array $ev, array $group, array $flaggedIds, array $dismissedIds): bool
{
    $id = (int) $ev['id'];
    if (in_array($id, $dismissedIds, true)) {
        return false;
    }
    return in_array($id, $flaggedIds, true)
        || events_title_looks_misfiled((string) $ev['cal_key'], (string) $ev['title'], $group);
}

/** The events row when it belongs to one of this group's calendars, else null. */
function events_load(int $id, array $cals): ?array
{
    $ev = row('SELECT * FROM events WHERE id = ?', [$id]);
    return $ev !== null && isset($cals[$ev['cal_key']]) ? $ev : null;
}

/**
 * Validate the posted add/edit fields. Returns ['error' => ?string, 'data' => [...columns...], 'values' => raw].
 * Rules: calendar of this group, non-empty title (200 max), valid dates, end >= start (empty end = start).
 */
function events_validate(string $calKey, array $cals): array
{
    $title = (string) post('title', '');
    $startRaw = (string) post('start', '');
    $endRaw = (string) post('end', '');
    $holiday = post('is_holiday') === '1';
    $values = ['title' => $title, 'start' => $startRaw, 'end' => $endRaw, 'is_holiday' => $holiday, 'cal_key' => $calKey];
    $start = to_date($startRaw);
    $end = $endRaw === '' ? $start : to_date($endRaw);

    $error = null;
    if (!isset($cals[$calKey])) {
        $error = 'Pick a calendar of this group.';
    } elseif ($title === '') {
        $error = 'Enter a title.';
    } elseif (mb_strlen($title) > 200) {
        $error = 'The title is too long (200 characters max).';
    } elseif ($start === null) {
        $error = 'Enter a valid start date.';
    } elseif ($end === null) {
        $error = 'Enter a valid end date.';
    } elseif ($end < $start) {
        $error = 'The end date is before the start date.';
    }
    $data = $error !== null ? [] : [
        'cal_key'    => $calKey,
        'title'      => $title,
        'start_date' => ymd($start),
        'end_date'   => ymd($end),
        'is_holiday' => $holiday ? 1 : 0,
    ];
    return ['error' => $error, 'data' => $data, 'values' => $values];
}

/** Audit summary (SPEC section 11): "Company Events & Holidays: Christmas 2026-12-25..2026-12-25 (company holiday) added". */
function events_summary(array $cals, array $ev, string $verb): string
{
    $label = (string) ($cals[$ev['cal_key']]['label'] ?? $ev['cal_key']);
    $s = $label . ': ' . $ev['title'] . ' ' . $ev['start_date'] . '..' . $ev['end_date'];
    if ((int) $ev['is_holiday'] === 1) {
        $s .= ' (company holiday)';
    }
    return $s . ' ' . $verb;
}

/** events.php?g=<key>&cal=<cal>[&year=<year>][&extra]. */
function events_url(array $group, string $calKey, ?string $year = null, array $extra = []): string
{
    $qs = ['g' => $group['group_key'], 'cal' => $calKey];
    if ($year !== null) {
        $qs['year'] = $year;
    }
    return app_url('events.php') . '?' . http_build_query($qs + $extra);
}

/** After a save: keep "all years", otherwise show the year the saved row lives in. */
function events_year_after(string $year, string $startDate): string
{
    return $year === 'all' ? 'all' : substr($startDate, 0, 4);
}

/** Weekdays inside an event (what a holiday event would not charge); pure engine function. */
function events_weekdays(array $ev): int
{
    $s = to_date((string) $ev['start_date']);
    $e = to_date((string) $ev['end_date']);
    return $s === null || $e === null ? 0 : working_days($s, $e, []);
}

/** Options of a calendar <select>. */
function events_cal_options(array $cals, string $selected): string
{
    $html = '';
    foreach ($cals as $k => $c) {
        $html .= '<option value="' . h((string) $k) . '"' . ($k === $selected ? ' selected' : '') . '>' . h((string) $c['label']) . '</option>';
    }
    return $html;
}

/**
 * The five input cells of the add row / edit row (title [+ calendar select], start, end, weekdays, holiday).
 * The inputs belong to the form with id $formId, which sits outside the table (a form cannot span table cells).
 */
function events_input_cells(string $formId, array $v, array $cals, bool $withCal, string $prefix): string
{
    $f = ' form="' . h($formId) . '"';
    $html = '<td><input' . $f . ' type="text" name="title" id="' . h($prefix) . '-title" maxlength="200" required'
        . ' placeholder="Title" value="' . h((string) $v['title']) . '" aria-label="Title">';
    if ($withCal) {
        $html .= '<label class="ev-callabel" for="' . h($prefix) . '-cal">Calendar</label>'
            . '<select' . $f . ' name="cal_key" id="' . h($prefix) . '-cal">' . events_cal_options($cals, (string) $v['cal_key']) . '</select>';
    }
    $html .= '</td>';
    $html .= '<td><input' . $f . ' type="date" name="start" id="' . h($prefix) . '-start" required value="' . h((string) $v['start']) . '" aria-label="Start date"></td>';
    // data-follow (app.js): the end date follows the start date until it is set explicitly.
    $html .= '<td><input' . $f . ' type="date" name="end" id="' . h($prefix) . '-end" data-follow="' . h($prefix) . '-start" value="' . h((string) $v['end']) . '" aria-label="End date"></td>';
    $html .= '<td class="num muted"></td>';
    $html .= '<td><label class="ev-check"><input' . $f . ' type="checkbox" name="is_holiday" value="1"' . ($v['is_holiday'] ? ' checked' : '')
        . '> Company holiday (does not count against PTO)</label></td>';
    return $html;
}

/**
 * SPEC 14.3 hook after an events write: mark the touched calendar(s) dirty and run the inline incremental pass for
 * the group within its budget. Returns the engine's one-line status for the flash.
 */
function events_after_write(int $groupId, string ...$calKeys): string
{
    foreach (array_unique($calKeys) as $k) {
        mark_dirty($k);
    }
    return sync_dirty_inline($groupId);
}

/** A tiny one-button POST form (delete / move / keep / copy). */
function events_button_form(string $action, array $hidden, string $label, string $btnClass, ?string $confirm = null): string
{
    $html = '<form method="post" action="' . h($action) . '" class="inline-form"' . ($confirm !== null ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($hidden as $k => $val) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $val) . '">';
    }
    return $html . '<button class="btn btn-sm ' . h($btnClass) . '" type="submit">' . h($label) . '</button></form>';
}

// ------------------------------------------------------------------ which calendar, which year

$cals = events_calendars($groupId);
if ($cals === []) {
    layout_header('Events');
    echo '<div class="card"><h1>Events</h1><p>This group has no events calendars.</p></div>';
    layout_footer();
    exit;
}

$calKey = (string) get('cal', '');
$editId = ctype_digit((string) get('edit', '')) ? (int) get('edit') : 0;
$yearParam = get('year');   // ?year=<yyyy>|all; a History link may override it below with the event's own year

// A link from History arrives as ?edit=<id> without ?cal=: resolve the calendar (and group) from the event itself.
if ($editId > 0 && $calKey === '') {
    $hit = row('SELECT e.cal_key, e.start_date, c.group_id, g.group_key FROM events e JOIN calendars c ON c.cal_key = e.cal_key
                JOIN groups g ON g.id = c.group_id WHERE e.id = ?', [$editId]);
    if ($hit !== null && (int) $hit['group_id'] !== $groupId && user_can_group($user, (int) $hit['group_id'])) {
        redirect('events.php?' . http_build_query(['g' => $hit['group_key'], 'cal' => $hit['cal_key'], 'year' => substr((string) $hit['start_date'], 0, 4), 'edit' => $editId]));
    }
    if ($hit !== null && (int) $hit['group_id'] === $groupId) {
        $calKey = (string) $hit['cal_key'];
        if ($yearParam === null) {
            $yearParam = substr((string) $hit['start_date'], 0, 4);
        }
    }
}
if (!isset($cals[$calKey])) {
    $calKey = (string) array_key_first($cals);
}
$cal = $cals[$calKey];

$years = array_map('intval', array_column(rows('SELECT DISTINCT YEAR(start_date) AS y FROM events WHERE cal_key = ? ORDER BY y DESC', [$calKey]), 'y'));
$year = (string) ($yearParam ?? '');
if ($year !== 'all' && !in_array((int) $year, $years, true)) {
    $year = $years === [] ? 'all' : (string) $years[0];   // default: the latest year that has events
}
$here = events_url($group, $calKey, $year);

// ------------------------------------------------------------------ POST actions

$formError = null;    // validation message shown above the table (no redirect, so the typed values stay)
$formValues = null;   // sticky values for the add row or the edit row

if (is_post()) {
    $action = (string) post('action', '');
    $id = (int) post('id', 0);

    if ($action === 'add') {
        $v = events_validate($calKey, $cals);
        if ($v['error'] !== null) {
            $formError = $v['error'];
            $formValues = $v['values'];
        } else {
            $data = $v['data'] + ['created_at' => now_str(), 'created_by' => $userId, 'updated_at' => now_str(), 'updated_by' => $userId];
            tx(static function () use ($data, $cals, $groupId): void {
                $newId = insert('events', $data);
                $after = row('SELECT * FROM events WHERE id = ?', [$newId]);
                audit('insert', 'events', $newId, null, $groupId, null, $after, events_summary($cals, $after, 'added'));
            });
            group_holidays_reset();   // the memoised holiday set is stale after an events write
            flash('ok', rtrim('Added "' . $data['title'] . '" (' . fmt_date($data['start_date']) . ($data['end_date'] !== $data['start_date'] ? ' - ' . fmt_date($data['end_date']) : '') . '). '
                . events_after_write($groupId, $calKey)));
            redirect(events_url($group, $calKey, events_year_after($year, $data['start_date'])));
        }
    } elseif ($action === 'update') {
        $ev = events_load($id, $cals);
        if ($ev === null) {
            flash('err', 'That event no longer exists.');
            redirect($here);
        }
        $v = events_validate((string) post('cal_key', $ev['cal_key']), $cals);
        if ($v['error'] !== null) {
            $formError = $v['error'];
            $formValues = $v['values'];
            $editId = $id;
        } else {
            $changed = false;
            foreach ($v['data'] as $k => $val) {
                if ((string) $ev[$k] !== (string) $val) {
                    $changed = true;
                }
            }
            if (!$changed) {
                flash('ok', 'No changes.');
                redirect($here);
            }
            $data = $v['data'] + ['updated_at' => now_str(), 'updated_by' => $userId];
            $moved = $data['cal_key'] !== $ev['cal_key'];
            tx(static function () use ($id, $data, $ev, $cals, $groupId, $moved): void {
                update_row('events', $data, 'id = ?', [$id]);
                $after = row('SELECT * FROM events WHERE id = ?', [$id]);
                $verb = $moved ? 'updated and moved from ' . $cals[$ev['cal_key']]['label'] : 'updated';
                audit('update', 'events', $id, null, $groupId, $ev, $after, events_summary($cals, $after, $verb));
                if ($moved) {
                    events_misfiled_clear($id);   // SPEC section 6: moving clears the "possibly misfiled" flag
                }
            });
            group_holidays_reset();
            flash('ok', rtrim('Saved "' . $data['title'] . '". ' . events_after_write($groupId, (string) $ev['cal_key'], (string) $data['cal_key'])));
            redirect(events_url($group, $data['cal_key'], events_year_after($year, $data['start_date'])));
        }
    } elseif ($action === 'delete') {
        $ev = events_load($id, $cals);
        if ($ev === null) {
            flash('err', 'That event no longer exists.');
            redirect($here);
        }
        tx(static function () use ($id, $ev, $cals, $groupId): void {
            // Full before-image in the audit row: History's Restore re-inserts from it.
            audit('delete', 'events', $id, null, $groupId, $ev, null, events_summary($cals, $ev, 'deleted'));
            q('DELETE FROM events WHERE id = ?', [$id]);
            events_misfiled_clear($id);
        });
        group_holidays_reset();
        flash('ok', rtrim('Deleted "' . $ev['title'] . '". It can be restored from History. ' . events_after_write($groupId, (string) $ev['cal_key'])));
        redirect($here);
    } elseif ($action === 'move') {
        $ev = events_load($id, $cals);
        $toCal = (string) post('to_cal', '');
        if ($ev === null) {
            flash('err', 'That event no longer exists.');
            redirect($here);
        }
        if (!isset($cals[$toCal]) || $toCal === $ev['cal_key']) {
            flash('err', 'Pick another calendar of this group.');
            redirect($here);
        }
        tx(static function () use ($id, $ev, $toCal, $cals, $groupId, $userId): void {
            update_row('events', ['cal_key' => $toCal, 'updated_at' => now_str(), 'updated_by' => $userId], 'id = ?', [$id]);
            $after = row('SELECT * FROM events WHERE id = ?', [$id]);
            audit('update', 'events', $id, null, $groupId, $ev, $after, events_summary($cals, $after, 'moved from ' . $cals[$ev['cal_key']]['label']));
            events_misfiled_clear($id);   // SPEC section 6: the Events screen clears the id when the row is moved
        });
        group_holidays_reset();
        flash('ok', rtrim('Moved "' . $ev['title'] . '" to ' . $cals[$toCal]['label'] . '. ' . events_after_write($groupId, (string) $ev['cal_key'], $toCal)));
        redirect($here);
    } elseif ($action === 'keep') {
        $ev = events_load($id, $cals);
        if ($ev === null) {
            flash('err', 'That event no longer exists.');
            redirect($here);
        }
        tx(static function () use ($id, $ev, $cals, $groupId): void {
            events_setting_ids_remove('misfiled_events', $id);
            events_setting_ids_save('misfiled_dismissed', array_merge(events_setting_ids('misfiled_dismissed'), [$id]));
            audit('setting', 'events', $id, null, $groupId, null, null, events_summary($cals, $ev, 'kept on this calendar (misfiled flag cleared)'));
        });
        flash('ok', '"' . $ev['title'] . '" stays on ' . $cal['label'] . '.');
        redirect($here);
    } elseif ($action === 'copy_year') {
        $from = (int) post('year_from', 0);
        $to = $from + 1;
        if ($from < 2000 || $from > 2100 || !in_array($from, $years, true)) {
            flash('err', 'Pick a year that has events on this calendar.');
            redirect($here);
        }
        $src = rows('SELECT * FROM events WHERE cal_key = ? AND start_date >= ? AND start_date <= ? ORDER BY start_date, id',
            [$calKey, $from . '-01-01', $from . '-12-31']);
        if ($src === []) {
            flash('err', 'No ' . $from . ' events to copy.');
            redirect($here);
        }
        $result = tx(static function () use ($src, $calKey, $from, $to, $cals, $groupId, $userId): array {
            // Skip a copy when the target year already has the same title on the shifted start date (re-running is safe).
            $have = [];
            foreach (rows('SELECT title, start_date FROM events WHERE cal_key = ? AND start_date >= ? AND start_date <= ?', [$calKey, $to . '-01-01', $to . '-12-31']) as $x) {
                $have[mb_strtolower((string) $x['title']) . '|' . $x['start_date']] = true;
            }
            $copied = 0;
            $skipped = 0;
            foreach ($src as $ev) {
                $s = to_date((string) $ev['start_date']);
                $e = to_date((string) $ev['end_date']);
                if ($s === null || $e === null) {
                    continue;
                }
                // +1 year: Feb 29 rolls to Mar 1 in a non-leap year (same as the engine's cycle math).
                $ns = ymd($s->modify('+1 year'));
                $ne = ymd($e->modify('+1 year'));
                $key = mb_strtolower((string) $ev['title']) . '|' . $ns;
                if (isset($have[$key])) {
                    $skipped++;
                    continue;
                }
                $data = [
                    'cal_key'    => $calKey,
                    'title'      => $ev['title'],
                    'start_date' => $ns,
                    'end_date'   => $ne,
                    'is_holiday' => (int) $ev['is_holiday'],
                    'created_at' => now_str(), 'created_by' => $userId,
                    'updated_at' => now_str(), 'updated_by' => $userId,
                ];
                $newId = insert('events', $data);
                $after = row('SELECT * FROM events WHERE id = ?', [$newId]);
                audit('insert', 'events', $newId, null, $groupId, null, $after, events_summary($cals, $after, 'copied from ' . $from));
                $have[$key] = true;
                $copied++;
            }
            return [$copied, $skipped];
        });
        group_holidays_reset();
        $msg = 'Copied ' . plural($result[0], 'event') . ' from ' . $from . ' to ' . $to
            . ($result[1] > 0 ? ' (' . $result[1] . ' already there, skipped)' : '') . '.';
        if ($result[0] > 0) {
            $msg .= ' Check floating holidays (Thanksgiving, Memorial Day, Labor Day, Easter) and fix their dates.';
            $msg = rtrim($msg . ' ' . events_after_write($groupId, $calKey));
        }
        flash('ok', $msg);
        redirect(events_url($group, $calKey, (string) $to));
    } else {
        flash('err', 'Unknown action.');
        redirect($here);
    }
}

// ------------------------------------------------------------------ data for the page

$flaggedIds = events_setting_ids('misfiled_events');
$dismissedIds = events_setting_ids('misfiled_dismissed');

// Flag counts per calendar (all years) for the tab badges and the "in other years" hint.
$flagCount = array_fill_keys(array_keys($cals), 0);
$sql = 'SELECT e.id, e.cal_key, e.title FROM events e JOIN calendars c ON c.cal_key = e.cal_key WHERE c.group_id = ? AND c.kind = ?';
foreach (rows($sql, [$groupId, 'events']) as $ev) {
    if (isset($flagCount[$ev['cal_key']]) && events_is_flagged($ev, $group, $flaggedIds, $dismissedIds)) {
        $flagCount[$ev['cal_key']]++;
    }
}

$where = 'cal_key = ?';
$p = [$calKey];
if ($year !== 'all') {
    $where .= ' AND start_date >= ? AND start_date <= ?';
    $p[] = $year . '-01-01';
    $p[] = $year . '-12-31';
}
$list = rows("SELECT * FROM events WHERE $where ORDER BY start_date DESC, id DESC", $p);

$copyYear = $year !== 'all' ? (int) $year : ($years[0] ?? null);
$holidaysFrom = group_holidays_from($group);
$otherCals = array_filter($cals, static fn(array $c): bool => $c['cal_key'] !== $calKey);
$addValues = $formValues !== null && $editId === 0 ? $formValues : ['title' => '', 'start' => '', 'end' => '', 'is_holiday' => false, 'cal_key' => $calKey];

// ------------------------------------------------------------------ page

layout_header('Events', ['css' => ['assets/events.css']]);
echo '<div class="toolbar"><h1>Events</h1><span class="muted">' . h((string) $group['name']) . '</span></div>';

// One tab per events calendar of the group.
echo '<nav class="subtabs">';
foreach ($cals as $k => $c) {
    $active = $k === $calKey ? ' class="active"' : '';
    echo '<a' . $active . ' href="' . h(events_url($group, (string) $k)) . '">' . h((string) $c['label']);
    if ((int) $c['is_active'] !== 1) {
        echo ' <span class="badge">inactive</span>';
    }
    if ($flagCount[$k] > 0) {
        echo ' <span class="badge badge-warn" title="possibly misfiled">' . h((string) $flagCount[$k]) . '</span>';
    }
    echo '</a>';
}
echo '</nav>';

// SPEC section 5: what the holiday checkbox does for this group.
echo '<p class="help">Events marked as a company holiday are not charged against PTO';
echo $holidaysFrom !== null
    ? ' for requests starting on or after ' . h($holidaysFrom->format('m/d/Y')) . ' (Admin &gt; Groups changes that date).'
    : ' &mdash; but the rule is switched off for this group (Admin &gt; Groups sets the start date).';
echo '</p>';

// Year filter + copy button.
echo '<div class="toolbar ev-toolbar">';
echo '<form method="get" action="' . h(app_url('events.php')) . '" class="inline-form">';
echo '<input type="hidden" name="g" value="' . h((string) $group['group_key']) . '"><input type="hidden" name="cal" value="' . h($calKey) . '">';
echo '<label for="year" class="ev-inline">Year</label> <select name="year" id="year">';
echo '<option value="all"' . ($year === 'all' ? ' selected' : '') . '>All years</option>';
foreach ($years as $y) {
    echo '<option value="' . h((string) $y) . '"' . ((string) $y === $year ? ' selected' : '') . '>' . h((string) $y) . '</option>';
}
echo '</select> <button class="btn btn-sm" type="submit">Show</button></form>';
echo '<span class="spacer"></span>';
if ($copyYear !== null) {
    echo events_button_form($here, ['action' => 'copy_year', 'year_from' => $copyYear],
        'Copy ' . $copyYear . ' events to ' . ($copyYear + 1), '',
        'Copy every ' . $copyYear . ' event on ' . $cal['label'] . ' to ' . ($copyYear + 1) . ' (same titles, dates one year later)?');
}
echo '</div>';

if ($formError !== null) {
    echo '<div class="flash flash-err">' . h($formError) . '</div>';
}
if ($year !== 'all' && $flagCount[$calKey] > 0) {
    $visibleFlags = 0;
    foreach ($list as $ev) {
        if (events_is_flagged($ev, $group, $flaggedIds, $dismissedIds)) {
            $visibleFlags++;
        }
    }
    if ($flagCount[$calKey] > $visibleFlags) {
        echo '<p class="help">' . h(plural($flagCount[$calKey] - $visibleFlags, 'possibly misfiled row')) . ' in other years: '
            . '<a href="' . h(events_url($group, $calKey, 'all')) . '">show all years</a>.</p>';
    }
}

// The add and edit forms sit outside the table; their inputs point at them with form="...".
echo '<form id="ev-add" method="post" action="' . h($here) . '">' . csrf_field() . '<input type="hidden" name="action" value="add"></form>';
if ($editId > 0) {
    echo '<form id="ev-edit" method="post" action="' . h($here) . '">' . csrf_field() . '<input type="hidden" name="action" value="update">'
        . '<input type="hidden" name="id" value="' . h((string) $editId) . '"></form>';
}

// Sortable (app.js); the inline add row is data-nosort so it stays at the top whatever the sort.
echo '<div class="table-wrap"><table class="ev-table sortable"><thead><tr>';
echo '<th data-sort="text">Title</th><th data-sort="date">Start</th><th data-sort="date">End</th><th class="num" data-sort="num">Weekdays</th><th data-sort="num">Company holiday</th><th data-sort="text">Calendar</th><th>Actions</th>';
echo '</tr></thead><tbody>';

// Inline add row (SPEC section 7.7).
echo '<tr class="ev-add" id="ev-new" data-nosort>' . events_input_cells('ev-add', $addValues, $cals, false, 'ev-new');
echo '<td></td><td class="ev-actions"><button form="ev-add" class="btn btn-primary btn-sm" type="submit">Add</button></td></tr>';

$editFound = false;
foreach ($list as $ev) {
    $id = (int) $ev['id'];
    $flagged = events_is_flagged($ev, $group, $flaggedIds, $dismissedIds);

    if ($id === $editId) {
        $editFound = true;
        $v = $formValues ?? ['title' => $ev['title'], 'start' => $ev['start_date'], 'end' => $ev['end_date'], 'is_holiday' => (int) $ev['is_holiday'] === 1, 'cal_key' => $ev['cal_key']];
        echo '<tr class="ev-edit" id="ev-' . h((string) $id) . '">' . events_input_cells('ev-edit', $v, $cals, true, 'ev-' . $id);
        echo '<td data-v="' . h(sync_badge_state($ev)) . '">' . sync_badge($ev) . '</td><td class="ev-actions"><button form="ev-edit" class="btn btn-primary btn-sm" type="submit">Save</button> '
            . '<a class="btn btn-sm" href="' . h($here) . '">Cancel</a></td></tr>';
        continue;
    }

    echo '<tr id="ev-' . h((string) $id) . '"' . ($flagged ? ' class="ev-flagged"' : '') . '>';
    echo '<td>' . h((string) $ev['title']);
    if ($flagged) {
        echo '<div class="ev-flag"><span class="badge badge-warn">possibly misfiled</span> ';
        foreach ($otherCals as $k => $c) {
            echo events_button_form($here, ['action' => 'move', 'id' => $id, 'to_cal' => $k], 'Move to ' . $c['label'], '');
        }
        echo events_button_form($here, ['action' => 'keep', 'id' => $id], 'Keep here', '');
        echo '</div>';
    }
    echo '</td>';
    echo '<td data-v="' . h((string) $ev['start_date']) . '">' . h(fmt_date((string) $ev['start_date'])) . '</td>';
    echo '<td data-v="' . h((string) $ev['end_date']) . '">' . h(fmt_date((string) $ev['end_date'])) . '</td>';
    echo '<td class="num">' . h((string) events_weekdays($ev)) . '</td>';
    echo '<td data-v="' . ((int) $ev['is_holiday'] === 1 ? '1' : '0') . '">' . ((int) $ev['is_holiday'] === 1 ? '<span class="badge badge-ok">Company holiday</span>' : '<span class="muted">-</span>') . '</td>';
    echo '<td data-v="' . h(sync_badge_state($ev)) . '">' . sync_badge($ev) . '</td>';
    echo '<td class="ev-actions"><a class="btn btn-sm" href="' . h(events_url($group, $calKey, $year, ['edit' => $id])) . '#ev-' . h((string) $id) . '">Edit</a> ';
    echo events_button_form($here, ['action' => 'delete', 'id' => $id], 'Delete', 'btn-danger',
        'Delete "' . $ev['title'] . '" (' . fmt_date((string) $ev['start_date']) . ')? It can be restored from History.');
    echo '</td></tr>';
}
if ($list === []) {
    echo '<tr><td colspan="7" class="muted">No events on ' . h((string) $cal['label']) . ($year !== 'all' ? ' in ' . h($year) : '') . ' yet. Use the row above to add one.</td></tr>';
}
echo '</tbody></table></div>';

if ($editId > 0 && !$editFound) {
    echo '<p class="help">The event you wanted to edit is not on this calendar/year. <a href="' . h(events_url($group, $calKey, 'all', ['edit' => $editId])) . '">Look in all years</a>.</p>';
}
echo '<p class="help">' . h(plural(count($list), 'event')) . ' shown, newest first. Weekdays = Monday to Friday days inside the event.</p>';

layout_footer();

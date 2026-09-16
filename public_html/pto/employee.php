<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Employee detail (SPEC section 7.6): employee.php?id=N
 * - Profile edit. A hire-date change shows a before/after balance table per cycle and needs a confirm checkbox.
 *   A name change warns that calendar titles will change (live in assets/employee.js and in the saved flash).
 * - Cycle-by-cycle ledger from employee_ledger(): allotment, adjustments (with reasons), used, remaining, every
 *   request with its running balance. Newest cycle first; current and next cycles open, older ones collapsed.
 * - Adjustments panel: grant/deduct, kind, effective date with the resulting cycle shown, mandatory reason; delete.
 * - "Mark as departed on <date>": sets status/departed_on, then sync_depart_employee() removes their calendar events
 *   right away (result in the flash); Un-depart tops up birthday rows and marks both calendars dirty (SPEC 14.3).
 * - Profile save (name / birthday): birthday_rows_topup() + mark_dirty() on the PTO and birthday calendars + inline pass.
 * - The ledger shows each request's calendar state (synced / pending / error, SPEC 14.5).
 * - Export CSV of the ledger: employee.php?id=N&export=csv
 * Every write goes through tx() with an audit() row; summaries read like "Walker, Rebecca: hire date 2012-03-08 -> 2012-03-10".
 *
 * Private helpers are prefixed employee_; the profile fields (read / validate / render) live in lib/employees.php
 * and are shared with employees.php.
 */

$user = require_login();
$idParam = get('id');
if (!is_string($idParam) || !ctype_digit($idParam) || (int) $idParam <= 0) {
    // The group tabs link here as employee.php?g=<key>: send them to the list for that group.
    $g = get('g');
    redirect('employees.php' . (is_string($g) && $g !== '' ? '?g=' . rawurlencode($g) : ''));
}
$id = (int) $idParam;
$eg = employee_with_group($id);
if ($eg === null) {
    layout_error_page(404, 'Employee not found', 'No employee has id ' . $id . '.', 'employees.php', 'All employees');
}
$employee = $eg['employee'];
$group = $eg['group'];
$groupId = (int) $group['id'];
if (!user_can_group($user, $groupId)) {
    layout_error_page(403, 'Not allowed', 'Your account is limited to another group.', 'employees.php', 'All employees');
}
// The group tabs should highlight this employee's group even when the session remembered the other one.
$_SESSION['group_key'] = $group['group_key'];
$policy = group_policy($group);
$kinds = $policy['kinds'];
$today = group_today($group);
$hire = to_date($employee['hire_date']) ?? $today;

// ---- private helpers ----------------------------------------------------------------------------------------

/** Audit summary for a profile save: "Walker, Rebecca: hire date 2012-03-08 -> 2012-03-10; birthday 02/18 -> 02/19". */
function employee_profile_summary(array $before, array $after, array $changed): string
{
    $parts = [];
    if (isset($changed['name'])) {
        $parts[] = 'name -> ' . $after['name'];
    }
    if (isset($changed['hire_date'])) {
        $parts[] = 'hire date ' . $before['hire_date'] . ' -> ' . $after['hire_date'];
    }
    if (array_key_exists('birth_month', $changed) || array_key_exists('birth_day', $changed) || array_key_exists('birth_year', $changed)) {
        $parts[] = 'birthday ' . (employee_birthday($before) ?: 'none') . ' -> ' . (employee_birthday($after) ?: 'none');
    }
    if (array_key_exists('notes', $changed)) {
        $parts[] = 'notes updated';
    }
    return $before['name'] . ': ' . implode('; ', $parts);
}

/** Export CSV of the ledger (chronological): cycle summary rows, adjustments and every request. */
function employee_export_csv(array $employee, array $group, array $kinds, array $cycles): never
{
    $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $employee['name']), '_');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ledger_' . $slug . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    $head = ['Employee', 'Group', 'Cycle start', 'Cycle end', 'Years of service', 'Type', 'Kind', 'Start', 'End', 'Days', 'Days in cycle'];
    foreach ($kinds as $k) {
        $head[] = $k . ' remaining after';
    }
    $head[] = 'Note';
    csv_row($out, $head);
    $blank = array_fill(0, count($kinds), '');
    foreach ($cycles as $c) {
        $cs = ymd($c['start']);
        $ce = ymd($c['end']->modify('-1 day'));
        // Free-text cells (name, reason, note) go through csv_text() so Excel never runs them as formulas.
        $base = [csv_text($employee['name']), $group['name'], $cs, $ce, (string) $c['yos']];
        $sum = [];
        foreach ($kinds as $k) {
            $sum[] = sprintf('%s: allotment %s, adjustments %s, used %s, remaining %s', $k, fmt_days($c['allotment'][$k]),
                signed_days($c['adjusted'][$k]), fmt_days($c['used'][$k]), fmt_days($c['remaining'][$k]));
        }
        csv_row($out, array_merge($base, ['Cycle', '', $cs, $ce, '', ''], $blank, [implode('; ', $sum)]));
        foreach ($c['adjustments'] as $a) {
            csv_row($out, array_merge($base, ['Adjustment', $a['kind'], $a['effective_date'], '', signed_days((float) $a['days']), ''], $blank,
                [csv_text($a['reason'])]));
        }
        foreach ($c['requests'] as $r) {
            $rem = [];
            foreach ($kinds as $k) {
                $rem[] = fmt_days($r['remaining_after'][$k]);
            }
            $note = (string) ($r['note'] ?? '');
            if ($r['note_after'] !== null) {
                $note = trim($note . ' ' . $r['note_after']);
            }
            csv_row($out, array_merge($base, ['Time off', $r['kind'], ymd($r['start']), ymd($r['end']), fmt_days($r['days']),
                $r['split'] ? fmt_days($r['days_in_cycle']) : ''], $rem, [csv_text($note)]));
        }
    }
    fclose($out);
    exit;
}

/** Before/after balance table when the hire date changes; cycles aligned by years of service at cycle start. */
function employee_render_hire_diff(array $kinds, array $before, array $after, string $oldHire, string $newHire): void
{
    $byYos = [];
    foreach ($before as $c) {
        $byYos[$c['yos']]['before'] = $c;
    }
    foreach ($after as $c) {
        $byYos[$c['yos']]['after'] = $c;
    }
    ksort($byYos);
    echo '<div class="inline-warn"><b>Hire date ' . h(fmt_date($oldHire)) . ' &rarr; ' . h(fmt_date($newHire)) . '.</b> '
        . 'Every cycle boundary moves and every balance is recomputed. Check the table, then tick the box below to save. Nothing has been saved yet.</div>';
    echo '<div class="table-wrap"><table class="diff"><thead><tr><th class="num">Yrs</th><th class="before">Before: cycle</th>';
    foreach ($kinds as $k) {
        echo '<th class="num">' . h($k) . ' left / allot.</th>';
    }
    echo '<th class="after">After: cycle</th>';
    foreach ($kinds as $k) {
        echo '<th class="num">' . h($k) . ' left / allot.</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($byYos as $yos => $pair) {
        $b = $pair['before'] ?? null;
        $a = $pair['after'] ?? null;
        echo '<tr><td class="num">' . h((string) $yos) . '</td>';
        foreach (['before' => $b, 'after' => $a] as $side => $c) {
            $other = $side === 'before' ? $a : $b;
            if ($c === null) {
                echo '<td class="first muted">-</td>';
                foreach ($kinds as $k) {
                    echo '<td class="num muted">-</td>';
                }
                continue;
            }
            $flag = ($c['is_current'] ? ' <span class="badge badge-ok">current</span>' : '') . ($c['is_next'] ? ' <span class="badge badge-warn">next</span>' : '');
            echo '<td class="first">' . h(cycle_label($c)) . $flag . '</td>';
            foreach ($kinds as $k) {
                $allot = $c['allotment'][$k] + $c['adjusted'][$k];
                $changed = $other === null || $other['remaining'][$k] != $c['remaining'][$k]
                    || ($other['allotment'][$k] + $other['adjusted'][$k]) != $allot;
                echo '<td class="num' . ($changed ? ' changed' : '') . balance_class($c['remaining'][$k]) . '">' . h(fmt_days($c['remaining'][$k])) . ' / ' . h(fmt_days($allot)) . '</td>';
            }
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<label class="check"><input type="checkbox" name="confirm_hire" value="1" required> I have checked the balances above; change the hire date.</label>';
}

/**
 * SPEC 14.3 hook after a profile change / un-depart: birthday rows for this year and next, the PTO and birthday
 * calendars dirty, then the inline pass. Returns the engine's sentence for the flash.
 */
function employee_sync_after(int $groupId): string
{
    birthday_rows_topup($groupId);
    foreach (array_merge(group_calendars($groupId, 'pto'), group_calendars($groupId, 'birthdays')) as $c) {
        mark_dirty((string) $c['cal_key']);
    }
    return sync_dirty_inline($groupId);
}

/** The cycle-by-cycle ledger, newest cycle first. $syncRows: time_off id => row (google_event_id, sync_error). */
function employee_render_ledger(array $kinds, array $cycles, array $employee, array $syncRows = []): void
{
    foreach (array_reverse($cycles, true) as $c) {
        $open = $c['is_current'] || $c['is_next'];
        if ($c['yos'] < 0) {
            $badge = '<span class="badge badge-err">before hire date</span>';
        } elseif ($c['is_current']) {
            $badge = '<span class="badge badge-ok">current</span>';
        } elseif ($c['is_next']) {
            $badge = '<span class="badge badge-warn">next</span>';
        } elseif ($c['is_future']) {
            $badge = '<span class="badge">future</span>';
        } else {
            $badge = '<span class="badge">past</span>';
        }
        echo '<details class="cycle' . ($c['is_current'] ? ' current' : '') . '"' . ($open ? ' open' : '') . ' id="cycle-' . h(ymd($c['start'])) . '">';
        echo '<summary><span>' . h(cycle_label($c)) . '</span>' . $badge . '<span class="muted">' . h((string) $c['yos']) . ' yrs of service</span><span class="spacer"></span>';
        foreach ($kinds as $k) {
            $allot = $c['allotment'][$k] + $c['adjusted'][$k];
            echo '<span class="kind-sum">' . h($k) . ' <b class="' . h(trim(balance_class($c['remaining'][$k]))) . '">' . h(fmt_days($c['remaining'][$k])) . '</b> / ' . h(fmt_days($allot)) . '</span>';
        }
        echo '</summary><div class="cycle-body">';
        echo '<div class="kinds">';
        foreach ($kinds as $k) {
            echo '<span><b>' . h($k) . '</b>: allotment ' . h(fmt_days($c['allotment'][$k]))
                . ($c['adjusted'][$k] != 0 ? ', adjustments ' . h(signed_days($c['adjusted'][$k])) : '')
                . ', used ' . h(fmt_days($c['used'][$k])) . ', remaining <span class="' . h(trim(balance_class($c['remaining'][$k]))) . '">' . h(fmt_days($c['remaining'][$k])) . '</span></span>';
        }
        echo '</div>';
        if ($c['adjustments'] !== []) {
            echo '<ul class="adjlist">';
            foreach ($c['adjustments'] as $a) {
                $d = (float) $a['days'];
                echo '<li><span class="' . ($d > 0 ? 'pos' : 'neg') . '">' . h(signed_days($d)) . ' ' . h($a['kind']) . '</span> effective ' . h(fmt_date($a['effective_date'])) . ': ' . h($a['reason']) . '</li>';
            }
            echo '</ul>';
        }
        if ($c['requests'] === []) {
            echo '<p class="muted">No time off in this cycle.</p>';
        } else {
            echo '<div class="table-wrap"><table><thead><tr><th>Kind</th><th>Start</th><th>End</th><th class="num">Days</th>';
            foreach ($kinds as $k) {
                echo '<th class="num">' . h($k) . ' after</th>';
            }
            echo '<th>Note</th><th>Calendar</th><th></th></tr></thead><tbody>';
            foreach ($c['requests'] as $r) {
                echo '<tr><td>' . h($r['kind']) . '</td><td>' . h($r['start']->format('m/d/Y')) . '</td><td>' . h($r['end']->format('m/d/Y')) . '</td>';
                $days = fmt_days($r['days_in_cycle']);
                if ($r['split']) {
                    $days .= ' <span class="help">of ' . h(fmt_days($r['days'])) . ' (split)</span>';
                }
                echo '<td class="num">' . $days . '</td>';
                foreach ($kinds as $k) {
                    echo '<td class="num' . balance_class($r['remaining_after'][$k]) . '">' . h(fmt_days($r['remaining_after'][$k])) . '</td>';
                }
                echo '<td>' . h((string) ($r['note'] ?? '')) . ($r['note_after'] !== null ? '<span class="note-after">' . h($r['note_after']) . '</span>' : '') . '</td>';
                echo '<td>' . sync_badge($r['id'] !== null ? ($syncRows[(int) $r['id']] ?? null) : null) . '</td>';
                echo '<td class="num">' . ($r['id'] !== null ? '<a class="btn btn-sm" href="' . h(app_url('request.php?id=' . (int) $r['id'])) . '">Edit</a>' : '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></details>';
    }
}

// ---- CSV export ----------------------------------------------------------------------------------------------
if (get('export') === 'csv') {
    employee_export_csv($employee, $group, $kinds, employee_ledger($id, $today));
}

// ---- POST actions --------------------------------------------------------------------------------------------
$profileErrors = [];
$profileValues = employee_values_from_row($group, $employee);
$hireDiff = null;
$adjErrors = [];
$adjValues = ['kind' => $kinds[0], 'sign' => 'grant', 'days' => '', 'effective_date' => ymd($today), 'reason' => ''];
$departErrors = [];
$departValue = $employee['departed_on'] ?? ymd($today);
$self = 'employee.php?id=' . $id;

if (is_post()) {
    $action = (string) post('action', '');

    if ($action === 'profile') {
        $in = employee_read_profile($group, false);
        $profileValues = $in['values'];
        $profileErrors = $in['errors'];
        if ($profileErrors === []) {
            $new = $in['row'];
            $changed = [];
            foreach ($new as $k => $val) {
                if ((string) ($employee[$k] ?? '') !== (string) ($val ?? '')) {
                    $changed[$k] = $val;
                }
            }
            if ($changed === []) {
                flash('ok', 'No changes to save.');
                redirect($self);
            }
            if (isset($changed['hire_date']) && post('confirm_hire') !== '1') {
                // SPEC 7.6: a hire-date change shows the before/after balance table and needs the confirm checkbox.
                $hireDiff = [
                    'before' => employee_ledger($id, $today),
                    'after'  => employee_ledger_for($group, array_merge($employee, ['hire_date' => $new['hire_date']]), $today),
                    'old'    => (string) $employee['hire_date'],
                    'new'    => (string) $new['hire_date'],
                ];
            } else {
                $summary = tx(static function () use ($id, $groupId, $employee, $new, $changed, $user): string {
                    update_row('employees', $new + ['updated_at' => now_str(), 'updated_by' => (int) $user['id']], 'id = ?', [$id]);
                    $after = row('SELECT * FROM employees WHERE id = ?', [$id]);
                    $summary = employee_profile_summary($employee, $after, $changed);
                    audit('update', 'employees', $id, $id, $groupId, $employee, $after, $summary);
                    return $summary;
                });
                $msg = 'Profile saved (' . $summary . ').';
                if (isset($changed['name'])) {
                    $msg .= ' The calendar event titles for this employee change with the next sync.';
                }
                // SPEC 14.3 hook: a name or birthday change touches the mirrored calendars.
                if (array_intersect_key($changed, array_flip(['name', 'birth_month', 'birth_day', 'birth_year'])) !== []) {
                    $msg = rtrim($msg . ' ' . employee_sync_after($groupId));
                }
                flash('ok', $msg);
                redirect($self);
            }
        }
    } elseif ($action === 'adjust_add') {
        $adjValues = [
            'kind'           => (string) post('kind', $kinds[0]),
            'sign'           => (string) post('sign', 'grant'),
            'days'           => (string) post('days', ''),
            'effective_date' => (string) post('effective_date', ''),
            'reason'         => (string) preg_replace('/\s+/', ' ', (string) post('reason', '')),
        ];
        if (!in_array($adjValues['kind'], $kinds, true)) {
            $adjErrors[] = 'Pick a kind: ' . implode(' or ', $kinds) . '.';
        }
        if (!in_array($adjValues['sign'], ['grant', 'deduct'], true)) {
            $adjErrors[] = 'Choose grant or deduct.';
        }
        $days = is_numeric($adjValues['days']) ? round((float) $adjValues['days'], 2) : 0.0;
        if ($days <= 0 || $days > 999) {
            $adjErrors[] = 'Days must be a positive number (fractions such as 0.5 are fine).';
        }
        $eff = to_date($adjValues['effective_date']);
        if ($eff === null) {
            $adjErrors[] = 'Enter the effective date as YYYY-MM-DD.';
        }
        if ($adjValues['reason'] === '') {
            $adjErrors[] = 'A reason is required.';   // SPEC 7.6: mandatory reason
        } elseif (mb_strlen($adjValues['reason']) > 255) {
            $adjErrors[] = 'The reason is too long (255 characters max).';
        }
        if ($adjErrors === []) {
            $signed = $adjValues['sign'] === 'deduct' ? -$days : $days;
            // SPEC section 4 rule 4: the adjustment lands in the cycle whose [start, end) contains effective_date.
            $cycleStart = cycle_start($hire, $eff);
            $label = $cycleStart->format('m/d/Y') . ' - ' . cycle_end($cycleStart)->modify('-1 day')->format('m/d/Y');
            $adjRow = [
                'employee_id'    => $id,
                'kind'           => $adjValues['kind'],
                'effective_date' => ymd($eff),
                'days'           => number_format($signed, 2, '.', ''),
                'reason'         => $adjValues['reason'],
                'created_at'     => now_str(),
                'created_by'     => (int) $user['id'],
            ];
            tx(static function () use ($adjRow, $id, $groupId, $employee, $signed, $cycleStart): void {
                $aid = insert('adjustments', $adjRow);
                $after = row('SELECT * FROM adjustments WHERE id = ?', [$aid]);
                audit('insert', 'adjustments', $aid, $id, $groupId, null, $after,
                    sprintf('%s: %s %s adjustment for the cycle starting %s (%s)', $employee['name'], $adjRow['kind'],
                        signed_days($signed), ymd($cycleStart), $adjRow['reason']));
            });
            flash('ok', sprintf('Adjustment saved: %s %s applies to the cycle %s.', signed_days($signed), $adjValues['kind'], $label));
            redirect($self);
        }
    } elseif ($action === 'adjust_delete') {
        $aid = (int) post('adjustment_id', 0);
        $a = row('SELECT * FROM adjustments WHERE id = ? AND employee_id = ?', [$aid, $id]);
        if ($a === null) {
            flash('err', 'That adjustment no longer exists.');
        } else {
            $cycleStart = cycle_start($hire, to_date($a['effective_date']) ?? $today);
            tx(static function () use ($a, $aid, $id, $groupId, $employee, $cycleStart): void {
                q('DELETE FROM adjustments WHERE id = ?', [$aid]);
                audit('delete', 'adjustments', $aid, $id, $groupId, $a, null,
                    sprintf('%s: %s %s adjustment for the cycle starting %s deleted (%s)', $employee['name'], $a['kind'],
                        signed_days((float) $a['days']), ymd($cycleStart), $a['reason']));
            });
            flash('ok', 'Adjustment deleted.');
        }
        redirect($self);
    } elseif ($action === 'depart') {
        $departValue = (string) post('departed_on', '');
        $d = to_date($departValue);
        if ($d === null) {
            $departErrors[] = 'Enter the departure date as YYYY-MM-DD.';
        } elseif ($d < $hire) {
            $departErrors[] = 'The departure date is before the hire date ' . fmt_date($hire) . '.';
        } elseif ($employee['status'] === 'departed' && $employee['departed_on'] === ymd($d)) {
            $departErrors[] = 'Already marked as departed on that date.';
        }
        if ($departErrors === []) {
            tx(static function () use ($id, $groupId, $employee, $d, $user): void {
                update_row('employees', ['status' => 'departed', 'departed_on' => ymd($d), 'updated_at' => now_str(), 'updated_by' => (int) $user['id']], 'id = ?', [$id]);
                $after = row('SELECT * FROM employees WHERE id = ?', [$id]);
                $summary = $employee['status'] === 'departed'
                    ? sprintf('%s: departure date %s -> %s', $employee['name'], $employee['departed_on'], ymd($d))
                    : sprintf('%s: marked departed on %s', $employee['name'], ymd($d));
                audit('update', 'employees', $id, $id, $groupId, $employee, $after, $summary);
            });
            // SPEC 14.3: delete their calendar events right away (pto and birthday rows); leftovers are finished by the
            // departed-cleanup step of the next sync, so both calendars are marked dirty too.
            $dep = sync_depart_employee($id);
            foreach (array_merge(group_calendars($groupId, 'pto'), group_calendars($groupId, 'birthdays')) as $c) {
                mark_dirty((string) $c['cal_key']);
            }
            flash((int) ($dep['failed'] ?? 0) > 0 ? 'err' : 'ok', $employee['name'] . ' is marked as departed on ' . fmt_date($d)
                . '. They are off the viewer page; history is kept. Calendar: ' . (string) ($dep['message'] ?? ''));
            redirect($self);
        }
    } elseif ($action === 'undepart') {
        if ($employee['status'] !== 'departed') {
            flash('err', $employee['name'] . ' is not marked as departed.');
        } else {
            tx(static function () use ($id, $groupId, $employee, $user): void {
                update_row('employees', ['status' => 'active', 'departed_on' => null, 'updated_at' => now_str(), 'updated_by' => (int) $user['id']], 'id = ?', [$id]);
                $after = row('SELECT * FROM employees WHERE id = ?', [$id]);
                audit('update', 'employees', $id, $id, $groupId, $employee, $after,
                    sprintf('%s: un-departed (was departed on %s)', $employee['name'], (string) $employee['departed_on']));
            });
            // SPEC 14.3: rows have NULL ids after the departure deletes, so they re-insert on the next pass.
            flash('ok', rtrim($employee['name'] . ' is active again. ' . employee_sync_after($groupId)));
        }
        redirect($self);
    }
}

// ---- Page ----------------------------------------------------------------------------------------------------
$cycles = employee_ledger($id, $today);
$current = null;
$next = null;
foreach ($cycles as $c) {
    if ($c['is_current']) {
        $current = $c;
    }
    if ($c['is_next']) {
        $next = $c;
    }
}
$adjustments = employee_adjustments($id);
$existingNames = array_map(static fn(array $r): string => (string) $r['name'],
    rows('SELECT name FROM employees WHERE group_id = ? AND id <> ?', [$groupId, $id]));

layout_header($employee['name'], ['css' => ['assets/employees.css'], 'js' => ['assets/employee.js']]);

echo '<div class="emp-head"><h1>' . h($employee['name']) . '</h1>';
echo $employee['status'] === 'departed'
    ? '<span class="badge badge-err">departed ' . h(fmt_date($employee['departed_on'])) . '</span>'
    : '<span class="badge badge-ok">active</span>';
echo '<span class="muted">' . h($group['name']) . '</span><span class="spacer"></span>';
$g = '?g=' . rawurlencode((string) $group['group_key']);
echo '<a class="btn btn-primary" href="' . h(app_url('request.php') . $g . '&employee=' . $id) . '">+ Time off</a> ';
echo '<a class="btn" href="' . h(app_url($self . '&export=csv')) . '">Export CSV</a> ';
echo '<a class="btn" href="' . h(app_url('employees.php') . $g . ($employee['status'] === 'departed' ? '&tab=former' : '')) . '">All employees</a></div>';

echo '<div class="card"><div class="emp-meta">';
echo '<div><div class="k">Hire date</div><div class="v">' . h(fmt_date($employee['hire_date'])) . '</div></div>';
echo '<div><div class="k">Years of service</div><div class="v">' . h((string) years_of_service($hire, $today)) . '</div></div>';
echo '<div><div class="k">Birthday</div><div class="v">' . h(employee_birthday($employee) ?: '-') . '</div></div>';
if ($current !== null) {
    echo '<div><div class="k">Current cycle</div><div class="v">' . h(cycle_label($current)) . '</div></div>';
    foreach ($kinds as $k) {
        $allot = $current['allotment'][$k] + $current['adjusted'][$k];
        echo '<div><div class="k">' . h($k) . ' left / allot.</div><div class="v' . balance_class($current['remaining'][$k]) . '">' . h(fmt_days($current['remaining'][$k])) . ' / ' . h(fmt_days($allot)) . '</div></div>';
    }
}
if ($next !== null) {
    $parts = [];
    foreach ($kinds as $k) {
        $parts[] = fmt_days($next['remaining'][$k]) . (count($kinds) > 1 ? ' ' . policy_kind_label($k) : '');
    }
    echo '<div><div class="k">After ' . h($next['start']->format('m/d/Y')) . '</div><div class="v">' . h(implode(' / ', $parts)) . '</div></div>';
}
echo '</div>';
if ($employee['notes'] !== null && $employee['notes'] !== '') {
    echo '<p class="help">Notes: ' . h($employee['notes']) . '</p>';
}
echo '</div>';

// Profile
echo '<div class="card"><h2>Profile</h2>';
if ($profileErrors !== []) {
    echo '<div class="inline-err"><ul>';
    foreach ($profileErrors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
echo '<form method="post" data-name-form data-original-name="' . h($employee['name']) . '" data-existing-names="' . h(json_encode($existingNames, JSON_UNESCAPED_UNICODE) ?: '[]') . '">';
echo csrf_field() . '<input type="hidden" name="action" value="profile">';
employee_render_profile_fields($group, $profileValues, false);
if ($hireDiff !== null) {
    employee_render_hire_diff($kinds, $hireDiff['before'], $hireDiff['after'], $hireDiff['old'], $hireDiff['new']);
}
echo '<div class="actions"><button class="btn btn-primary" type="submit">Save profile</button>';
if ($hireDiff !== null) {
    echo '<a class="btn" href="' . h(app_url($self)) . '">Discard</a>';
}
echo '</div></form></div>';

// Adjustments + departure side by side
echo '<div class="two-col">';
echo '<div class="card"><h2>Adjustments</h2>';
echo '<p class="help">A grant or deduction added to the allotment of the cycle that contains the effective date (SPEC rule 4).</p>';
if ($adjustments === []) {
    echo '<p class="muted">No adjustments.</p>';
} else {
    echo '<div class="table-wrap"><table><thead><tr><th>Effective</th><th>Kind</th><th class="num">Days</th><th>Cycle</th><th>Reason</th><th></th></tr></thead><tbody>';
    foreach ($adjustments as $a) {
        $d = (float) $a['days'];
        $cs = cycle_start($hire, to_date($a['effective_date']) ?? $today);
        echo '<tr><td>' . h(fmt_date($a['effective_date'])) . '</td><td>' . h($a['kind']) . '</td>';
        echo '<td class="num ' . ($d > 0 ? 'pos' : 'neg') . '">' . h(signed_days($d)) . '</td>';
        echo '<td><a href="#cycle-' . h(ymd($cs)) . '">' . h($cs->format('m/d/Y')) . ' - ' . h(cycle_end($cs)->modify('-1 day')->format('m/d/Y')) . '</a></td>';
        echo '<td>' . h($a['reason']) . '</td>';
        echo '<td class="num"><form method="post" data-confirm="Delete this ' . h(signed_days($d) . ' ' . $a['kind']) . ' adjustment?">' . csrf_field()
            . '<input type="hidden" name="action" value="adjust_delete"><input type="hidden" name="adjustment_id" value="' . (int) $a['id'] . '">'
            . '<button class="btn btn-sm btn-danger" type="submit">Delete</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '<h3>Add adjustment</h3>';
if ($adjErrors !== []) {
    echo '<div class="inline-err"><ul>';
    foreach ($adjErrors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
echo '<form method="post" class="adj-form" data-adjust-form data-hire-date="' . h(ymd($hire)) . '">' . csrf_field() . '<input type="hidden" name="action" value="adjust_add">';
echo '<div class="days-row">';
echo '<div><label>Grant or deduct</label><div class="segmented">';
echo '<label><input type="radio" name="sign" value="grant"' . ($adjValues['sign'] !== 'deduct' ? ' checked' : '') . '><span>Grant (+)</span></label>';
echo '<label><input type="radio" name="sign" value="deduct"' . ($adjValues['sign'] === 'deduct' ? ' checked' : '') . '><span>Deduct (-)</span></label>';
echo '</div></div>';
echo '<div><label for="adj_days">Days</label><input type="number" id="adj_days" name="days" step="0.01" min="0.01" max="999" required value="' . h($adjValues['days']) . '"></div>';
if (count($kinds) > 1) {
    echo '<div><label for="adj_kind">Kind</label><select id="adj_kind" name="kind">';
    foreach ($kinds as $k) {
        echo '<option value="' . h($k) . '"' . ($adjValues['kind'] === $k ? ' selected' : '') . '>' . h($k) . '</option>';
    }
    echo '</select></div>';
} else {
    echo '<input type="hidden" name="kind" value="' . h($kinds[0]) . '">';
}
echo '</div>';
echo '<label for="adj_date">Effective date</label><input type="date" id="adj_date" name="effective_date" required data-adjust-date value="' . h($adjValues['effective_date']) . '">';
echo '<p class="cycle-preview" data-cycle-preview></p>';
echo '<label for="adj_reason">Reason <span class="help">(required)</span></label><input type="text" id="adj_reason" name="reason" maxlength="255" required value="' . h($adjValues['reason']) . '">';
echo '<div class="actions"><button class="btn btn-primary" type="submit">Add adjustment</button></div></form>';
echo '</div>';

echo '<div class="card depart"><h2>Departure</h2>';
if ($departErrors !== []) {
    echo '<div class="inline-err"><ul>';
    foreach ($departErrors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
if ($employee['status'] === 'departed') {
    echo '<p>Departed on <b>' . h(fmt_date($employee['departed_on'])) . '</b> after ' . h((string) years_of_service($hire, to_date($employee['departed_on']) ?? $today)) . ' years. '
        . 'Off the viewer page; history is kept; their calendar events were removed.</p>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="depart">';
    echo '<label for="departed_on">Departure date</label><input type="date" id="departed_on" name="departed_on" required value="' . h($departValue) . '">';
    echo '<div class="actions"><button class="btn" type="submit">Update date</button></div></form>';
    echo '<form method="post" data-confirm="Make ' . h($employee['name']) . ' active again?">' . csrf_field() . '<input type="hidden" name="action" value="undepart">';
    echo '<div class="actions"><button class="btn btn-primary" type="submit">Un-depart</button></div></form>';
} else {
    echo '<p class="help">Marks the person as former: off the viewer page and dashboard, history kept. Their events are removed from the Google calendars right away.</p>';
    echo '<form method="post" data-confirm="Mark ' . h($employee['name']) . ' as departed?">' . csrf_field() . '<input type="hidden" name="action" value="depart">';
    echo '<label for="departed_on">Departed on</label><input type="date" id="departed_on" name="departed_on" required value="' . h($departValue) . '">';
    echo '<div class="actions"><button class="btn btn-danger" type="submit">Mark as departed</button></div></form>';
}
echo '</div></div>';

// Ledger
echo '<h2>Ledger</h2>';
echo '<p class="help">One block per cycle from the hire date through the next cycle (plus any cycle a request or adjustment touches), newest first. '
    . 'Balances are recomputed from time off and adjustments on every view; the "after" columns are the running balance the sheets showed.</p>';
$syncRows = [];
foreach (rows('SELECT id, google_event_id, sync_error FROM time_off WHERE employee_id = ?', [$id]) as $tr) {
    $syncRows[(int) $tr['id']] = $tr;
}
employee_render_ledger($kinds, $cycles, $employee, $syncRows);
layout_footer();

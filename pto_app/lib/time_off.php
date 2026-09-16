<?php
declare(strict_types=1);

/**
 * time_off rows: working days, audit summaries and the insert / update / delete writes shared by request.php
 * (the form) and requests.php (the list). Every write here runs inside the caller's transaction (tx() joins an
 * outer one) and writes its audit row in the same transaction (SPEC section 11).
 */

/** Working days a time_off row charges: weekdays (SPEC section 3 rule 3; the section 5 holiday exclusion is retired and dormant). */
function time_off_days(array $group, array $row): int
{
    $s = to_date($row['start_date']);
    $e = to_date($row['end_date']);
    if ($s === null || $e === null) {
        return 0;
    }
    $applies = holidays_for_request($row, group_holidays((int) $group['id']), group_holidays_from($group));
    return working_days($s, $e, $applies);
}

/** Audit summary in the SPEC section 11 style: "Walker, Rebecca: Vacation 2026-10-11..2026-10-13 (3 days) added". */
function time_off_summary(string $employeeName, array $row, int $days, string $verb): string
{
    return sprintf('%s: %s %s..%s (%s) %s', $employeeName, $row['kind'], $row['start_date'], $row['end_date'], plural($days, 'day'), $verb);
}

/** Insert one time_off row (employee_id, kind, start_date, end_date, note) and audit it. Returns the new id. */
function time_off_insert(array $group, array $employee, array $data, int $userId): int
{
    return tx(static function () use ($group, $employee, $data, $userId): int {
        $data += ['created_at' => now_str(), 'created_by' => $userId, 'updated_at' => now_str(), 'updated_by' => $userId];
        $id = insert('time_off', $data);
        $after = row('SELECT * FROM time_off WHERE id = ?', [$id]) ?? $data;
        audit('insert', 'time_off', $id, (int) $employee['id'], (int) $group['id'], null, $after,
            time_off_summary((string) $employee['name'], $after, time_off_days($group, $after), 'added'));
        return $id;
    });
}

/** Update one time_off row with the full before-image in the audit row. */
function time_off_update(array $group, array $employee, array $before, array $data, int $userId): void
{
    tx(static function () use ($group, $employee, $before, $data, $userId): void {
        $data += ['updated_at' => now_str(), 'updated_by' => $userId];
        update_row('time_off', $data, 'id = ?', [(int) $before['id']]);
        $after = row('SELECT * FROM time_off WHERE id = ?', [(int) $before['id']]) ?? $before;
        $summary = time_off_summary((string) $employee['name'], $after, time_off_days($group, $after), 'changed')
            . sprintf(' (was %s %s..%s)', $before['kind'], $before['start_date'], $before['end_date']);
        audit('update', 'time_off', (int) $before['id'], (int) $employee['id'], (int) $group['id'], $before, $after, $summary);
    });
}

/** Delete one time_off row; the full before-image goes to audit_log so History can restore it (SPEC 7.4 / 7.8). */
function time_off_delete(array $group, array $employee, array $before): void
{
    tx(static function () use ($group, $employee, $before): void {
        $id = (int) $before['id'];
        audit('delete', 'time_off', $id, (int) $employee['id'], (int) $group['id'], $before, null,
            time_off_summary((string) $employee['name'], $before, time_off_days($group, $before), 'deleted'));
        q('DELETE FROM time_off WHERE id = ?', [$id]);
    });
}

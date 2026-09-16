<?php
declare(strict_types=1);

/**
 * Append-only audit log (SPEC section 11). Call inside the same transaction as the change.
 * Summaries read like "Walker, Rebecca: Vacation 2026-10-11..2026-10-13 (3 days) added".
 */
function audit(string $action, ?string $table, ?int $rowId, ?int $employeeId, ?int $groupId, ?array $before, ?array $after, string $summary): void
{
    $userId = null;
    if (PHP_SAPI !== 'cli' && isset($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }
    insert('audit_log', [
        'at'          => now_str(),
        'user_id'     => $userId,
        'ip'          => PHP_SAPI === 'cli' ? null : client_ip(),
        'action'      => $action,
        'table_name'  => $table,
        'row_id'      => $rowId,
        'employee_id' => $employeeId,
        'group_id'    => $groupId,
        'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'after_json'  => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'summary'     => mb_substr($summary, 0, 255),
    ]);
}

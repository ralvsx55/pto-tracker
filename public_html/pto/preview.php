<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/pto_app/lib/bootstrap.php';

/**
 * Live preview for the time-off form (SPEC section 7.3): session-checked JSON over request_preview().
 * GET preview.php?employee_id=1&kind=PTO&start=2026-10-05&end=2026-10-07[&exclude=<request id>]
 */
$user = current_user();
if ($user === null) {
    json_out(['error' => 'Not logged in.'], 401);
}
$employeeId = (int) get('employee_id', 0);
$eg = $employeeId > 0 ? employee_with_group($employeeId) : null;
if ($eg === null) {
    json_out(['error' => 'Unknown employee.'], 404);
}
if (!user_can_group($user, (int) $eg['group']['id'])) {
    json_out(['error' => 'Not allowed.'], 403);
}
$exclude = get('exclude');
$exclude = is_string($exclude) && ctype_digit($exclude) ? (int) $exclude : null;

$result = request_preview($employeeId, (string) get('kind', ''), (string) get('start', ''), (string) get('end', ''), $exclude);
json_out($result, isset($result['error']) ? 422 : 200);

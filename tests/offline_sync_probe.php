<?php
/**
 * The P5-M06b test's eyes and hands, on the test database only.
 *   read                                   grants, the sync log, hours, gate marks, placements, activity
 *   review <placement> <date> <status> <at> a supervisor's decision on a day, at a given time
 *   checkin <placement> <date> <marked_at>  a gate mark made on the screens at a given time
 *   bump <user>                             the account's sign-in version moves (password change)
 *   expire <grant>                          a grant past its expiry
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/offline_sync.json'), true);
$cmd = $argv[1] ?? 'read';
if ($cmd === 'review') {
    q('UPDATE attendance_records SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE placement_id = ? AND work_date = ?',
      [(string) $argv[4], (int) $fx['ids']['sup'], (string) $argv[5], (int) $argv[2], (string) $argv[3]]);
    exit(0);
}
if ($cmd === 'checkin') {
    q('INSERT INTO assignment_checkins (placement_id, work_date, present, marked_by, marked_at) VALUES (?,?,1,?,?)
       ON DUPLICATE KEY UPDATE present = 1, marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)',
      [(int) $argv[2], (string) $argv[3], (int) $fx['ids']['admin'], (string) $argv[4]]);
    exit(0);
}
if ($cmd === 'bump') {
    q('INSERT INTO account_security(user_id,session_version) VALUES (?,1) ON DUPLICATE KEY UPDATE session_version=session_version+1', [(int) $argv[2]]);
    exit(0);
}
if ($cmd === 'expire') {
    q('UPDATE device_grants SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [(int) $argv[2]]);
    exit(0);
}

$users = implode(',', array_map('intval', $fx['ids']));
$places = implode(',', array_map('intval', $fx['places']));
echo json_encode([
    'grants' => rows("SELECT id, user_id, token_hash, label, session_version, created_at, expires_at, last_used_at, revoked_at, revoked_by, revoke_reason,
                             TIMESTAMPDIFF(MINUTE, created_at, expires_at) AS minutes FROM device_grants WHERE user_id IN ($users) ORDER BY id"),
    'log' => rows("SELECT user_id, device_id, entry_id, kind, placement_id, work_date, hours, present, captured_at, received_at, outcome, reason, attendance_id FROM attendance_sync_log WHERE user_id IN ($users) ORDER BY id"),
    'attendance' => rows("SELECT id, placement_id, work_date, hours, status, source, submitted_by FROM attendance_records WHERE placement_id IN ($places) ORDER BY id"),
    'checkins' => rows("SELECT placement_id, work_date, present, marked_by, marked_at FROM assignment_checkins WHERE placement_id IN ($places) ORDER BY placement_id, work_date"),
    'placements' => rows("SELECT id, status FROM placements WHERE id IN ($places) ORDER BY id"),
    'activity' => rows("SELECT user_id, action, entity_id FROM activity WHERE user_id IN ($users) AND action LIKE '%trusted phone%' ORDER BY id"),
]);

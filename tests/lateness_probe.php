<?php
/**
 * The P5-M14 test's eyes, on the test database only: the late arrivals,
 * the weekly sheets with what week_money() pays and bills, and the
 * notifications sent.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/lateness.json'), true);
$places = implode(',', array_map('intval', $fx['places']));
$job = row('SELECT * FROM jobs WHERE id = ?', [(int) $fx['job']]);
$sheets = [];
foreach (rows("SELECT ts.*, p.pay_rate, p.bill_rate, p.per_diem_rate FROM timesheets ts JOIN placements p ON p.id = ts.placement_id WHERE ts.placement_id IN ($places) ORDER BY ts.id") as $s) {
    $money = week_money($s, $s, $job);
    $sheets[] = ['placement_id' => (int) $s['placement_id'], 'week_ending' => $s['week_ending'], 'status' => $s['status'], 'hours_worked' => (float) $s['hours_worked'],
                 'late_docked_hours' => (float) $s['late_docked_hours'], 'late_billed_hours' => (float) $s['late_billed_hours'],
                 'pay' => round((float) $money['labour_cost'], 2), 'bill' => round((float) $money['bill_total'], 2)];
}
$users = implode(',', array_map('intval', $fx['ids']));
echo json_encode([
    'lateness' => rows("SELECT id, placement_id, work_date, shift_start, arrived_at, minutes_late, docked_minutes, reason, status, dispute_note, decision_note FROM attendance_lateness WHERE placement_id IN ($places) ORDER BY work_date, id"),
    'policy' => row('SELECT * FROM lateness_policies WHERE job_id = ?', [(int) $fx['job']]),
    'sheets' => $sheets,
    'notifications' => rows("SELECT user_id, message, target FROM notifications WHERE target LIKE '/lateness%' AND user_id IN ($users) ORDER BY id"),
]);

<?php
/**
 * Read-only: leave types, the M04 people's requests, and the leave
 * project's sheets with their frozen figures, as JSON, for leave_http.py.
 *
 *   php leave_probe.php <job_id>
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$sheets = [];
foreach (rows('SELECT t.placement_id, t.hours_worked, t.paid_leave_hours, t.status, s.result_json FROM timesheets t
               JOIN placements p ON p.id = t.placement_id LEFT JOIN pay_snapshots s ON s.timesheet_id = t.id
               WHERE p.job_id = ? ORDER BY t.placement_id', [$job]) as $r) {
    $r['snapshot'] = $r['result_json'] !== null ? json_decode((string) $r['result_json'], true) : null;
    unset($r['result_json']);
    $sheets[] = $r;
}

echo json_encode([
    'types'    => rows('SELECT slug, label, accrual_method, accrual_hours_per_day, accrual_cap_days, carryover_max_days,
                               eligible_after_days, eligible_employment_types, is_paid, hours_per_day, is_active FROM leave_types ORDER BY slug'),
    'requests' => rows("SELECT r.id, c.email, r.leave_type, r.starts_on, r.ends_on, r.status FROM time_off_requests r
                        JOIN placements p ON p.id = r.placement_id JOIN candidates c ON c.id = p.candidate_id
                        WHERE c.email LIKE 'm04-%' ORDER BY r.id"),
    'sheets'   => $sheets,
]);

<?php
/** Read-only: the P2-M01 project's people, their pay changes and frozen weeks, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$sheets = [];
foreach (rows('SELECT t.placement_id, t.week_ending, t.status, s.result_json FROM timesheets t JOIN placements p ON p.id = t.placement_id
               LEFT JOIN pay_snapshots s ON s.timesheet_id = t.id WHERE p.job_id = ? ORDER BY t.week_ending, t.placement_id', [$job]) as $r) {
    $snap = $r['result_json'] ? json_decode((string) $r['result_json'], true) : null;
    $sheets[] = ['placement_id' => (int) $r['placement_id'], 'week' => $r['week_ending'], 'status' => $r['status'],
                 'pay_rate' => $snap['pay_rate'] ?? null, 'labour_cost' => $snap['labour_cost'] ?? null, 'salary' => $snap['salary_basis'] ?? null];
}

echo json_encode([
    'grades'   => rows('SELECT id, code, label, rate_min, rate_max, salary_min, salary_max, is_active FROM pay_grades ORDER BY id'),
    'changes'  => rows('SELECT c.id, c.candidate_id, c.placement_id, c.kind, c.old_amount, c.new_amount, c.grade_id, c.effective_from, c.outside_band, c.applied_at IS NOT NULL AS applied, c.cancelled_at IS NOT NULL AS cancelled
                        FROM compensation_changes c JOIN placements p ON p.candidate_id = c.candidate_id WHERE p.job_id = ? ORDER BY c.id', [$job]),
    'profiles' => rows('SELECT e.candidate_id, e.salary_per_period, e.grade_id FROM employee_profiles e JOIN placements p ON p.candidate_id = e.candidate_id WHERE p.job_id = ?', [$job]),
    'rates'    => rows('SELECT id, pay_rate FROM placements WHERE job_id = ?', [$job]),
    'sheets'   => $sheets,
]);

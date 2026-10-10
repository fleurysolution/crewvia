<?php
/**
 * The P2-M04 test's eyes, on the test database only: plans, their pay
 * items, enrollments, the pay items on people, and - with "g2n <candidate>
 * <placement> <week ending>" - what gross to net takes from a week of 1,000
 * gross for that person, exactly as payroll would.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/gross-to-net.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'g2n') {
    $r = gross_to_net_for_sheet(['candidate_id' => (int) $argv[2], 'placement_id' => (int) $argv[3], 'week_ending' => (string) $argv[4]],
                                ['labour_cost' => 1000, 'leave_pay' => 0, 'per_diem' => 0, 'expenses' => 0]);
    echo json_encode(['deductions' => array_map(fn($l) => [$l['code'], (float) $l['amount'], (bool) $l['pre_tax']], $r['deductions']),
                      'employer' => array_map(fn($l) => [$l['code'], (float) $l['amount']], $r['employer'])]);
    exit(0);
}

$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'plans'       => $num(rows('SELECT p.id, p.code, p.method, p.is_active, d.id AS deduction_id, d.code AS deduction_code, d.method AS deduction_method, d.pre_tax, r.code AS employer_code, r.side AS employer_side FROM benefit_plans p
                                JOIN pay_items d ON d.id = p.deduction_item_id JOIN pay_items r ON r.id = p.employer_item_id ORDER BY p.id'), ['id', 'is_active', 'pre_tax', 'deduction_id']),
    'enrollments' => $num(rows('SELECT id, candidate_id, plan_id, status, tier, employee_amount, employer_amount, starts_on, ends_on FROM benefit_enrollments ORDER BY id'), ['id', 'candidate_id', 'plan_id', 'employee_amount', 'employer_amount']),
    'pay_items'   => $num(rows('SELECT e.id, e.candidate_id, i.code, e.amount, e.starts_on, e.ends_on, e.benefit_enrollment_id FROM employee_pay_items e JOIN pay_items i ON i.id = e.pay_item_id WHERE e.benefit_enrollment_id IS NOT NULL ORDER BY e.id'), ['id', 'candidate_id', 'amount', 'benefit_enrollment_id']),
]);

<?php
/**
 * Read-only: prints what the database holds for one candidate, as JSON,
 * so hr_relationships_http.py can check what a screen actually wrote.
 *
 *   php hr_relationships_probe.php <candidate_id>
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') {
    fwrite(STDERR, "Refusing: test database only.\n");
    exit(2);
}

$cid = (int) ($argv[1] ?? 0);
$placements = rows('SELECT id, job_id, vacancy_id, order_line_id, pay_rate, bill_rate, per_diem_rate, guarantee_hours
                    FROM placements WHERE candidate_id = ? ORDER BY id', [$cid]);
$changes = [];

foreach ($placements as $p) {
    $changes[$p['id']] = rows('SELECT old_pay_rate, new_pay_rate, old_bill_rate, new_bill_rate, changed_by
                               FROM placement_rate_changes WHERE placement_id = ? ORDER BY id', [$p['id']]);
}

echo json_encode([
    'profile'         => row('SELECT employment_type, flsa_status FROM employee_profiles WHERE candidate_id = ?', [$cid]),
    'classifications' => rows('SELECT employment_type, flsa_status, effective_from, reason, recorded_by
                               FROM employee_classifications WHERE candidate_id = ? ORDER BY id', [$cid]),
    'placements'      => $placements,
    'rate_changes'    => $changes,
]);

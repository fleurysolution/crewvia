<?php
/** Read-only: the P2-M06 project's recognitions, cases, notes, separations, register entries and the access log, as JSON. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$job = (int) ($argv[1] ?? 0);
$people = 'SELECT candidate_id FROM placements WHERE job_id = ' . $job;
$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'recognitions' => $num(rows("SELECT id, candidate_id, kind, title FROM recognitions WHERE candidate_id IN ($people) ORDER BY id"), ['id', 'candidate_id']),
    'cases'        => $num(rows("SELECT id, reference, candidate_id, status, outcome, facts, response, acknowledged_at IS NOT NULL AS acknowledged, opened_by, safety_incident_id FROM disciplinary_cases WHERE candidate_id IN ($people) ORDER BY id"), ['id', 'candidate_id', 'acknowledged', 'opened_by', 'safety_incident_id']),
    'notes'        => $num(rows("SELECT n.case_id, n.kind FROM disciplinary_notes n JOIN disciplinary_cases d ON d.id = n.case_id WHERE d.candidate_id IN ($people) ORDER BY n.id"), ['case_id']),
    'separations'  => $num(rows("SELECT placement_id, reason, voluntary, rehire, case_id FROM separations WHERE candidate_id IN ($people) ORDER BY id"), ['placement_id', 'voluntary', 'case_id']),
    'register'     => rows("SELECT candidate_id, rehire_status, exclusion_reason FROM employee_profiles WHERE candidate_id IN ($people) ORDER BY candidate_id"),
    'log'          => $num(rows('SELECT user_id, action, candidate_id, subject_user_id FROM hr_access_log ORDER BY id'), ['user_id', 'candidate_id', 'subject_user_id']),
    'grants'       => $num(rows('SELECT user_id FROM hr_access_grants ORDER BY user_id'), ['user_id']),
    'notified'     => $num(rows("SELECT user_id, message FROM notifications WHERE target = '/hr-records' ORDER BY id"), ['user_id']),
]);

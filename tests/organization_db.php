<?php
/**
 * P5-M17 fixture for organization_http.py, on the isolated test database
 * only. No table changes, so no rollback here. A recruiter and a Logistics
 * user; a project with 30 people, 20 of them on two supervisors' crews and
 * 10 with no supervisor.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'hotels' => 'hotels', 'sup1' => 'supervisor', 'sup2' => 'supervisor'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Org ' . $k, 'org-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Org client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M17 crew chart','active')", [$client]);
$job = (int) db()->lastInsertId();
$places = [];
for ($i = 1; $i <= 30; $i++) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", [sprintf('Org person %02d', $i), sprintf('org-p%02d@test.invalid', $i)]);
    $cand = (int) db()->lastInsertId();
    q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'on_site',CURDATE())", [$cand, $job]);
    $places[] = $pid = (int) db()->lastInsertId();
    if ($i <= 20) {
        q("INSERT INTO assignment_details(placement_id,trade,supervisor_id,shift_label) VALUES (?,'Fitter',?,'Days')", [$pid, $i <= 10 ? $ids['sup1'] : $ids['sup2']]);
    }
}
file_put_contents(__DIR__ . '/organization.json', json_encode(['ids' => $ids, 'job' => $job, 'places' => $places]));
file_put_contents(__DIR__ . '/org-db-results.json', json_encode(['Fixture for the organization chart'], JSON_PRETTY_PRINT));
echo 'PASS Fixture for the organization chart' . PHP_EOL;

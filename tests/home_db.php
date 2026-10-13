<?php
/**
 * P5-M21 (layout wave 2) fixture for home_http.py, on the isolated test
 * database only. No table changes, so no rollback here. One user per desk,
 * a supervisor with two of their crew on site, a worker with an onboarding
 * task waiting, a running project with an open requisition and a hotel.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'hotels' => 'hotels', 'pay' => 'payroll', 'sup' => 'supervisor', 'w' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Home ' . $k, 'hm-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Home client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,headcount_target) VALUES (?,'P5-M21 home project','active',6)", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings) VALUES (?,'P5-M21 fitter','Synthetic.',1,5)", [$job]);
$vacancy = (int) db()->lastInsertId();
q("INSERT INTO onboarding_requirements(job_id,title,instructions) VALUES (?,'P5-M21 orientation','Watch the site video.')", [$job]);
$crew = [];
foreach (['w' => 'w', 'c2' => null] as $k => $account) {
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'placed')", ['Home crew ' . $k, 'hm-cand-' . $k . '@test.invalid']);
    $cand = (int) db()->lastInsertId();
    if ($account) { q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$account], $cand]); }
    q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate,bill_rate,guarantee_hours) VALUES (?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL 20 DAY),30,50,40)", [$cand, $job]);
    $crew[$k] = (int) db()->lastInsertId();
    q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Fitter',?)", [$crew[$k], $ids['sup']]);
}
q("INSERT INTO hotels(name,nightly_rate) VALUES ('P5-M21 Inn',90)");
$hotel = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/home.json', json_encode(['ids' => $ids, 'job' => $job, 'vacancy' => $vacancy, 'crew' => $crew, 'hotel' => $hotel]));
file_put_contents(__DIR__ . '/hm-db-results.json', json_encode(['Fixture for the role homes'], JSON_PRETTY_PRINT));
echo 'PASS Fixture for the role homes' . PHP_EOL;

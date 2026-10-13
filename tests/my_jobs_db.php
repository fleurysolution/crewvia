<?php
/**
 * P5-M13 (T10) fixture for my_jobs_http.py, on the isolated test database
 * only. No table changes in this module, so no rollback here.
 *
 * A project in Katy, TX with six requisitions (a chemical one with a
 * required question, a mechanical one, one asking ten years, one closed,
 * one whose posting expired, one open to any trade) and one on a closed
 * project; workers: a chemical worker with five years in Houston, a worker
 * whose file says neither trade nor years, one on the do-not-rehire
 * register, and an account not linked to a crew record; a recruiter.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['w' => 'worker', 'blank' => 'worker', 'banned' => 'worker', 'loose' => 'worker', 'rec' => 'recruiter'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Jobs ' . $k, 'mj-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$cands = [];
foreach (['w' => ['chemical', 5, 'Houston', 'TX'], 'blank' => ['other', null, null, null], 'banned' => ['chemical', 9, 'Katy', 'TX']] as $k => [$trade, $years, $city, $state]) {
    q("INSERT INTO candidates(full_name,email,stage,discipline,years_exp,city,state) VALUES (?,?,'placed',?,?,?,?)", ['Jobs worker ' . $k, 'mj-' . $k . '@test.invalid', $trade, $years, $city, $state]);
    $cands[$k] = (int) db()->lastInsertId();
    q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$k], $cands[$k]]);
}
q("INSERT INTO employee_profiles(candidate_id,rehire_status) VALUES (?,'ineligible')", [$cands['banned']]);

q("INSERT INTO clients(name) VALUES ('Jobs client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,site_city,site_state,lodging_provided) VALUES (?,'P5-M13 Katy turnaround','active','Katy','TX',1)", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M13 finished job','closed')", [$client]);
$closedJob = (int) db()->lastInsertId();
$v = [];
foreach (['chem' => [$job, 'P5-M13 chemical operator', 'chemical', 3, 1, 42.5, 75], 'mech' => [$job, 'P5-M13 millwright', 'mechanical', null, 1, 38, null],
          'senior' => [$job, 'P5-M13 senior operator', 'chemical', 10, 1, 55, null], 'shut' => [$job, 'P5-M13 filled role', 'chemical', null, 0, 40, null],
          'expired' => [$job, 'P5-M13 expired posting', 'chemical', null, 1, 40, null], 'any' => [$job, 'P5-M13 fire watch', 'other', null, 1, 22, null],
          'gone' => [$closedJob, 'P5-M13 role on a closed project', 'chemical', null, 1, 40, null]] as $k => [$j, $title, $trade, $years, $open, $pay, $diem]) {
    q('INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline,years_experience,pay_rate,per_diem_rate) VALUES (?,?,?,?,2,?,?,?,?)', [$j, $title, 'Synthetic requisition.', $open, $trade, $years, $pay, $diem]);
    $v[$k] = (int) db()->lastInsertId();
}
q('INSERT INTO requisition_publication(vacancy_id,published_on,expires_on) VALUES (?, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY))', [$v['expired']]);
q("INSERT INTO screening_questions(job_id,vacancy_id,question,required,answer_type) VALUES (?,?,'Can you work nights?',1,'yes_no')", [$job, $v['chem']]);
$question = (int) db()->lastInsertId();
q("INSERT INTO screening_questions(job_id,vacancy_id,question,required,answer_type) VALUES (?,?,'Anything else we should know?',0,'text')", [$job, $v['chem']]);
$optional = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/my_jobs.json', json_encode(['ids' => $ids, 'cands' => $cands, 'v' => $v, 'question' => $question, 'optional' => $optional]));
file_put_contents(__DIR__ . '/mj-db-results.json', json_encode(['Fixture for Jobs for you'], JSON_PRETTY_PRINT));
echo 'PASS Fixture for Jobs for you' . PHP_EOL;

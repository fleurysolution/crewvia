<?php
/**
 * P5-M26 fixture for walkthrough_http.py (the flow-by-flow walkthrough fixes), on
 * the isolated test database only.
 *   - one user per desk, a recruiter still on a temporary password, a supervisor;
 *   - an agreement with a 50-hour guarantee and two lines, one of them with no
 *     guarantee of its own (the full week must use the agreement's);
 *   - two requisitions, a project question, a question for the second role only
 *     (required), and a number question with a minimum on the first;
 *   - applicants: one answered and offerable, one not yet phone-screened, one
 *     offered with a note and a portal account (to accept);
 *   - a worker on site under the supervisor, with one onboarding task never
 *     submitted and one submitted.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'rec' => 'recruiter', 'temp' => 'recruiter', 'sup' => 'supervisor', 'w' => 'worker', 'w2' => 'worker'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,?)', ['Walk ' . $k, 'wk-' . $k . '@test.invalid', $password, $role, $k === 'temp' ? 1 : 0]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Walkthrough client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,guarantee_hours,strike_hours,site_city,site_state,lodging_provided,travel_provided,transport_provided)
   VALUES (?,'P5-M26 plant','active',50,60,'Houston','TX',1,1,0)", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,per_diem_rate) VALUES (?,'Walk machinist','mechanical',4,45,68,75)", [$job]);
$lineA = (int) db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,per_diem_rate) VALUES (?,'Walk engineer','chemical',2,50,78,NULL)", [$job]);
$lineB = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,order_line_id,title,description,is_open,openings,starts_on,shift) VALUES (?,?,'Walk machinist req','Synthetic.',1,2,'2026-11-02','Day')", [$job, $lineA]);
$vA = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,order_line_id,title,description,is_open,openings) VALUES (?,?,'Walk engineer req','Synthetic.',1,1)", [$job, $lineB]);
$vB = (int) db()->lastInsertId();
q("INSERT INTO screening_questions(job_id,vacancy_id,question,required,answer_type,knockout_answer,weight) VALUES (?,NULL,'Do you have a full-time job now?',1,'yes_no','Yes',2)", [$job]);
$qAll = (int) db()->lastInsertId();
q("INSERT INTO screening_questions(job_id,vacancy_id,question,required,answer_type,knockout_answer,weight) VALUES (?,?,'Do you need sponsorship?',1,'yes_no','Yes',3)", [$job, $vB]);
$qB = (int) db()->lastInsertId();
q("INSERT INTO screening_questions(job_id,vacancy_id,question,required,answer_type,knockout_answer,weight) VALUES (?,?,'Years of setup?',1,'number','<2',3)", [$job, $vA]);
$qNum = (int) db()->lastInsertId();

$cand = static function (string $k, ?string $account = null): int {
    global $ids;
    q("INSERT INTO candidates(full_name,email,stage) VALUES (?,?,'new')", ['Walk ' . $k, 'wk-cand-' . $k . '@test.invalid']);
    $id = (int) db()->lastInsertId();
    if ($account) { q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$ids[$account], $id]); }
    return $id;
};
$c1 = $cand('ready');
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'interview')", [$c1, $vA]);
$app1 = (int) db()->lastInsertId();
q('INSERT INTO screening_answers(application_id,question_id,answer) VALUES (?,?,?),(?,?,?)', [$app1, $qAll, 'No', $app1, $qNum, '5']);
$c2 = $cand('phone');
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'new')", [$c2, $vA]);
$app2 = (int) db()->lastInsertId();
$c3 = $cand('offered', 'w2');
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'offered')", [$c3, $vA]);
$app3 = (int) db()->lastInsertId();
q("INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,'offered','Bring your steel-toe boots.')", [$app3, $ids['rec']]);

$cw = $cand('onsite', 'w');
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate) VALUES (?,?,'on_site',DATE_SUB(CURDATE(), INTERVAL 10 DAY),45)", [$cw, $job]);
$pw = (int) db()->lastInsertId();
q("INSERT INTO assignment_details(placement_id,trade,supervisor_id) VALUES (?,'Walk machinist',?)", [$pw, $ids['sup']]);
q("INSERT INTO onboarding_requirements(job_id,title,instructions,required) VALUES (?,'Walk orientation','Watch the plant video and type the code.',1)", [$job]);
$req = (int) db()->lastInsertId();
q("INSERT INTO onboarding_requirements(job_id,title,instructions,required) VALUES (?,'Walk badge','Bring two forms of ID to the gate.',1)", [$job]);
$req2 = (int) db()->lastInsertId();
q("INSERT INTO onboarding_tasks(placement_id,requirement_id,status) VALUES (?,?,'pending')", [$pw, $req]);
$taskPending = (int) db()->lastInsertId();
q("INSERT INTO onboarding_tasks(placement_id,requirement_id,status,response) VALUES (?,?,'submitted','Done, code 4417')", [$pw, $req2]);
$taskSubmitted = (int) db()->lastInsertId();

file_put_contents(__DIR__ . '/walkthrough.json', json_encode(compact('ids', 'job', 'lineA', 'lineB', 'vA', 'vB', 'qAll', 'qB', 'qNum', 'app1', 'app2', 'app3', 'c1', 'c2', 'c3', 'pw', 'taskPending', 'taskSubmitted')));
echo "PASS Walkthrough fixture written\n";

<?php
/**
 * P4-M01 fixture, on the isolated test database only. Records dated in
 * 2017, a year no other test uses, on two projects of their own, so
 * reports_http.py can expect exact figures. February 2017:
 *
 *   Ann   P4-M01 project, hourly, 30 then 32 from 13 Feb, bill 50, since 2 Jan
 *   Ben   P4-M01 project, salaried 2,000, 2 Jan to 15 Feb (completed)
 *   Cal   P4-M01 project, hourly 25, bill 40, since 1 Feb, grade 26-35 (outside)
 *   Dee   P4-M01 second project, since 10 Jan
 *
 *   roll call    Ann present 6, 7, 8 Feb, absent 9 Feb. Cal present 6 Feb
 *   leave        Ann 20-22 Feb approved (3 days of 10); Cal 1 request waiting
 *   pay          weeks ending 11 and 18 Feb: Ann both weeks, Cal the first
 *   advances     Ann 600 paid out, 200 repaid; Cal loan 300 written off;
 *                Ben 150 only requested
 *   benefits     dental: Ann covered (12 / 30), Cal waived, Ben ended 10 Feb
 *   performance  Ann approved review 85 % B, a goal open, one achieved, an
 *                overdue action; Cal a goal missed
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

check('Nothing is dated 2017 yet', ! val("SELECT COUNT(*) FROM placements WHERE start_date BETWEEN '2017-01-01' AND '2017-12-31'")
      && ! val("SELECT COUNT(*) FROM payroll_runs WHERE week_ending BETWEEN '2017-01-01' AND '2017-12-31'"));

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['admin' => 'admin', 'payroll' => 'payroll', 'recruiter' => 'recruiter', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Reports ' . $k, 'rp-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
$admin = $ids['admin'];

q("INSERT INTO clients(name) VALUES ('Reports client')");
$client = (int) db()->lastInsertId();
$job = static function (string $title) use ($client): int {
    q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status) VALUES (?,?,'Synthetic project for the reports.',30,50,0,0,30,'active')", [$client, $title]);
    return (int) db()->lastInsertId();
};
$jobA = $job('P4-M01 project');
$jobB = $job('P4-M01 second project');

q("INSERT INTO pay_grades(code,label,rate_min,rate_max,sort_order,is_active) VALUES ('P4G','P4 grade',26,35,99,1)");
$grade = (int) db()->lastInsertId();

$person = static function (string $name, string $trade, array $profile): int {
    q('INSERT INTO candidates(full_name,email,discipline,stage) VALUES (?,?,?,?)', [$name, strtolower($name) . '-p4@test.invalid', $trade, 'placed']);
    $id = (int) db()->lastInsertId();
    $cols = array_keys($profile);
    q('INSERT INTO employee_profiles(candidate_id,' . implode(',', $cols) . ') VALUES (?' . str_repeat(',?', count($cols)) . ')', [$id, ...array_values($profile)]);
    return $id;
};
$ann = $person('Ann Reportable', 'electrical', ['employee_number' => 'E-101', 'employment_type' => 'hourly', 'availability' => 'on_assignment', 'rehire_status' => 'eligible']);
$ben = $person('Ben Reportable', 'mechanical', ['employee_number' => 'E-102', 'employment_type' => 'salaried', 'salary_per_period' => 2000, 'rehire_status' => 'review']);
$cal = $person('Cal Reportable', 'labour', ['employee_number' => 'E-103', 'employment_type' => 'hourly', 'grade_id' => $grade]);
$dee = $person('Dee Reportable', 'labour', ['employee_number' => 'E-104', 'employment_type' => 'hourly']);

$place = static function (int $cand, int $jobId, string $status, string $start, ?string $end, float $pay, float $bill) use ($admin): int {
    q('INSERT INTO placements(candidate_id,job_id,status,start_date,end_date,pay_rate,bill_rate,created_by) VALUES (?,?,?,?,?,?,?,?)', [$cand, $jobId, $status, $start, $end, $pay, $bill, $admin]);
    return (int) db()->lastInsertId();
};
$pAnn = $place($ann, $jobA, 'on_site', '2017-01-02', null, 32, 50);
$pBen = $place($ben, $jobA, 'completed', '2017-01-02', '2017-02-15', 0, 60);
$pCal = $place($cal, $jobA, 'on_site', '2017-02-01', null, 25, 40);
$pDee = $place($dee, $jobB, 'on_site', '2017-01-10', null, 28, 45);
q("INSERT INTO compensation_changes(candidate_id,placement_id,kind,old_amount,new_amount,effective_from,reason,recorded_by,recorded_at,applied_at)
   VALUES (?,?,'hourly_rate',30,32,'2017-02-13','Annual review',?,NOW(),NOW())", [$ann, $pAnn, $admin]);

foreach ([['2017-02-06', 1], ['2017-02-07', 1], ['2017-02-08', 1], ['2017-02-09', 0]] as [$day, $present]) {
    q('INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by,marked_at) VALUES (?,?,?,?,NOW())', [$pAnn, $day, $present, $admin]);
}
q("INSERT INTO assignment_checkins(placement_id,work_date,present,marked_by,marked_at) VALUES (?,'2017-02-06',1,?,NOW())", [$pCal, $admin]);

q("INSERT INTO leave_types(slug,label,days_allowed,accrual_method,is_paid,hours_per_day,sort_order,is_active) VALUES ('p4pto','P4 paid leave',10,'annual',1,8,99,1)");
q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,leave_type,reason,status,reviewed_by,reviewed_at) VALUES (?,?,'2017-02-20','2017-02-22','P4 paid leave','p4pto','Family','approved',?,NOW())", [$pAnn, $admin, $admin]);
q("INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,leave_type,reason,status) VALUES (?,?,'2017-03-06','2017-03-07','P4 paid leave','p4pto','Appointment','pending')", [$pCal, $admin]);

// Pay: the frozen figures of three approved sheets in two weeks.
$sheet = static function (int $placement, string $week, array $g) use ($admin): int {
    q("INSERT INTO timesheets(placement_id,week_ending,hours_worked,status,approved_by,approved_at) VALUES (?,?,40,'approved',?,NOW())", [$placement, $week, $admin]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO pay_snapshots(timesheet_id,result_json) VALUES (?,?)', [$id, json_encode(['labour_cost' => $g['gross_wages'], 'gross_to_net' => $g])]);
    return $id;
};
$union = ['code' => 'P4UNION', 'label' => 'P4 union dues', 'pre_tax' => false];
$w1 = '2017-02-11';
$w2 = '2017-02-18';
$s1 = $sheet($pAnn, $w1, ['gross_wages' => 1200, 'deductions' => [$union + ['amount' => 20]], 'total_deductions' => 20,
                          'employer' => [['code' => 'P4MATCH', 'label' => 'P4 match', 'pre_tax' => false, 'amount' => 15]], 'total_employer' => 15,
                          'shortfalls' => [$union + ['amount' => 5]], 'reimbursements' => 100, 'net_before_tax' => 1280]);
$sheet($pAnn, $w2, ['gross_wages' => 1200, 'deductions' => [$union + ['amount' => 20]], 'total_deductions' => 20, 'employer' => [], 'total_employer' => 0,
                    'shortfalls' => [], 'reimbursements' => 0, 'net_before_tax' => 1180]);
$sheet($pCal, $w1, ['gross_wages' => 1000, 'deductions' => [$union + ['amount' => 20]], 'total_deductions' => 20, 'employer' => [], 'total_employer' => 0,
                    'shortfalls' => [], 'reimbursements' => 0, 'net_before_tax' => 980]);
q("INSERT INTO payroll_runs(week_ending,status,opened_by,approved_by,approved_at) VALUES (?,'approved',?,?,NOW())", [$w1, $admin, $admin]);
$run1 = (int) db()->lastInsertId();
q("INSERT INTO payroll_runs(week_ending,status,opened_by,approved_by,approved_at,locked_by,locked_at) VALUES (?,'locked',?,?,NOW(),?,NOW())", [$w2, $admin, $admin, $admin]);
q("INSERT INTO payroll_adjustments(run_id,candidate_id,timesheet_id,kind,amount,reason,created_by) VALUES (?,?,?,'back_pay',50,'Missed hours',?)", [$run1, $ann, $s1, $admin]);
q("INSERT INTO payroll_payments(timesheet_id,method,reference,paid_by) VALUES (?,'direct_deposit','P4-DD-1',?)", [$s1, $admin]);

q("INSERT INTO wage_advances(candidate_id,job_id,amount,weekly_repayment,reason,status,requested_by,approved_by,approved_at,paid_out_on,kind)
   VALUES (?,?,600,100,'Travel deposit','paid_out',?,?,NOW(),'2017-02-01','advance')", [$ann, $jobA, $admin, $admin]);
$adv = (int) db()->lastInsertId();
foreach (['2017-02-11', '2017-02-18'] as $on) {
    q('INSERT INTO wage_advance_payments(advance_id,amount,paid_on,recorded_by) VALUES (?,100,?,?)', [$adv, $on, $admin]);
}
q("INSERT INTO wage_advances(candidate_id,job_id,amount,weekly_repayment,reason,status,requested_by,approved_by,approved_at,paid_out_on,kind,written_off_amount,written_off_reason,written_off_by,written_off_at)
   VALUES (?,?,300,50,'Tools','written_off',?,?,NOW(),'2017-02-02','loan',300,'Left without notice',?,NOW())", [$cal, $jobA, $admin, $admin, $admin]);
$loan = (int) db()->lastInsertId();
q("INSERT INTO wage_advances(candidate_id,job_id,amount,weekly_repayment,reason,status,requested_by,kind) VALUES (?,?,150,50,'Boots','requested',?,'advance')", [$ben, $jobA, $admin]);

q("INSERT INTO pay_items(code,label,side,method,pre_tax,sort_order,is_active) VALUES ('P4DENT_E','P4 dental','deduction','fixed',1,99,1)");
$dItem = (int) db()->lastInsertId();
q("INSERT INTO pay_items(code,label,side,method,pre_tax,sort_order,is_active) VALUES ('P4DENT_R','P4 dental employer','employer_contribution','fixed',0,99,1)");
$rItem = (int) db()->lastInsertId();
q("INSERT INTO benefit_plans(code,name,kind,provider,method,pre_tax,eligible_types,waiting_days,deduction_item_id,employer_item_id,is_active) VALUES ('P4DENTAL','P4 dental plan','dental','Synthetic dental','fixed',1,'hourly,salaried',0,?,?,1)", [$dItem, $rItem]);
$plan = (int) db()->lastInsertId();
q("INSERT INTO benefit_enrollments(candidate_id,plan_id,status,tier,employee_amount,employer_amount,starts_on,created_by) VALUES (?,?,'enrolled','employee',12,30,'2017-02-01',?)", [$ann, $plan, $admin]);
q("INSERT INTO benefit_enrollments(candidate_id,plan_id,status,tier,starts_on,reason,created_by) VALUES (?,?,'waived','employee','2017-02-01','Covered elsewhere',?)", [$cal, $plan, $admin]);
q("INSERT INTO benefit_enrollments(candidate_id,plan_id,status,tier,employee_amount,employer_amount,starts_on,ends_on,created_by) VALUES (?,?,'ended','employee',12,30,'2017-01-15','2017-02-10',?)", [$ben, $plan, $admin]);

$template = (int) val('SELECT MIN(id) FROM appraisal_templates');
if (! $template) {
    q("INSERT INTO appraisal_templates(code,label,kind,scale_max,self_review,grade_a,grade_b,grade_c,grade_d,is_active) VALUES ('P4T','P4 template','periodic',5,0,90,75,60,40,1)");
    $template = (int) db()->lastInsertId();
}
q("INSERT INTO appraisals(template_id,placement_id,candidate_id,period_from,period_to,status,score_percent,grade,would_rehire,decided_by,decided_at,created_by)
   VALUES (?,?,?,'2017-01-02','2017-02-15','approved',85,'B',1,?,'2017-02-20 10:00:00',?)", [$template, $pAnn, $ann, $admin, $admin]);
q("INSERT INTO performance_goals(candidate_id,title,measure,target_on,progress,status,created_by) VALUES (?,'Lead a crew','A crew of four','2017-06-30',40,'open',?)", [$ann, $admin]);
q("INSERT INTO performance_goals(candidate_id,title,measure,target_on,progress,status,created_by,closed_by,closed_at) VALUES (?,'Safety card','Card issued','2017-02-28',100,'achieved',?,?,'2017-02-25 09:00:00')", [$ann, $admin, $admin]);
q("INSERT INTO performance_goals(candidate_id,title,measure,target_on,progress,status,created_by,closed_by,closed_at) VALUES (?,'Forklift ticket','Ticket issued','2017-02-09',10,'missed',?,?,'2017-02-10 09:00:00')", [$cal, $admin, $admin]);
q("INSERT INTO development_actions(candidate_id,kind,description,owner_id,due_on,status,created_by) VALUES (?,'training','Foreman course',?,'2017-03-01','open',?)", [$ann, $admin, $admin]);

file_put_contents(__DIR__ . '/reports.json', json_encode(['ids' => $ids, 'jobA' => $jobA, 'jobB' => $jobB, 'ann' => $ann, 'ben' => $ben, 'cal' => $cal, 'dee' => $dee,
    'pAnn' => $pAnn, 'pBen' => $pBen, 'pCal' => $pCal, 'pDee' => $pDee, 'adv' => $adv, 'loan' => $loan, 'w1' => $w1, 'w2' => $w2]));
check('The P4-M01 fixture is in place', (int) val('SELECT COUNT(*) FROM placements WHERE job_id IN (?, ?)', [$jobA, $jobB]) === 4);
file_put_contents(__DIR__ . '/rp-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

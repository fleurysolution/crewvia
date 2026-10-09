<?php
require __DIR__.'/test-app/app/bootstrap.php';
$password=password_hash('TestPassword123!',PASSWORD_DEFAULT);
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Test admin','admin@test.invalid',?,'admin',0)",[$password]);
q("INSERT INTO clients(name) VALUES ('Test client')"); $client=(int)db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status,headcount_target) VALUES (?,'Test project A','Provide labour support to the test site in accordance with the scope of work.',50,80,50,60,30,'active',3)",[$client]);$a=(int)db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,description,pay_rate,bill_rate,guarantee_hours,strike_hours,per_diem_rate,status,headcount_target) VALUES (?,'Test project B','Provide labour support to the second test site.',60,90,0,0,20,'active',1)",[$client]);$b=(int)db()->lastInsertId();

// The scope of work: two trades on one agreement at two different rates,
// which is the case a single project rate always answered wrongly.
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,per_diem_rate,guarantee_hours,strike_guarantee_hours,sort_order) VALUES (?,'Test welder','mechanical',2,50,80,30,50,60,1)",[$a]);$lineWelder=(int)db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,per_diem_rate,guarantee_hours,strike_guarantee_hours,sort_order) VALUES (?,'Test labourer','other',1,22,38,30,40,60,2)",[$a]);$lineLabour=(int)db()->lastInsertId();
q("INSERT INTO job_order_lines(job_id,role_title,discipline,quantity,pay_rate,bill_rate,per_diem_rate,guarantee_hours,sort_order) VALUES (?,'Test operator','operator',1,60,90,20,0,1)",[$b]);$lineOperator=(int)db()->lastInsertId();
$ids=[];
foreach(['worker@test.invalid','worker2@test.invalid'] as $email) {
 q('INSERT INTO candidates(full_name,email) VALUES (?,?)',[$email,$email]);$cid=(int)db()->lastInsertId();
 q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'worker',0)",[$email,$email,$password]);$uid=(int)db()->lastInsertId();
 q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)',[$uid,$cid]);
 q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'offered','2026-10-01')",[$cid,$a]);$pid=(int)db()->lastInsertId();
 $ids[]=['candidate'=>$cid,'placement'=>$pid,'user'=>$uid];
 q("INSERT INTO employment_clearance(candidate_id,i9_status,w4_status) VALUES (?,'employer_completed','reviewed')",[$cid]);
}
q("INSERT INTO placements(candidate_id,job_id,status,start_date) VALUES (?,?,'on_site','2026-10-01')",[$ids[0]['candidate'],$b]);$pb=(int)db()->lastInsertId();
q("INSERT INTO onboarding_requirements(job_id,title,instructions) VALUES (?,'Orientation','Complete orientation')",[$a]);$req=(int)db()->lastInsertId();
q("INSERT INTO onboarding_tasks(placement_id,requirement_id) VALUES (?,?)",[$ids[0]['placement'],$req]);$task=(int)db()->lastInsertId();
q("INSERT INTO vacancies(job_id,order_line_id,title,description,discipline,openings) VALUES (?,?,'Test welder','Test vacancy description','mechanical',2)",[$a,$lineWelder]);$vac=(int)db()->lastInsertId();
q("INSERT INTO hotels(name,nightly_rate) VALUES ('Test hotel',100)");$hotel=(int)db()->lastInsertId();
q("INSERT INTO shuttle_runs(job_id,runs_on,seats,driver,pickup_point) VALUES (?,'2026-10-20',1,'Test driver','Test stop')",[$a]);$run=(int)db()->lastInsertId();
q("INSERT INTO equipment(name,asset_tag) VALUES ('Test helmet',?)",['TEST-'.bin2hex(random_bytes(5))]);$asset=(int)db()->lastInsertId();
$token=bin2hex(random_bytes(32));
q("INSERT INTO candidates(full_name,email) VALUES ('Invited worker',?)",['invited-'.bin2hex(random_bytes(3)).'@test.invalid']);$inviteCandidate=(int)db()->lastInsertId();
q('INSERT INTO invitations(candidate_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))',[$inviteCandidate,hash('sha256',$token)]);
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Test supervisor','supervisor@test.invalid',?,'supervisor',0)",[$password]);$supervisor=(int)db()->lastInsertId();
q("INSERT INTO assignment_details(placement_id,trade,supervisor_id,shift_label) VALUES (?,'Welder',?,'Day shift')",[$ids[0]['placement'],$supervisor]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')",[$ids[0]['candidate'],$vac]);$historyApp=(int)db()->lastInsertId();
q("INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,'rejected','Historical rejection before reconsideration')",[$historyApp,(int)val("SELECT id FROM users WHERE email='admin@test.invalid'")]);
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Test client contact','client@test.invalid',?,'client',0)",[$password]);$clientUser=(int)db()->lastInsertId();
q("INSERT INTO attendance_records(placement_id,work_date,hours,status,submitted_by,reviewed_by,reviewed_at) VALUES (?,'2026-10-08',8,'approved',?,?,NOW())",[$ids[0]['placement'],$ids[0]['user'],(int)val("SELECT id FROM users WHERE email='admin@test.invalid'")]);
q('INSERT INTO client_access(user_id,client_id,granted_by) VALUES (?,?,?)',[$clientUser,$client,(int)val("SELECT id FROM users WHERE email='admin@test.invalid'")]);
q("INSERT INTO clients(name) VALUES ('Other private client')");$otherClient=(int)db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'Private other client project','active')",[$otherClient]);$otherJob=(int)db()->lastInsertId();
q("INSERT INTO client_invoices(job_id,reference,starts_on,ends_on,total,details_json,status,created_by) VALUES (?,'PRIVATE-OTHER-INVOICE','2026-10-01','2026-10-08',999,'[]','issued',?)",[$otherJob,(int)val("SELECT id FROM users WHERE email='admin@test.invalid'")]);$otherInvoice=(int)db()->lastInsertId();
q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES ('Recovery test','recovery@test.invalid',?,'recruiter',0)",[$password]);$recoveryUser=(int)db()->lastInsertId();
$recoveryToken=bin2hex(random_bytes(32));q('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$recoveryUser,hash('sha256',$recoveryToken)]);
$contractApplications=[];foreach([0,1,0,1] as $i){q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')",[$ids[$i]['candidate'],$vac]);$contractApplications[]=(int)db()->lastInsertId();}
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'screening')",[$ids[1]['candidate'],$vac]);$expiryApp=(int)db()->lastInsertId();q("INSERT INTO employment_contracts(application_id,revision,title,terms,content_hash,method,status,expires_at,created_by) VALUES (?,1,'Expired synthetic contract','Expired terms',?,'internal','issued',DATE_SUB(NOW(),INTERVAL 1 DAY),?)",[$expiryApp,hash('sha256','Expired terms'),(int)val("SELECT id FROM users WHERE email='admin@test.invalid'")]);$expiredContract=(int)db()->lastInsertId();
echo json_encode(compact('lineWelder','lineLabour','lineOperator','expiredContract','contractApplications','a','b','ids','pb','req','task','vac','hotel','run','asset','token','supervisor','historyApp','client','clientUser','otherClient','otherJob','otherInvoice','recoveryUser','recoveryToken'));

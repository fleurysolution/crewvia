<?php
require_login();$own=is_worker_account();$supervisor=user()['role']==='supervisor';
if(!$own&&!$supervisor)require_role('recruiter','hotels');
$jobId=(int)($_GET['job_id']??$_POST['job_id']??current_job()['id']??0);
if($own)$allowed=row('SELECT p.id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE p.job_id=? AND w.user_id=?',[$jobId,uid()]);
elseif($supervisor)$allowed=row('SELECT p.id FROM placements p JOIN assignment_details d ON d.placement_id=p.id WHERE p.job_id=? AND d.supervisor_id=?',[$jobId,uid()]);
else $allowed=row('SELECT id FROM jobs WHERE id=?',[$jobId]);
if($jobId&&!$allowed){refuse(404, t('Project unavailable.'));}
if($_SERVER['REQUEST_METHOD']==='POST') {
    require_role('recruiter');$fields=[];
    foreach(['access_instructions','emergency_contacts','escalation_procedure','site_posts'] as $key){$fields[$key]=trim((string)($_POST[$key]??''));if(mb_strlen($fields[$key])>8000){refuse(422, t('Safety plan text is too long.'));}}
    if(!$jobId){refuse(422, t('Choose a project first.'));}
    q('INSERT INTO project_safety_plans(job_id,access_instructions,emergency_contacts,escalation_procedure,site_posts,reviewed_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE access_instructions=VALUES(access_instructions),emergency_contacts=VALUES(emergency_contacts),escalation_procedure=VALUES(escalation_procedure),site_posts=VALUES(site_posts),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW()',[$jobId,...array_values($fields),uid()]);
    log_activity('updated safety plan','project',$jobId);redirect('/safety-plan?job_id='.$jobId);
}
$plan=$jobId?row('SELECT * FROM project_safety_plans WHERE job_id=?',[$jobId]):null;
$jobs=$own?rows('SELECT DISTINCT j.id,j.title FROM jobs j JOIN placements p ON p.job_id=j.id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=?',[uid()]):($supervisor?rows('SELECT DISTINCT j.id,j.title FROM jobs j JOIN placements p ON p.job_id=j.id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=?',[uid()]):rows('SELECT id,title FROM jobs ORDER BY id DESC'));
render('safety-plan',compact('plan','jobId','jobs'));

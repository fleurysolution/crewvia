<?php
require_role('recruiter'); $job=current_job(); $jobId=(int)($job['id'] ?? 0); $inviteUrl=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='requirement' && $jobId) {
  $title=trim((string)($_POST['title'] ?? ''));
  if($title) q('INSERT INTO onboarding_requirements(job_id,title,instructions) VALUES (?,?,?)',[$jobId,$title,trim((string)($_POST['instructions'] ?? ''))]);
 }
 if($do==='configure_requirement') {
  $rid=(int)($_POST['requirement_id']??0);$instructions=trim((string)($_POST['instructions']??''));$required=isset($_POST['required'])?1:0;
  if(!$instructions||mb_strlen($instructions)>8000){refuse(422, t('Approved onboarding instructions are required.'));}
  db()->beginTransaction();$old=row('SELECT * FROM onboarding_requirements WHERE id=? AND job_id=? FOR UPDATE',[$rid,$jobId]);
  if(!$old){db()->rollBack();refuse(404, t('Requirement unavailable.'));}
  if($old['instructions']!==$instructions||(int)$old['required']!==$required) {
   q('UPDATE onboarding_requirements SET instructions=?,required=? WHERE id=?',[$instructions,$required,$rid]);
   q('INSERT INTO onboarding_requirement_events(requirement_id,user_id,previous_json,current_json) VALUES (?,?,?,?)',[$rid,uid(),json_encode($old,JSON_THROW_ON_ERROR),json_encode(['instructions'=>$instructions,'required'=>$required],JSON_THROW_ON_ERROR)]);
   q("UPDATE onboarding_tasks t JOIN placements p ON p.id=t.placement_id SET t.status='pending',t.reviewed_by=NULL,t.reviewed_at=NULL WHERE t.requirement_id=? AND p.status NOT IN ('completed','cancelled')",[$rid]);
  }
  db()->commit();log_activity('updated onboarding requirement','requirement',$rid);flash(t('Instructions updated. Active assignments require a new review.'));redirect('/onboarding');
 }
 if($do==='invite') {
  require_once __DIR__.'/../invitations.php';
  try { $inviteUrl=workforce_invite_candidate((int)($_POST['candidate_id'] ?? 0),true);if(!$inviteUrl) flash(t('The candidate already has a personal account.')); }catch(Throwable $error){flash(t($error->getMessage()),'err');}
 }
 if($do==='review') {
  $status=in_array($_POST['status'] ?? '',['approved','rejected'],true)?$_POST['status']:'rejected';
  q('UPDATE onboarding_tasks t JOIN placements p ON p.id=t.placement_id SET t.status=?,t.reviewed_by=?,t.reviewed_at=NOW() WHERE t.id=? AND p.job_id=?',[$status,uid(),(int)($_POST['task_id'] ?? 0),$jobId]);
  log_activity('reviewed onboarding','task',(int)($_POST['task_id'] ?? 0),$status);
 }
 if(!$inviteUrl) redirect('/onboarding');
}
if($jobId) q('INSERT IGNORE INTO onboarding_tasks(placement_id,requirement_id) SELECT p.id,r.id FROM placements p JOIN onboarding_requirements r ON r.job_id=p.job_id WHERE p.job_id=?',[$jobId]);
$requirements=rows('SELECT * FROM onboarding_requirements WHERE job_id=?',[$jobId]);
$tasks=rows('SELECT t.*,c.full_name,r.title FROM onboarding_tasks t JOIN placements p ON p.id=t.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN onboarding_requirements r ON r.id=t.requirement_id WHERE p.job_id=? ORDER BY c.full_name,r.id',[$jobId]);
$vacancies=rows('SELECT * FROM vacancies WHERE job_id=?',[$jobId]);
$applications=rows('SELECT a.*,c.full_name,v.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN vacancies v ON v.id=a.vacancy_id WHERE v.job_id=? ORDER BY a.id DESC',[$jobId]);

// People to choose between, instead of a number nothing shows.
$people = rows("SELECT c.id, c.full_name, c.discipline, c.city, c.state
                FROM candidates c
                ORDER BY c.full_name LIMIT 500");

render('onboarding',compact('requirements','tasks','vacancies','applications','inviteUrl','people'));

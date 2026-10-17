<?php
require_role('recruiter'); require_once __DIR__.'/../onboarding-tasks.php'; $job=current_job(); $jobId=(int)($job['id'] ?? 0); $inviteUrl=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='requirement' && $jobId) {
  $title=trim((string)($_POST['title'] ?? ''));
  // P5-M55: the same title twice on a project is refused; the crew on site is asked only when ticked.
  if($title && row('SELECT id FROM onboarding_requirements WHERE job_id=? AND title=?',[$jobId,$title])){refuse(422,t('This project already has that requirement.'),'/onboarding');}
  // P5-M64: a requirement can hold its form (W-4, I-9, direct deposit), filled in the task itself.
  $kind=in_array($_POST['form_kind']??'',['w4','i9','bank'],true)?$_POST['form_kind']:null;
  if($title) q('INSERT INTO onboarding_requirements(job_id,title,instructions,for_crew,form_kind) VALUES (?,?,?,?,?)',[$jobId,$title,trim((string)($_POST['instructions'] ?? '')),isset($_POST['for_crew'])?1:0,$kind]);
 }
 // P5-M55: a requirement nobody has answered yet can be removed, with its untouched tasks.
 if($do==='remove_requirement') {
  $rid=(int)($_POST['requirement_id']??0);
  if(!row('SELECT id FROM onboarding_requirements WHERE id=? AND job_id=?',[$rid,$jobId])){refuse(404,t('Requirement unavailable.'),'/onboarding');}
  if(!onboarding_requirement_removable($rid)){refuse(422,t('Somebody has already answered this requirement: change its instructions instead.'),'/onboarding');}
  db()->beginTransaction();q('DELETE FROM onboarding_tasks WHERE requirement_id=?',[$rid]);q('DELETE FROM onboarding_requirement_events WHERE requirement_id=?',[$rid]);q('DELETE FROM onboarding_requirements WHERE id=?',[$rid]);db()->commit();
  log_activity('removed onboarding requirement','requirement',$rid);flash(t('Requirement removed, with the tasks nobody had started.'));redirect('/onboarding');
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
  // P5-M64: the form the task holds.
  $kind=in_array($_POST['form_kind']??'',['w4','i9','bank'],true)?$_POST['form_kind']:null;
  if(($old['form_kind']??null)!==$kind){q('UPDATE onboarding_requirements SET form_kind=? WHERE id=?',[$kind,$rid]);}
  // P5-M55: asking the crew on site too, or no longer (their untouched tasks go; what they answered stays).
  $forCrew=isset($_POST['for_crew'])?1:0;
  if((int)$old['for_crew']!==$forCrew){
   q('UPDATE onboarding_requirements SET for_crew=? WHERE id=?',[$forCrew,$rid]);
   if(!$forCrew)q("DELETE t FROM onboarding_tasks t JOIN placements p ON p.id=t.placement_id WHERE t.requirement_id=? AND p.status='on_site' AND t.status='pending' AND t.response IS NULL AND t.reviewed_at IS NULL",[$rid]);
  }
  db()->commit();log_activity('updated onboarding requirement','requirement',$rid);flash(t('Instructions updated. Active assignments require a new review.'));redirect('/onboarding');
 }
 if($do==='invite') {
  require_once __DIR__.'/../invitations.php';
  try { $inviteUrl=workforce_invite_candidate((int)($_POST['candidate_id'] ?? 0),true);if(!$inviteUrl) flash(t('The candidate already has a personal account.')); }catch(Throwable $error){flash(t($error->getMessage()),'err');}
 }
 if($do==='review') {
  $status=in_array($_POST['status'] ?? '',['approved','rejected'],true)?$_POST['status']:'rejected';
  // P5-M64: a task holding a form closes with its form, checked on Change requests.
  if(val('SELECT r.form_kind FROM onboarding_tasks t JOIN onboarding_requirements r ON r.id=t.requirement_id WHERE t.id=?',[(int)($_POST['task_id'] ?? 0)])){refuse(422,t('This task closes by itself when its form is checked on Change requests.'),'/onboarding');}
  // Walkthrough finding 25: a task the worker had not done could be approved, which opened the deployment gate.
  // Only what the worker submitted is reviewed.
  $reviewed=db()->prepare("UPDATE onboarding_tasks t JOIN placements p ON p.id=t.placement_id SET t.status=?,t.reviewed_by=?,t.reviewed_at=NOW() WHERE t.id=? AND p.job_id=? AND t.status='submitted'");
  $reviewed->execute([$status,uid(),(int)($_POST['task_id'] ?? 0),$jobId]);
  if(!$reviewed->rowCount()){flash(t('Only a task the worker has submitted can be approved or sent back.'),'err');redirect('/onboarding');}
  log_activity('reviewed onboarding','task',(int)($_POST['task_id'] ?? 0),$status);
  // P5-M66: the last task approved may clear the person for deployment: the office is told.
  if($status==='approved'){require_once __DIR__.'/../deployment.php';deployment_ready_notice((int)val('SELECT p.candidate_id FROM onboarding_tasks t JOIN placements p ON p.id=t.placement_id WHERE t.id=?',[(int)($_POST['task_id'] ?? 0)]));}
  flash($status==='approved'?t('Task approved.'):t('Task sent back to the worker.'));
 }
 if(!$inviteUrl) redirect('/onboarding');
}
// P5-M55: the new hires get the project's requirements; the crew on site only those meant for them too.
if($jobId) onboarding_sync('p.job_id = ?',[$jobId]);
if($jobId){require_once __DIR__.'/../deployment.php';deployment_ready_notice(null,$jobId);}
$requirements=rows('SELECT * FROM onboarding_requirements WHERE job_id=?',[$jobId]);
$tasks=rows('SELECT t.*,c.full_name,r.title,r.form_kind FROM onboarding_tasks t JOIN placements p ON p.id=t.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN onboarding_requirements r ON r.id=t.requirement_id WHERE p.job_id=? ORDER BY c.full_name,r.id',[$jobId]);
$vacancies=rows('SELECT * FROM vacancies WHERE job_id=?',[$jobId]);
$applications=rows('SELECT a.*,c.full_name,v.title FROM applications a JOIN candidates c ON c.id=a.candidate_id JOIN vacancies v ON v.id=a.vacancy_id WHERE v.job_id=? ORDER BY a.id DESC',[$jobId]);

// People to choose between, instead of a number nothing shows.
$people = rows("SELECT c.id, c.full_name, c.discipline, c.city, c.state
                FROM candidates c
                ORDER BY c.full_name LIMIT 500");

render('onboarding',compact('requirements','tasks','vacancies','applications','inviteUrl','people'));

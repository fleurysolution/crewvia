<?php
require_role('recruiter');require_once __DIR__.'/../contracts.php';$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $id=(int)($_POST['application_id'] ?? 0);$do=$_POST['do'] ?? '';
 $a=row('SELECT a.* FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? AND v.job_id=?',[$id,$jobId]);
 if(!$a) { refuse(404, t('Application unavailable in this project.')); }
 if($do==='stage') {
  $stage=(string)($_POST['stage'] ?? '');
  $note=trim((string)($_POST['note'] ?? ''));

  // A settled application does not move again. Its menu is disabled, so
  // the browser sends no stage at all and this would otherwise fall
  // through every branch and redirect as though something had happened.
  if(in_array($a['stage'],['accepted','rejected','withdrawn'],true)) {
   flash(t('That application is settled. Raise a new one for the same person if they come back.'),'err');
   redirect('/recruitment');
  }

  // A note on its own is a decision worth keeping. It used to be written
  // only as part of a stage change, so recording why somebody is being
  // held at screening required pretending to move them.
  if($stage===''||$stage===$a['stage']) {
   if($note==='') { flash(t('Nothing to record: change the stage or write a note.'),'err');redirect('/recruitment'); }
   q('INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,?,?)',[$id,uid(),$a['stage'],$note]);
   log_activity('noted an application','application',$id,$note);
   flash(t('Note recorded against the application.'));
   redirect('/recruitment');
  }

  if(in_array($stage,['new','screening','interview','offered','accepted','rejected','withdrawn'],true)) {
   if(in_array($stage,['offered','accepted'],true) && (int)val("SELECT COUNT(*) FROM screening_checks WHERE application_id=? AND status<>'passed'",[$id])) { flash(t('Complete screening checks before offering this job.'),'err');redirect('/recruitment'); }
   if(in_array($stage,['offered','accepted'],true) && (int)val("SELECT COUNT(*) FROM screening_questions q LEFT JOIN screening_answers s ON s.question_id=q.id AND s.application_id=? WHERE q.job_id=? AND q.required=1 AND (s.answer IS NULL OR TRIM(s.answer)='')",[$id,$jobId])) {flash(t('Complete required screening questions before offering this job.'),'err');redirect('/recruitment');}
   if($stage==='offered' && (contract_document_gaps((int)$a['candidate_id'],$jobId)||contract_latest($id)||val('SELECT required_before_deployment FROM contract_policies WHERE job_id=?',[$jobId]))) {flash(t('Use the contract workflow after required document verification.'),'err');redirect('/recruitment');}
   if($stage==='offered' && !trim((string)($_POST['note'] ?? ''))) { flash(t('Enter the complete offer terms before issuing an offer.'),'err');redirect('/recruitment'); }
   if($stage==='accepted') { flash(t('The candidate must accept their offer in their private portal.'),'err');redirect('/recruitment'); }
   q('UPDATE applications SET stage=? WHERE id=?',[$stage,$id]);
   q('INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,?,?)',[$id,uid(),$stage,trim((string)($_POST['note'] ?? ''))]);
   if(in_array($stage,['screening','offered'],true)) {
    require_once __DIR__.'/../invitations.php';
    try { workforce_invite_candidate((int)$a['candidate_id']); }catch(Throwable $error) {flash(t('Stage recorded. Invitation needs attention: :message', ['message'=>t($error->getMessage())]),'err');}
   }
  }
 }
 if($do==='check') { $title=trim((string)($_POST['title'] ?? ''));if($title) q('INSERT INTO screening_checks(application_id,title) VALUES (?,?)',[$id,$title]); }
 if($do==='review') {
  $status=$_POST['status'] ?? '';
  if(in_array($status,['pending','passed','failed'],true)) q('UPDATE screening_checks SET status=?,reviewed_by=?,reviewed_at=NOW(),note=? WHERE id=? AND application_id=?',[$status,uid(),trim((string)($_POST['note'] ?? '')),(int)($_POST['check_id'] ?? 0),$id]);
 }
 log_activity('recruitment update','application',$id,$do);redirect('/recruitment');
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*100;
$applications=rows('SELECT a.*,v.title,c.full_name,COALESCE(v.pay_rate,l.pay_rate) rate,l.role_title line_role FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN candidates c ON c.id=a.candidate_id LEFT JOIN job_order_lines l ON l.id=v.order_line_id WHERE v.job_id=? ORDER BY a.screened_out ASC, a.screening_score IS NULL, a.screening_score DESC, a.id DESC LIMIT 100 OFFSET '.$offset,[$jobId]);
$visible=array_column($applications,'id');$visibleIds=$visible?implode(',',array_map('intval',$visible)):'0';
$checks=rows('SELECT s.* FROM screening_checks s JOIN applications a ON a.id=s.application_id JOIN vacancies v ON v.id=a.vacancy_id WHERE v.job_id=? AND a.id IN ('.$visibleIds.')',[$jobId]);
$identityMatches=rows("SELECT DISTINCT nc.id applicant_id,old.id,old.full_name,old.stage FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN candidates nc ON nc.id=a.candidate_id JOIN candidates old ON old.id<>nc.id AND ((nc.email IS NOT NULL AND nc.email<>'' AND old.normalized_email=nc.normalized_email) OR (nc.phone IS NOT NULL AND nc.phone<>'' AND old.normalized_phone=nc.normalized_phone) OR old.normalized_name=nc.normalized_name) WHERE v.job_id=? AND a.id IN (".$visibleIds.")",[$jobId]);
render('recruitment',compact('applications','checks','identityMatches','page'));

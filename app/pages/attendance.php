<?php
require_once __DIR__.'/../attendance-controls.php';
require_login();$own=is_worker_account();$supervisor=user()['role']==='supervisor';$jobId=(int)(current_job()['id'] ?? 0);
if(!$own && !$supervisor) require_role('payroll');
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='submit') {
  $pid=(int)($_POST['placement_id'] ?? 0);$date=(string)($_POST['work_date'] ?? '');$hours=$_POST['hours'] ?? '';
  $p=row("SELECT p.* FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE p.id=? AND w.user_id=? AND p.status IN ('confirmed','travelling','on_site')",[$pid,uid()]);
  if(!$p || !valid_date($date) || !is_numeric($hours) || !is_finite((float)$hours) || (float)$hours<0 || (float)$hours>24 || ($p['start_date'] && $date<$p['start_date']) || ($p['end_date'] && $date>$p['end_date'])) { refuse(422, t('Valid working date, assignment and 0–24 hours required.')); }
  $locked=row("SELECT id FROM attendance_records WHERE placement_id=? AND work_date=? AND status='approved'",[$pid,$date]);
  if(!$locked) q("INSERT INTO attendance_records(placement_id,work_date,hours,submitted_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE hours=VALUES(hours),status='submitted'",[$pid,$date,(float)$hours,uid()]);
 }
 if($do==='review') {
  require_role('payroll','supervisor');$id=(int)($_POST['attendance_id'] ?? 0);$status=$_POST['status'] ?? '';
  $allowed=$supervisor?row('SELECT a.id FROM attendance_records a JOIN assignment_details d ON d.placement_id=a.placement_id WHERE a.id=? AND d.supervisor_id=? AND a.submitted_by<>?',[$id,uid(),uid()]):row('SELECT a.id FROM attendance_records a JOIN placements p ON p.id=a.placement_id WHERE a.id=? AND p.job_id=? AND a.submitted_by<>?',[$id,$jobId,uid()]);
  if($allowed && in_array($status,['approved','rejected'],true)) q("UPDATE attendance_records SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='submitted'",[$status,uid(),$id]);
 }
 // An approved day that was wrong. The old hours and the reason are kept,
 // and the worker sees both.
 if($do==='correct') {
  require_role('payroll','supervisor');
  db()->beginTransaction();
  $refusal=attendance_correct((int)($_POST['attendance_id'] ?? 0),$_POST['hours'] ?? '',(string)($_POST['reason'] ?? ''),uid());
  if($refusal!==null) { db()->rollBack();refuse(422,$refusal); }
  db()->commit();
  log_activity('corrected approved attendance','attendance',(int)$_POST['attendance_id'],(string)$_POST['hours'].' h - '.mb_substr((string)$_POST['reason'],0,200));
  flash(t('Day corrected. The previous hours and your reason are kept on the record.'));
  redirect('/attendance');
 }
 // A day the worker could not enter (R26): validated by whoever enters it,
 // with a note saying why.
 if($do==='enter') {
  require_role('payroll','supervisor');
  db()->beginTransaction();
  $pid=(int)($_POST['placement_id'] ?? 0);
  $refusal=attendance_enter_for_worker($pid,(string)($_POST['work_date'] ?? ''),$_POST['hours'] ?? '',(string)($_POST['note'] ?? ''),uid());
  if($refusal!==null) { db()->rollBack();refuse(422,$refusal); }
  db()->commit();
  log_activity('entered attendance for a worker','placement',$pid,(string)$_POST['work_date'].' '.(string)$_POST['hours'].' h');
  flash(t('Day recorded and approved, with your note.'));
  redirect('/attendance');
 }
 log_activity('attendance update','project',$jobId,$do);redirect('/attendance');
}
if($supervisor) {$records=rows('SELECT a.*,c.full_name,j.title FROM attendance_records a JOIN placements p ON p.id=a.placement_id JOIN jobs j ON j.id=p.job_id JOIN candidates c ON c.id=p.candidate_id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=? ORDER BY a.work_date DESC LIMIT 300',[uid()]);$assignments=[];}
else if($own) { $records=rows('SELECT a.*,c.full_name,j.title FROM attendance_records a JOIN placements p ON p.id=a.placement_id JOIN jobs j ON j.id=p.job_id JOIN candidates c ON c.id=p.candidate_id JOIN worker_accounts w ON w.candidate_id=c.id WHERE w.user_id=? ORDER BY a.work_date DESC LIMIT 300',[uid()]);$assignments=rows("SELECT p.id,j.title FROM placements p JOIN jobs j ON j.id=p.job_id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=? AND p.status IN ('confirmed','travelling','on_site')",[uid()]);}
else {$records=rows('SELECT a.*,c.full_name,j.title FROM attendance_records a JOIN placements p ON p.id=a.placement_id JOIN jobs j ON j.id=p.job_id JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? ORDER BY a.work_date DESC LIMIT 300',[$jobId]);$assignments=[];}
$corrections=attendance_corrections_for(array_column($records,'id'));
// Who the staff member can enter a day for: their own crew, or the project.
$crew=[];
if($supervisor) $crew=rows("SELECT p.id,c.full_name,j.title FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=? AND p.status IN ('confirmed','travelling','on_site','completed') ORDER BY c.full_name",[uid()]);
else if(!$own && can('payroll')) $crew=rows("SELECT p.id,c.full_name,j.title FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE p.job_id=? AND p.status IN ('confirmed','travelling','on_site','completed') ORDER BY c.full_name",[$jobId]);
render('attendance',compact('records','assignments','own','supervisor','corrections','crew'));

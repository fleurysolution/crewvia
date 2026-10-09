<?php
require_login();$isWorker=is_worker_account();$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='request') {
  $pid=(int)($_POST['placement_id'] ?? 0);$from=(string)($_POST['starts_on'] ?? '');$to=(string)($_POST['ends_on'] ?? '');
  $own=row("SELECT p.id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=? AND p.id=? AND p.status NOT IN ('completed','cancelled')",[uid(),$pid]);
  if(!$own || !valid_date($from) || !valid_date($to) || $to<$from) { refuse(422, t('Invalid assignment or dates.')); }
  q('INSERT INTO time_off_requests(placement_id,user_id,starts_on,ends_on,request_type,reason) VALUES (?,?,?,?,?,?)',[$pid,uid(),$from,$to,mb_substr(trim((string)($_POST['request_type'] ?? 'Time off')),0,120),trim((string)($_POST['reason'] ?? ''))]);
  q("INSERT INTO notifications(user_id,message,target) SELECT id,'Time-off request awaiting review','/timeoff' FROM users WHERE role='admin' AND is_active=1");
 }
 if($do==='review') {
  require_role('recruiter','supervisor');$status=$_POST['status'] ?? ''; $id=(int)($_POST['request_id'] ?? 0);
  if(in_array($status,['approved','rejected'],true)) {
   $r=user()['role']==='supervisor' ? row("SELECT r.* FROM time_off_requests r JOIN assignment_details d ON d.placement_id=r.placement_id WHERE r.id=? AND d.supervisor_id=? AND r.status='pending'",[$id,uid()]) : row("SELECT r.* FROM time_off_requests r JOIN placements p ON p.id=r.placement_id WHERE r.id=? AND p.job_id=? AND r.status='pending'",[$id,$jobId]);
   if($r && $r['user_id']!==uid()) {
    q("UPDATE time_off_requests SET status=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='pending'",[$status,uid(),trim((string)($_POST['review_note'] ?? '')),$id]);
    q('INSERT INTO notifications(user_id,message,target) VALUES (?,?,?)',[$r['user_id'],'Time-off request '.$status,'/timeoff']);log_activity('reviewed time off','request',$id,$status);
   }
  }
 }
 redirect('/timeoff');
}
if($isWorker && user()['role']!=='supervisor') {
 $requests=rows('SELECT r.*,j.title project FROM time_off_requests r JOIN placements p ON p.id=r.placement_id JOIN jobs j ON j.id=p.job_id WHERE r.user_id=? ORDER BY r.id DESC',[uid()]);
 $assignments=rows("SELECT p.id,j.title FROM placements p JOIN jobs j ON j.id=p.job_id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=? AND p.status NOT IN ('completed','cancelled')",[uid()]);
}else if(user()['role']==='supervisor') { $isWorker=false;$assignments=[];$requests=rows('SELECT r.*,c.full_name,j.title project FROM time_off_requests r JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id JOIN assignment_details d ON d.placement_id=p.id WHERE d.supervisor_id=? ORDER BY r.id DESC',[uid()]); }else { require_role('recruiter','supervisor');$requests=rows('SELECT r.*,c.full_name,j.title project FROM time_off_requests r JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id WHERE p.job_id=? ORDER BY r.id DESC',[$jobId]);$assignments=[]; }
render('timeoff',compact('requests','assignments','isWorker'));

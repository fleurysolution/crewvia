<?php
require_login();$own=is_worker_account();$jobId=(int)(current_job()['id'] ?? 0);
if(!$own) require_role('recruiter');
if($_SERVER['REQUEST_METHOD']==='POST') {
 require_role('recruiter');$do=$_POST['do'] ?? '';
 if($do==='issue') {
  $pid=(int)($_POST['placement_id'] ?? 0);$kind=$_POST['kind'] ?? '';$mode=$_POST['delivery_mode'] ?? '';$payer=$_POST['payer'] ?? '';
  $worker=row('SELECT p.id,w.user_id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE p.id=? AND p.job_id=?',[$pid,$jobId]);
  $authorization=trim((string)($_POST['authorization_text'] ?? ''));
  if(!$worker || !$authorization || !in_array($kind,['background','drug'],true) || !in_array($mode,['agency','mobile_bus','onsite'],true) || !in_array($payer,['agency','client','worker_reimbursement'],true)) { refuse(422, t('Worker portal, authorization text and valid screening details are required.')); }
  db()->beginTransaction();q('INSERT INTO signed_acknowledgements(placement_id,user_id,title,document_text,document_hash) VALUES (?,?,?,?,?)',[$pid,$worker['user_id'],ucfirst($kind).' screening authorization',$authorization,hash('sha256',$authorization)]);$auth=(int)db()->lastInsertId();
  q('INSERT INTO screening_cases(placement_id,kind,provider_name,delivery_mode,location,instructions,authorization_id,payer) VALUES (?,?,?,?,?,?,?,?)',[$pid,$kind,trim((string)($_POST['provider_name'] ?? '')),$mode,trim((string)($_POST['location'] ?? '')),trim((string)($_POST['instructions'] ?? '')),$auth,$payer]);
  q('INSERT INTO notifications(user_id,message,target) VALUES (?,?,?)',[$worker['user_id'],'Screening authorization awaiting signature','/agreements']);db()->commit();
 }
 if($do==='status') {
  $id=(int)($_POST['case_id'] ?? 0);$status=$_POST['status'] ?? '';
  $case=row('SELECT s.*,a.status consent FROM screening_cases s JOIN placements p ON p.id=s.placement_id LEFT JOIN signed_acknowledgements a ON a.id=s.authorization_id WHERE s.id=? AND p.job_id=?',[$id,$jobId]);
  if($case && in_array($status,['scheduled','completed','cleared','not_cleared','cancelled'],true)) {
   if($status!=='cancelled' && $case['consent']!=='signed') { flash(t('Candidate authorization must be signed before screening proceeds.'),'err');redirect('/checks'); }
   q('UPDATE screening_cases SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',[$status,uid(),$id]);log_activity('screening status','case',$id,$status);
  }
 }
 redirect('/checks');
}
if($own) { $cases=rows('SELECT s.*,j.title project,NULL full_name,a.status consent FROM screening_cases s JOIN placements p ON p.id=s.placement_id JOIN jobs j ON j.id=p.job_id JOIN worker_accounts w ON w.candidate_id=p.candidate_id LEFT JOIN signed_acknowledgements a ON a.id=s.authorization_id WHERE w.user_id=? ORDER BY s.id DESC',[uid()]);$crew=[]; }
else { $cases=rows('SELECT s.*,j.title project,c.full_name,a.status consent FROM screening_cases s JOIN placements p ON p.id=s.placement_id JOIN jobs j ON j.id=p.job_id JOIN candidates c ON c.id=p.candidate_id LEFT JOIN signed_acknowledgements a ON a.id=s.authorization_id WHERE p.job_id=? ORDER BY s.id DESC',[$jobId]);$crew=rows('SELECT p.id,c.full_name FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN worker_accounts w ON w.candidate_id=c.id WHERE p.job_id=?',[$jobId]); }
render('checks',compact('own','cases','crew'));

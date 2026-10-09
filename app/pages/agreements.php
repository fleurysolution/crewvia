<?php
require_login();$isWorker=is_worker_account();$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='issue') {
  require_role('recruiter');$pid=(int)($_POST['placement_id'] ?? 0);$text=trim((string)($_POST['document_text'] ?? ''));$title=trim((string)($_POST['title'] ?? ''));
  $w=row('SELECT w.user_id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE p.id=? AND p.job_id=?',[$pid,$jobId]);
  if($w && $text && $title) { q('INSERT INTO signed_acknowledgements(placement_id,user_id,title,document_text,document_hash) VALUES (?,?,?,?,?)',[$pid,$w['user_id'],$title,$text,hash('sha256',$text)]);q('INSERT INTO notifications(user_id,message,target) VALUES (?,?,?)',[$w['user_id'],'Document awaiting acknowledgement','/agreements']); }
 }
 if($do==='sign') {
  $name=trim((string)($_POST['signer_name'] ?? ''));$id=(int)($_POST['document_id'] ?? 0);
  if($name && strlen($name)<=190 && isset($_POST['consent'])) { q("UPDATE signed_acknowledgements SET signer_name=?,signed_at=NOW(),status='signed' WHERE id=? AND user_id=? AND status='pending'",[$name,$id,uid()]);log_activity('signed acknowledgement','document',$id); }
 }
 redirect('/agreements');
}
if($isWorker) {$docs=rows('SELECT * FROM signed_acknowledgements WHERE user_id=? ORDER BY id DESC',[uid()]);$crew=[];}
else { require_role('recruiter');$docs=rows('SELECT d.*,c.full_name FROM signed_acknowledgements d JOIN placements p ON p.id=d.placement_id JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? ORDER BY d.id DESC',[$jobId]);$crew=rows('SELECT p.id,c.full_name FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN worker_accounts w ON w.candidate_id=c.id WHERE p.job_id=?',[$jobId]); }
render('agreements',compact('docs','crew','isWorker'));

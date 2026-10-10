<?php
require_login();$own=is_worker_account();$jobId=(int)(current_job()['id'] ?? 0);
if(!$own) require_role('payroll');
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='claim') {
  $pid=(int)($_POST['placement_id'] ?? 0);$doc=(int)($_POST['receipt_document_id'] ?? 0);$amount=$_POST['amount'] ?? ''; $category=$_POST['category'] ?? '';
  $p=row('SELECT p.id,p.candidate_id FROM placements p JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE p.id=? AND w.user_id=?',[$pid,uid()]);
  if(!$p || !is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<=0 || !in_array($category,['flight','hotel','screening','transport','other'],true) || !row('SELECT id FROM worker_documents WHERE id=? AND candidate_id=?',[$doc,$p['candidate_id']])) { refuse(422, t('Valid assignment, positive amount and your own receipt are required.')); }
  q('INSERT INTO expense_claims(placement_id,user_id,category,amount,receipt_document_id,note) VALUES (?,?,?,?,?,?)',[$pid,uid(),$category,(float)$amount,$doc,trim((string)($_POST['note'] ?? ''))]);
 }
 if($do==='review') {
  require_role('payroll');$status=$_POST['status'] ?? '';
  if(in_array($status,['approved','rejected'],true)) q("UPDATE expense_claims e JOIN placements p ON p.id=e.placement_id SET e.status=?,e.reviewed_by=?,e.reviewed_at=NOW() WHERE e.id=? AND p.job_id=? AND e.status='submitted' AND e.user_id<>?",[$status,uid(),(int)($_POST['claim_id'] ?? 0),$jobId,uid()]);
 }
 if($do==='pay') {
  require_role('payroll');require_once __DIR__.'/../periods.php';if($why=period_guard(date('Y-m-d'))) { refuse(422,$why); }$method=$_POST['payment_method'] ?? '';$ref=trim((string)($_POST['payment_reference'] ?? ''));
  if($ref && in_array($method,['direct_deposit','check','cash'],true)) q("UPDATE expense_claims e JOIN placements p ON p.id=e.placement_id SET e.status='paid',e.payment_method=?,e.payment_reference=?,e.paid_at=NOW() WHERE e.id=? AND p.job_id=? AND e.status='approved'",[$method,$ref,(int)($_POST['claim_id'] ?? 0),$jobId]);
 }
 log_activity('expense workflow','project',$jobId,$do);redirect('/expenses');
}
if($own) {
 $claims=rows('SELECT e.*,j.title,NULL full_name FROM expense_claims e JOIN placements p ON p.id=e.placement_id JOIN jobs j ON j.id=p.job_id WHERE e.user_id=? ORDER BY e.id DESC',[uid()]);
 $assignments=rows('SELECT p.id,j.title FROM placements p JOIN jobs j ON j.id=p.job_id JOIN worker_accounts w ON w.candidate_id=p.candidate_id WHERE w.user_id=?',[uid()]);
 $receipts=rows('SELECT d.id,d.document_type FROM worker_documents d JOIN worker_accounts w ON w.candidate_id=d.candidate_id WHERE w.user_id=?',[uid()]);
} else { $claims=rows('SELECT e.*,j.title,c.full_name FROM expense_claims e JOIN placements p ON p.id=e.placement_id JOIN jobs j ON j.id=p.job_id JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? ORDER BY e.id DESC',[$jobId]);$assignments=$receipts=[]; }
render('expenses',compact('own','claims','assignments','receipts'));

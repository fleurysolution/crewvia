<?php
require_login();$isWorker=is_worker_account();$candidate=$isWorker?row('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):null;
if(!$isWorker) require_role('recruiter');
$cid=(int)($candidate['candidate_id'] ?? $_POST['candidate_id'] ?? $_GET['candidate_id'] ?? 0);
if($cid && row('SELECT id FROM candidates WHERE id=?',[$cid])) q('INSERT IGNORE INTO employment_clearance(candidate_id) VALUES (?)',[$cid]);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? ''; $docId=(int)($_POST['document_id'] ?? 0);
 $doc=row('SELECT id FROM worker_documents WHERE id=? AND candidate_id=?',[$docId,$cid]);
 if($do==='section1' && $isWorker && $doc && isset($_POST['attest'])) q("UPDATE employment_clearance SET section1_document_id=?,i9_status='employee_submitted' WHERE candidate_id=? AND i9_status<>'employer_completed'",[$docId,$cid]);
 if($do==='w4' && $isWorker && $doc && isset($_POST['attest'])) q("UPDATE employment_clearance SET w4_document_id=?,w4_status='submitted' WHERE candidate_id=? AND w4_status<>'reviewed'",[$docId,$cid]);
 if($do==='employer') {
  require_role('recruiter');$name=trim((string)($_POST['verifier_name'] ?? ''));$method=$_POST['verification_method'] ?? '';$first=(string)($_POST['first_day'] ?? '');$due=(string)($_POST['review_due_on'] ?? '');
  $case=row('SELECT * FROM employment_clearance WHERE candidate_id=?',[$cid]);
  if(!$doc || !$name || !$case || !$case['section1_document_id'] || !valid_date($first) || !valid_date($due) || !in_array($method,['in_person','authorized_representative'],true) || !isset($_POST['attest'])) { refuse(422, t('Completed Section 1, employer-signed form evidence, verifier and dates are required.')); }
  q("UPDATE employment_clearance SET first_day=?,review_due_on=?,section2_document_id=?,verifier_name=?,verification_method=?,verified_by=?,verified_at=NOW(),i9_status='employer_completed' WHERE candidate_id=?",[$first,$due,$docId,$name,$method,uid(),$cid]);
 }
 if($do==='review_w4') {
  require_role('recruiter');$case=row('SELECT w4_document_id FROM employment_clearance WHERE candidate_id=?',[$cid]);
  if($case && $case['w4_document_id']) q("UPDATE employment_clearance SET w4_status='reviewed' WHERE candidate_id=?",[$cid]);
 }
 log_activity('employment document workflow','candidate',$cid,$do);redirect('/employment?candidate_id='.$cid);
}
$case=$cid?row('SELECT e.*,c.full_name FROM employment_clearance e JOIN candidates c ON c.id=e.candidate_id WHERE e.candidate_id=?',[$cid]):null;
$documents=$cid?rows('SELECT id,document_type,status FROM worker_documents WHERE candidate_id=? ORDER BY id DESC',[$cid]):[];
$candidates=$isWorker?[]:rows('SELECT DISTINCT c.id,c.full_name FROM candidates c JOIN placements p ON p.candidate_id=c.id WHERE p.job_id=? ORDER BY c.full_name',[(int)(current_job()['id'] ?? 0)]);
render('employment',compact('isWorker','case','documents','candidates','cid'));

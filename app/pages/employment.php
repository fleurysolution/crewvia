<?php
require_login();$isWorker=is_worker_account();$candidate=$isWorker?row('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):null;
if(!$isWorker) require_role('recruiter');
$cid=(int)($candidate['candidate_id'] ?? $_POST['candidate_id'] ?? $_GET['candidate_id'] ?? 0);
if($cid && row('SELECT id FROM candidates WHERE id=?',[$cid])) q('INSERT IGNORE INTO employment_clearance(candidate_id) VALUES (?)',[$cid]);
require_once __DIR__.'/../identity-docs.php';
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? ''; $docId=(int)($_POST['document_id'] ?? 0);
 // P5-M65: whether the employer uses E-Verify; when it does, the I-9 asks for the SSN and each case is recorded.
 if($do==='everify_setting') {
  require_role('admin');
  q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('everify_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",[isset($_POST['enabled'])?'1':'0']);
  log_activity('set E-Verify use','setting',0,isset($_POST['enabled'])?'on':'off');flash(t('Saved.'));redirect('/employment'.($cid?'?candidate_id='.$cid:''));
 }
 // P5-M33: the W-4 signed in person. The coordinator makes the code; the worker uses it from their own portal.
 if($do==='signing_code') {
  require_role('recruiter');
  if(!$cid || !row('SELECT id FROM candidates WHERE id=?',[$cid])) { refuse(422, t('Select an existing candidate.')); }
  [$code,$expires]=signing_code_make($cid,uid());
  $_SESSION['signing_code']=['candidate_id'=>$cid,'code'=>$code,'expires'=>$expires];
  redirect('/employment?candidate_id='.$cid.'#signing');
 }
 if($do==='w4_in_person') {
  if(!$isWorker) { refuse(403, t('The worker signs from their own portal.')); }
  if($why=signing_code_use($cid,(string)($_POST['code']??''),$docId,isset($_POST['attest']),uid())) { refuse(422,$why,'/employment#sign'); }
  flash(t('Your W-4 is signed, witnessed in person. Payroll reviews it.'));redirect('/employment');
 }
 $doc=row('SELECT id FROM worker_documents WHERE id=? AND candidate_id=?',[$docId,$cid]);
 if($do==='section1' && $isWorker && $doc && isset($_POST['attest'])) q("UPDATE employment_clearance SET section1_document_id=?,i9_status='employee_submitted' WHERE candidate_id=? AND i9_status<>'employer_completed'",[$docId,$cid]);
 if($do==='w4' && $isWorker && $doc && isset($_POST['attest'])) q("UPDATE employment_clearance SET w4_document_id=?,w4_status='submitted' WHERE candidate_id=? AND w4_status<>'reviewed'",[$docId,$cid]);
 if($do==='employer') {
  require_role('recruiter');$name=trim((string)($_POST['verifier_name'] ?? ''));$method=$_POST['verification_method'] ?? '';$first=(string)($_POST['first_day'] ?? '');$due=(string)($_POST['review_due_on'] ?? '');
  $case=row('SELECT * FROM employment_clearance WHERE candidate_id=?',[$cid]);
  if(!$doc || !$name || !$case || (!$case['section1_document_id'] && !$case['i9_form_id']) || !valid_date($first) || !valid_date($due) || !in_array($method,['in_person','authorized_representative'],true) || !isset($_POST['attest'])) { refuse(422, t('Completed Section 1, employer-signed form evidence, verifier and dates are required.')); }
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
// P5-M33: a code is shown once, to the coordinator who made it, while it is valid.
$signing=null;
if(!$isWorker && isset($_SESSION['signing_code']) && (int)$_SESSION['signing_code']['candidate_id']===$cid && $_SESSION['signing_code']['expires']>date('Y-m-d H:i:s')){$signing=$_SESSION['signing_code'];unset($_SESSION['signing_code']);}
$witness=$case && !empty($case['w4_witness_id'])?(string)val('SELECT name FROM users WHERE id=?',[(int)$case['w4_witness_id']]):'';
$signFromLink=$isWorker?preg_replace('/\D/','',(string)($_GET['sign']??'')):'';
// P5-M64: the W-4 and the I-9 signed in the portal.
require_once __DIR__.'/../tax-forms.php';
$portalForms=$cid?['w4'=>tf_history($cid,'w4')[0]??null,'i9'=>tf_history($cid,'i9')[0]??null]:[];
$everify=tf_everify();$i9Accepted=$cid?row("SELECT id,everify_case,everify_result,everify_at FROM worker_tax_forms WHERE candidate_id=? AND form='i9' AND status='accepted' ORDER BY id DESC LIMIT 1",[$cid]):null;
render('employment',compact('isWorker','case','documents','candidates','cid','signing','witness','signFromLink','portalForms','everify','i9Accepted'));

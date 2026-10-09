<?php
require_login();$own=is_worker_account();$cid=$own?(int)val('SELECT candidate_id FROM worker_accounts WHERE user_id=?',[uid()]):(int)($_GET['id'] ?? $_POST['candidate_id'] ?? 0);
if(!$own) require_role('recruiter','payroll');
// Opened from the menu there is no id, and nowhere in the interface shows
// one. Asking for a person is the answer; refusing is not.
if(!$own && !$cid) {
 $search=trim((string)($_GET['q'] ?? ''));
 $like='%'.str_replace(['%','_'],['\%','\_'],$search).'%';
 $people=$search!==''
  ? rows('SELECT c.id,c.full_name,c.discipline,c.city,c.state,c.stage,p.employee_number
          FROM candidates c LEFT JOIN employee_profiles p ON p.candidate_id=c.id
          WHERE c.full_name LIKE ? OR c.email LIKE ? OR p.employee_number LIKE ?
          ORDER BY c.full_name LIMIT 100',[$like,$like,$like])
  : rows('SELECT c.id,c.full_name,c.discipline,c.city,c.state,c.stage,p.employee_number
          FROM candidates c LEFT JOIN employee_profiles p ON p.candidate_id=c.id
          ORDER BY c.id DESC LIMIT 50');

 $pageTitle=t('Employee folders').' · '.$config['app_name'];
 render('employee-folder-choose',compact('people','search'));
 exit;
}

$c=row('SELECT * FROM candidates WHERE id=?',[$cid]);if(!$c) { refuse(404, t('Employee record unavailable.')); }
q('INSERT IGNORE INTO employee_profiles(candidate_id) VALUES (?)',[$cid]);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 if($do==='profile') {
  require_role('recruiter');$type=$_POST['employment_type'] ?? '';$availability=$_POST['availability'] ?? '';$rehire=$_POST['rehire_status'] ?? '';
  if(in_array($type,['hourly','salaried','contractor','external'],true) && in_array($availability,['available','unavailable','on_assignment'],true) && array_key_exists($rehire,rehire_states())) {
   // The rehire decision is made on the person's page, where it asks
   // for a reason. Letting it be changed here as well, silently and
   // without one, is how a block loses the only thing that makes it
   // reviewable.
   q('UPDATE employee_profiles SET employment_type=?,availability=? WHERE candidate_id=?',[$type,$availability,$cid]);
  }
 }
 if($do==='payment') {
  require_role('payroll');$method=$_POST['payment_method'] ?? '';$salary=$_POST['salary_per_period'] ?? '';
  if(!in_array($method,['direct_deposit','check','cash'],true) || ($salary!=='' && (!is_numeric($salary) || (float)$salary<0))) { refuse(422, t('Invalid payment setup.')); }
  q('UPDATE employee_profiles SET payment_method=?,adp_employee_id=?,salary_per_period=? WHERE candidate_id=?',[$method,trim((string)($_POST['adp_employee_id'] ?? ''))?:null,$salary!==''?(float)$salary:null,$cid]);
 }
 if($do==='credential') {
  $expiry=(string)($_POST['expires_on'] ?? '');$type=trim((string)($_POST['credential_type'] ?? ''));$docId=(int)($_POST['document_id'] ?? 0);
  if(valid_date($expiry) && $type && (!$docId || row('SELECT id FROM worker_documents WHERE id=? AND candidate_id=?',[$docId,$cid]))) q('INSERT INTO worker_credentials(candidate_id,credential_type,description,expires_on,document_id) VALUES (?,?,?,?,?)',[$cid,$type,trim((string)($_POST['description'] ?? '')),$expiry,$docId?:null]);
 }
 if($do==='review_credential') {
  require_role('recruiter');$status=$_POST['status'] ?? '';
  if(in_array($status,['verified','rejected'],true)) q('UPDATE worker_credentials SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND candidate_id=?',[$status,uid(),(int)($_POST['credential_id'] ?? 0),$cid]);
 }
 q('INSERT INTO candidate_events(candidate_id,user_id,event_type) VALUES (?,?,?)',[$cid,uid(),$do]);log_activity('updated employee folder','candidate',$cid,$do);redirect('/employee-folder?id='.$cid);
}
$profile=row('SELECT * FROM employee_profiles WHERE candidate_id=?',[$cid]);
$placements=rows('SELECT p.id,p.status,p.start_date,p.end_date,j.title,d.trade,d.shift_label,u.name supervisor FROM placements p JOIN jobs j ON j.id=p.job_id LEFT JOIN assignment_details d ON d.placement_id=p.id LEFT JOIN users u ON u.id=d.supervisor_id WHERE p.candidate_id=? ORDER BY p.id DESC',[$cid]);
$applications=rows('SELECT a.*,v.title FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.candidate_id=? ORDER BY a.id DESC',[$cid]);
$applicationHistory=rows('SELECT e.stage,e.note,e.created_at,v.title,u.name FROM application_events e JOIN applications a ON a.id=e.application_id JOIN vacancies v ON v.id=a.vacancy_id LEFT JOIN users u ON u.id=e.user_id WHERE a.candidate_id=? ORDER BY e.id DESC LIMIT 100',[$cid]);
$events=rows('SELECT e.*,u.name FROM candidate_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.candidate_id=? ORDER BY e.id DESC LIMIT 100',[$cid]);
$credentials=rows('SELECT * FROM worker_credentials WHERE candidate_id=? ORDER BY expires_on',[$cid]);
$docs=($own || can('recruiter'))?rows('SELECT id,document_type,status,created_at FROM worker_documents WHERE candidate_id=? ORDER BY id DESC',[$cid]):[];
$history=rows("SELECT a.created_at,a.action,a.detail,u.name FROM activity a LEFT JOIN users u ON u.id=a.user_id WHERE (a.entity='candidate' AND a.entity_id=?) OR (a.entity IN ('placement','timesheet') AND (a.entity='placement' AND a.entity_id IN (SELECT id FROM placements WHERE candidate_id=?) OR a.entity='timesheet' AND a.entity_id IN (SELECT t.id FROM timesheets t JOIN placements p ON p.id=t.placement_id WHERE p.candidate_id=?))) ORDER BY a.id DESC LIMIT 100",[$cid,$cid,$cid]);
$signatures=rows('SELECT d.title,d.signer_name,d.signed_at,d.status FROM signed_acknowledgements d JOIN placements p ON p.id=d.placement_id WHERE p.candidate_id=? ORDER BY d.id DESC',[$cid]);
render('employee-folder',compact('c','cid','own','profile','placements','applications','events','credentials','docs','history','signatures','applicationHistory'));

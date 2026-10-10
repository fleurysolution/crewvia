<?php
require_once __DIR__.'/../hr.php';
require_once __DIR__.'/../gmail.php';
require_once __DIR__.'/../classification.php';
require_once __DIR__.'/../gross-to-net.php';
require_once __DIR__.'/../self-service.php';
require_once __DIR__.'/../compensation.php';
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
// Changes whose date has come are brought onto the current values first.
compensation_apply_due($cid);
q('INSERT IGNORE INTO employee_profiles(candidate_id) VALUES (?)',[$cid]);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';
 // ── the worker's own: proposed, then validated by staff (R22-R24) ──
 if($own && $do==='self_bank') {
  if(($why=self_bank_submit($cid,$_POST,uid()))!==null) refuse(422,$why);
  flash(t('Thank you. Payroll checks your bank details before they are used; until then your pay goes where it went before.'));
 }
 if($own && $do==='self_detail') {
  if(($why=self_detail_submit($cid,(string)($_POST['field'] ?? ''),(string)($_POST['value'] ?? ''),(string)($_POST['reason'] ?? ''),uid()))!==null) refuse(422,$why);
  flash(t('Sent. Your details change once somebody here has checked it.'));
 }
 if($own && $do==='self_confirm') {
  self_confirm_unchanged($cid,uid());
  flash(t('Thank you. Your details on file are confirmed for this job.'));
 }
 if($do==='profile') {
  require_role('recruiter');$availability=$_POST['availability'] ?? '';
  // The rehire decision is made on the person's page, where it asks
  // for a reason. Letting it be changed here as well, silently and
  // without one, is how a block loses the only thing that makes it
  // reviewable. The kind of employment is the same: it decides how a
  // week is paid, so it has its own form below, with a date and a reason.
  if(in_array($availability,['available','unavailable','on_assignment'],true)) {
   q('UPDATE employee_profiles SET availability=? WHERE candidate_id=?',[$availability,$cid]);
  }
 }
 // ── what they are employed as, from when ───────────────────────────
 if($do==='classification') {
  require_role('recruiter','payroll');
  db()->beginTransaction();
  q('SELECT candidate_id FROM employee_profiles WHERE candidate_id=? FOR UPDATE',[$cid]);
  $refusal=classification_change($cid,(string)($_POST['employment_type'] ?? ''),(string)($_POST['flsa_status'] ?? ''),
                                 (string)($_POST['effective_from'] ?? ''),(string)($_POST['reason'] ?? ''),uid());
  if($refusal!==null) { db()->rollBack();refuse(422,$refusal); }
  db()->commit();
  log_activity('changed employment classification','candidate',$cid,
               $c['full_name'].' - '.$_POST['employment_type'].' / '.$_POST['flsa_status'].' from '.$_POST['effective_from']);
  flash(t('Classification recorded. Weeks already approved keep what they were calculated on.'));
 }
 // ── where the money goes ───────────────────────────────────────────
 // Payroll only. A recruiter can open this folder and has no business
 // with somebody's account number.
 if($do==='bank') {
  require_role('payroll');

  $account = preg_replace('/\s+/', '', (string) ($_POST['account_number'] ?? ''));
  $routing = preg_replace('/\s+/', '', (string) ($_POST['routing_number'] ?? ''));
  $bank    = trim((string) ($_POST['bank_name'] ?? ''));
  $holder  = trim((string) ($_POST['account_holder'] ?? ''));

  if (! preg_match('/^[0-9]{4,20}$/D', $account)) {
   flash(t('An account number is 4 to 20 digits.'), 'err');
   redirect('/employee-folder?id=' . $cid);
  }

  // Nine digits, and the ABA checksum. A transposed digit that still
  // passes the length test is how money reaches the wrong bank.
  if (! preg_match('/^[0-9]{9}$/D', $routing)) {
   flash(t('A routing number is exactly 9 digits.'), 'err');
   redirect('/employee-folder?id=' . $cid);
  }

  $d = array_map('intval', str_split($routing));
  $sum = 3 * ($d[0] + $d[3] + $d[6]) + 7 * ($d[1] + $d[4] + $d[7]) + ($d[2] + $d[5] + $d[8]);

  if ($sum % 10 !== 0) {
   flash(t('That routing number fails its checksum. Check the digits against the cheque.'), 'err');
   redirect('/employee-folder?id=' . $cid);
  }

  if ($bank === '' || mb_strlen($bank) > 90) {
   flash(t('Name the bank.'), 'err');
   redirect('/employee-folder?id=' . $cid);
  }

  q('INSERT INTO worker_bank_details
      (candidate_id, encrypted_details, last_four, bank_label, status,
       submitted_by, reviewed_by, reviewed_at)
     VALUES (?,?,?,?,?,?,?,NOW())
     ON DUPLICATE KEY UPDATE encrypted_details = VALUES(encrypted_details),
                             last_four = VALUES(last_four),
                             bank_label = VALUES(bank_label),
                             status = VALUES(status),
                             reviewed_by = VALUES(reviewed_by),
                             reviewed_at = NOW()',
    [$cid,
     token_encrypt(['account_number' => $account, 'routing_number' => $routing,
                    'bank_name' => $bank, 'account_holder' => $holder]),
     substr($account, -4), $bank, 'verified', uid(), uid()]);

  q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)',
    [$cid, uid(), 'recorded']);

  // The number itself never reaches the activity log.
  log_activity('recorded bank details', 'candidate', $cid,
               $c['full_name'] . ' - ' . $bank . ' ****' . substr($account, -4));

  flash(t('Bank details saved for :name, ending :last.',
          ['name' => $c['full_name'], 'last' => substr($account, -4)]));

  redirect('/employee-folder?id=' . $cid);
 }

 // ── what is taken from pay, and what the employer adds ─────────────
 // Payroll only, like the bank details: these are terms of pay.
 if($do==='pay_item_add') {
  require_role('payroll');
  $item=row("SELECT * FROM pay_items WHERE id=? AND is_active=1 AND method<>'advance_repayment'",[(int)($_POST['pay_item_id'] ?? 0)]);
  $amount=trim((string)($_POST['amount'] ?? ''));$from=(string)($_POST['starts_on'] ?? '');
  if(!$item) refuse(422,t('Choose a pay item from the list.'));
  $max=$item['method']==='percent_of_gross'?100.0:10000.0;
  if(!is_numeric($amount)||(float)$amount<=0||(float)$amount>$max) refuse(422,$item['method']==='percent_of_gross'?t('A percentage is above 0 and at most 100.'):t('An amount a week is above 0 and at most 10,000.'));
  if(!valid_date($from)) refuse(422,t('Give the date it starts.'));
  if(val('SELECT COUNT(*) FROM employee_pay_items WHERE candidate_id=? AND pay_item_id=? AND (ends_on IS NULL OR ends_on>=?)',[$cid,(int)$item['id'],$from])) refuse(422,t('This person already has that item running. End it first, then add the new amount.'));
  q('INSERT INTO employee_pay_items(candidate_id,pay_item_id,amount,starts_on,note,created_by) VALUES (?,?,?,?,?,?)',[$cid,(int)$item['id'],round((float)$amount,2),$from,mb_substr(trim((string)($_POST['note'] ?? '')),0,255)?:null,uid()]);
  log_activity('added a pay item','candidate',$cid,$item['code'].' '.$amount.' from '.$from);
 }
 if($do==='pay_item_end') {
  require_role('payroll');
  $entry=row('SELECT * FROM employee_pay_items WHERE id=? AND candidate_id=? AND ends_on IS NULL',[(int)($_POST['employee_pay_item_id'] ?? 0),$cid]);
  $to=(string)($_POST['ends_on'] ?? '');
  if(!$entry) refuse(404,t('That item is not running for this person.'));
  if(!valid_date($to)||$to<$entry['starts_on']) refuse(422,t('Give an end date on or after the day it started.'));
  q('UPDATE employee_pay_items SET ends_on=? WHERE id=?',[$to,(int)$entry['id']]);
  log_activity('ended a pay item','candidate',$cid,'#'.$entry['id'].' to '.$to);
 }
 if($do==='comp_record') {
  require_role('payroll');
  db()->beginTransaction();
  q('SELECT candidate_id FROM employee_profiles WHERE candidate_id=? FOR UPDATE',[$cid]);
  if(($why=compensation_record($cid,$_POST,uid(),can('admin')))!==null) { db()->rollBack();refuse(422,$why); }
  db()->commit();
  log_activity('recorded a pay change','candidate',$cid,(string)($_POST['kind'] ?? '').' from '.(string)($_POST['effective_from'] ?? '').' - '.mb_substr((string)($_POST['reason'] ?? ''),0,200));
  flash(t('Pay change recorded. It applies to every week ending on or after its date.'));
 }
 if($do==='comp_cancel') {
  require_role('payroll');
  if(($why=compensation_cancel((int)($_POST['change_id'] ?? 0),$cid,uid()))!==null) refuse(422,$why);
  log_activity('cancelled a pay change','candidate',$cid,'#'.(int)$_POST['change_id']);
  flash(t('Cancelled. It will not take effect.'));
 }
 if($do==='payment') {
  require_role('payroll');$method=$_POST['payment_method'] ?? '';
  if(!in_array($method,['direct_deposit','check','cash'],true)) { refuse(422, t('Invalid payment setup.')); }
  // The salary is not typed over here any more: it changes through a dated
  // change with a reason, under "Pay and grade" (P2-M01).
  q('UPDATE employee_profiles SET payment_method=?,adp_employee_id=? WHERE candidate_id=?',[$method,trim((string)($_POST['adp_employee_id'] ?? ''))?:null,$cid]);
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
// Only the summary - the last four digits and the bank - reaches the
// view by default. The full record is read on request, and that read is
// logged.
$bank = can('payroll') ? worker_bank_summary($cid) : null;
$bankFull = null;

if ($bank && can('payroll') && ($_GET['reveal'] ?? '') === 'bank') {
 $bankFull = worker_bank_details($cid, 'revealed on the folder');
}

// The reasons are staff notes about a person; the person sees their own
// current classification on the profile card, not the deliberations.
$classifications=$own?[]:classification_history($cid);
$selfService=self_service_state($cid);
$employment=!$own?employment_history($cid,can('payroll')):[];
$comp=can('payroll')&&!$own?['grade'=>compensation_grade_on($cid,date('Y-m-d')),'grades'=>pay_grades(),'changes'=>rows('SELECT c.*,g.label AS grade_label,j.title FROM compensation_changes c LEFT JOIN pay_grades g ON g.id=c.grade_id LEFT JOIN placements p ON p.id=c.placement_id LEFT JOIN jobs j ON j.id=p.job_id WHERE c.candidate_id=? ORDER BY c.effective_from DESC,c.id DESC LIMIT 30',[$cid]),'current'=>rows("SELECT p.id,p.pay_rate,j.title FROM placements p JOIN jobs j ON j.id=p.job_id WHERE p.candidate_id=? AND p.status NOT IN ('completed','cancelled') ORDER BY p.id DESC",[$cid])]:null;
$ownBank=$own?worker_bank_summary($cid):null;
$payItems=can('payroll')?employee_pay_items($cid):[];
$payItemChoices=can('payroll')?array_values(array_filter(pay_items(),fn($i)=>$i['method']!=='advance_repayment')):[];

render('employee-folder',compact('c','cid','own','profile','placements','applications','events','credentials','docs','history','signatures','applicationHistory','bank','bankFull','classifications','payItems','payItemChoices','selfService','ownBank','employment','comp'));

<?php
require_role('worker','supervisor');require_once __DIR__.'/../contracts.php';
$worker=row('SELECT c.* FROM worker_accounts w JOIN candidates c ON c.id=w.candidate_id WHERE w.user_id=?',[uid()]);
// An account can exist before recruiting attaches it to a crew record -
// an invitation sent early, a transfer, a hand-made account. This used to
// answer 403 with an empty body, so somebody who had just chosen their
// password signed in successfully and landed on a white page with no
// menu and nothing to click. Say what is missing instead.
if(!$worker) { render('unlinked', []); exit; }
q('INSERT IGNORE INTO onboarding_tasks(placement_id,requirement_id) SELECT p.id,r.id FROM placements p JOIN onboarding_requirements r ON r.job_id=p.job_id WHERE p.candidate_id=?',[$worker['id']]);
if($_SERVER['REQUEST_METHOD']==='POST') {
 if(($_POST['do'] ?? '')==='accept_offer') {
  $aid=(int)($_POST['application_id'] ?? 0);$name=trim((string)($_POST['signer_name'] ?? ''));
  db()->beginTransaction();q('SELECT id FROM candidates WHERE id=? FOR UPDATE',[$worker['id']]);
  $a=row("SELECT a.*,v.job_id FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? AND a.candidate_id=? AND a.stage='offered' FOR UPDATE",[$aid,$worker['id']]);
  $terms=$a?(string)val("SELECT note FROM application_events WHERE application_id=? AND stage='offered' ORDER BY id DESC LIMIT 1",[$aid]):'';
  if($a && (contract_latest($aid)||val('SELECT required_before_deployment FROM contract_policies WHERE job_id=?',[$a['job_id']]))){db()->rollBack();flash(t('Review and sign the current contract in Contracts & signatures.'),'err');redirect('/contracts');}
  if($a && contract_document_gaps((int)$worker['id'],(int)$a['job_id'])){db()->rollBack();flash(t('Required documents must be approved before offer acceptance.'),'err');redirect('/portal');}
  if($a && $terms && $name && strlen($name)<=190 && isset($_POST['consent'])) {
   q("UPDATE applications SET stage='accepted' WHERE id=?",[$aid]);
   q('INSERT INTO offer_acceptances(application_id,user_id,offer_text,offer_hash,signer_name) VALUES (?,?,?,?,?)',[$aid,uid(),$terms,hash('sha256',$terms),$name]);
   q('INSERT INTO application_events(application_id,user_id,stage,note) VALUES (?,?,?,?)',[$aid,uid(),'accepted','Signed offer hash '.hash('sha256',$terms)]);
   $assignment=row("SELECT id FROM placements WHERE candidate_id=? AND job_id=? AND status NOT IN ('completed','cancelled')",[$worker['id'],$a['job_id']]);
   if(!$assignment) { require_once __DIR__.'/../placement-rates.php';$rates=placement_rates((int)$a['job_id'],(int)($a['vacancy_id'] ?? 0) ?: null);q("INSERT INTO placements(candidate_id,job_id,vacancy_id,order_line_id,status,created_by,pay_rate,bill_rate,per_diem_rate,guarantee_hours) VALUES (?,?,?,?,'offered',?,?,?,?,?)",[$worker['id'],$a['job_id'],$rates['vacancy_id'],$rates['order_line_id'],uid(),$rates['pay_rate'],$rates['bill_rate'],$rates['per_diem_rate'],$rates['guarantee_hours']]);$assignment=['id'=>(int)db()->lastInsertId()];require_once __DIR__.'/../procurement.php';procurement_request_lodging_for((int)$assignment['id']); }
   q('INSERT IGNORE INTO onboarding_tasks(placement_id,requirement_id) SELECT ?,id FROM onboarding_requirements WHERE job_id=?',[$assignment['id'],$a['job_id']]);
   q("INSERT INTO notifications(user_id,message,target) SELECT id,?,? FROM users WHERE role IN ('admin','recruiter') AND is_active=1",['Offer accepted; onboarding started','/onboarding']);
  }else flash(t('Offer terms, your signature and consent are required.'),'err');
  db()->commit();redirect('/portal');
 }
 $response=trim((string)($_POST['response'] ?? ''));
 if(strlen($response)>2000) { refuse(422, t('Response too long.')); }
 q("UPDATE onboarding_tasks t JOIN placements p ON p.id=t.placement_id SET t.response=?,t.status='submitted' WHERE t.id=? AND p.candidate_id=? AND t.status IN ('pending','rejected')",[$response,(int)($_POST['task_id'] ?? 0),$worker['id']]);
 log_activity('submitted onboarding','task',(int)($_POST['task_id'] ?? 0)); redirect('/portal');
}
$tasks=rows('SELECT t.*,r.title,r.instructions,j.title project FROM onboarding_tasks t JOIN onboarding_requirements r ON r.id=t.requirement_id JOIN placements p ON p.id=t.placement_id JOIN jobs j ON j.id=p.job_id WHERE p.candidate_id=?',[$worker['id']]);
$assignments=rows('SELECT p.*,j.title FROM placements p JOIN jobs j ON j.id=p.job_id WHERE p.candidate_id=? ORDER BY p.id DESC',[$worker['id']]);
$lodging=rows("SELECT h.name,l.room_number,l.check_in,l.check_out FROM lodging l JOIN hotels h ON h.id=l.hotel_id JOIN placements p ON p.id=l.placement_id WHERE p.candidate_id=? AND l.status NOT IN ('cancelled','checked_out')",[$worker['id']]);
$travel=rows("SELECT t.* FROM travel t JOIN placements p ON p.id=t.placement_id WHERE p.candidate_id=? AND t.status<>'cancelled'",[$worker['id']]);
$routes=rows('SELECT s.runs_on,s.depart_time,s.driver,r.pickup_stop FROM route_passengers r JOIN shuttle_runs s ON s.id=r.run_id JOIN placements p ON p.id=r.placement_id WHERE p.candidate_id=? AND s.runs_on>=CURDATE() ORDER BY s.runs_on,s.depart_time',[$worker['id']]);
$details=rows('SELECT j.title,d.trade,d.shift_label,u.name supervisor FROM assignment_details d JOIN placements p ON p.id=d.placement_id JOIN jobs j ON j.id=p.job_id LEFT JOIN users u ON u.id=d.supervisor_id WHERE p.candidate_id=?',[$worker['id']]);
$applications=rows("SELECT a.*,EXISTS(SELECT 1 FROM employment_contracts c WHERE c.application_id=a.id) has_contract,COALESCE((SELECT required_before_deployment FROM contract_policies WHERE job_id=v.job_id),0) contract_required,v.title,j.title project,(SELECT note FROM application_events ev WHERE ev.application_id=a.id AND ev.stage='offered' ORDER BY ev.id DESC LIMIT 1) offer_text FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN jobs j ON j.id=v.job_id WHERE a.candidate_id=?",[$worker['id']]);
render('portal',compact('worker','tasks','assignments','lodging','travel','routes','details','applications'));

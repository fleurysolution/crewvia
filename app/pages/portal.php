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
  $aid=(int)($_POST['application_id'] ?? 0);
  db()->beginTransaction();q('SELECT id FROM candidates WHERE id=? FOR UPDATE',[$worker['id']]);
  $a=row("SELECT a.*,v.job_id FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? AND a.candidate_id=? AND a.stage='offered' FOR UPDATE",[$aid,$worker['id']]);
  if($a && (contract_latest($aid)||val('SELECT required_before_deployment FROM contract_policies WHERE job_id=?',[$a['job_id']]))){db()->rollBack();flash(t('Review and sign the current contract in Contracts & signatures.'),'err');redirect('/contracts');}
  if($a && contract_document_gaps((int)$worker['id'],(int)$a['job_id'])){db()->rollBack();flash(t('Required documents must be approved before offer acceptance.'),'err');redirect('/portal');}
  // P5-M53: the candidate's signature goes to the office, which countersigns it; only then are they hired.
  require_once __DIR__.'/../offers.php';
  $why=offer_sign($aid,(int)$worker['id'],uid(),(string)($_POST['signer_name'] ?? ''),isset($_POST['consent']));
  db()->commit();
  if($why!==null){refuse(422,$why,'/portal#applications');}
  flash(t('Thank you. Your signed offer goes to the office, which countersigns it; you will be told here and by email.'));redirect('/portal#applications');
 }
 // P5-M53: the candidate may decline the offer, saying why if they wish.
 if(($_POST['do'] ?? '')==='decline_offer') {
  require_once __DIR__.'/../offers.php';
  db()->beginTransaction();
  $why=isset($_POST['confirm'])?offer_decline((int)($_POST['application_id'] ?? 0),(int)$worker['id'],uid(),(string)($_POST['reason'] ?? '')):t('Tick the box to confirm you decline this offer.');
  db()->commit();
  if($why!==null){refuse(422,$why,'/portal#applications');}
  flash(t('You declined the offer. The office is told.'));redirect('/portal#applications');
 }
 // P5-M32: the room number, then the clock-in, on the worker's own assignment.
 if(in_array($_POST['do'] ?? '',['room_number','clock_in'],true)) {
  require_once __DIR__.'/../clock-in.php';
  $pid=(int)val("SELECT id FROM placements WHERE id=? AND candidate_id=? AND status IN ('confirmed','travelling','on_site')",[(int)($_POST['placement_id']??0),$worker['id']]);
  if(!$pid){refuse(404,t('There is no assignment to clock in to.'),'/portal');}
  $why=$_POST['do']==='room_number'?ci_room_save($pid,(string)($_POST['room_number']??'')):ci_clock_in($pid,uid());
  if($why!==null){refuse(422,$why,'/portal#clock');}
  flash($_POST['do']==='room_number'?t('Room number saved. You can clock in.'):t('Clocked in. Your supervisor validates it.'));redirect('/portal#clock');
 }
 // P5-M31: the worker says they registered with ADP.
 if(($_POST['do'] ?? '')==='adp_registered') {
  require_once __DIR__.'/../adp-registration.php';
  if(adp_reg_settings()['code']===''){refuse(422,t('Payroll has not set up ADP registration yet.'),'/portal');}
  adp_reg_mark((int)$worker['id']);flash(t('Thank you. Payroll can see you registered with ADP.'));redirect('/portal#adp');
 }
 // P5-M30: what the coordinator needs to book the trip.
 if(($_POST['do'] ?? '')==='travel_profile') {
  require_once __DIR__.'/../travel-details.php';
  if($why=tp_save((int)$worker['id'],$_POST,uid())) { refuse(422,$why,'/portal#travel-details'); }
  flash(t('Thank you. The travel coordinator books your trip from these details.'));redirect('/portal#travel-details');
 }
 $response=trim((string)($_POST['response'] ?? ''));
 if(strlen($response)>2000) { refuse(422, t('Response too long.')); }
 q("UPDATE onboarding_tasks t JOIN placements p ON p.id=t.placement_id SET t.response=?,t.status='submitted' WHERE t.id=? AND p.candidate_id=? AND t.status IN ('pending','rejected')",[$response,(int)($_POST['task_id'] ?? 0),$worker['id']]);
 log_activity('submitted onboarding','task',(int)($_POST['task_id'] ?? 0)); redirect('/portal');
}
$tasks=rows('SELECT t.*,r.title,r.instructions,j.title project FROM onboarding_tasks t JOIN onboarding_requirements r ON r.id=t.requirement_id JOIN placements p ON p.id=t.placement_id JOIN jobs j ON j.id=p.job_id WHERE p.candidate_id=?',[$worker['id']]);
$assignments=rows('SELECT p.*,j.title FROM placements p JOIN jobs j ON j.id=p.job_id WHERE p.candidate_id=? ORDER BY p.id DESC',[$worker['id']]);
$lodging=rows("SELECT h.name,l.room_number,l.check_in,l.check_out FROM lodging l JOIN hotels h ON h.id=l.hotel_id JOIN placements p ON p.id=l.placement_id WHERE p.candidate_id=? AND l.status NOT IN ('cancelled','checked_out')",[$worker['id']]);
$travel=rows("SELECT t.*,p.job_id FROM travel t JOIN placements p ON p.id=t.placement_id WHERE p.candidate_id=? AND t.status<>'cancelled' ORDER BY t.direction,t.depart_time",[$worker['id']]);
// P5-M30: the itinerary in words, the advice for travellers, and the details form while a trip is ahead.
require_once __DIR__.'/../travel-details.php';
foreach($travel as &$leg){$leg['lines']=tp_leg_lines($leg);}
unset($leg);
$travelAdvice=$travel?tp_advisories((int)$travel[0]['job_id']):[];
$travelProfile=tp_profile((int)$worker['id']);
$tripAhead=(bool)array_filter($assignments,static fn($a)=>in_array($a['status'],['offered','confirmed','travelling'],true));
$routes=rows('SELECT s.runs_on,s.depart_time,s.driver,r.pickup_stop FROM route_passengers r JOIN shuttle_runs s ON s.id=r.run_id JOIN placements p ON p.id=r.placement_id WHERE p.candidate_id=? AND s.runs_on>=CURDATE() ORDER BY s.runs_on,s.depart_time',[$worker['id']]);
$details=rows('SELECT j.title,d.trade,d.shift_label,u.name supervisor FROM assignment_details d JOIN placements p ON p.id=d.placement_id JOIN jobs j ON j.id=p.job_id LEFT JOIN users u ON u.id=d.supervisor_id WHERE p.candidate_id=?',[$worker['id']]);
$applications=rows("SELECT a.*,EXISTS(SELECT 1 FROM employment_contracts c WHERE c.application_id=a.id) has_contract,COALESCE((SELECT required_before_deployment FROM contract_policies WHERE job_id=v.job_id),0) contract_required,v.title,j.title project,(SELECT note FROM application_events ev WHERE ev.application_id=a.id AND ev.stage='offered' ORDER BY ev.id DESC LIMIT 1) offer_text FROM applications a JOIN vacancies v ON v.id=a.vacancy_id JOIN jobs j ON j.id=v.job_id WHERE a.candidate_id=?",[$worker['id']]);
// Walkthrough findings 15-17: the stage in words, and an offer's terms. The
// worker was asked to accept "the terms displayed above" with only the
// recruiter's note above it; the terms are what was agreed for the role.
require_once __DIR__.'/../placement-rates.php';require_once __DIR__.'/../hiring.php';
foreach($applications as &$portalApp){
 $portalApp['stage_word']=hw_stages()[$portalApp['stage']]??$portalApp['stage'];
 if($portalApp['stage']!=='offered'){continue;}
 $portalApp['terms']=offer_terms((int)$portalApp['vacancy_id']);
 // P5-M53: the whole letter, and the candidate's signature if they signed it already.
 require_once __DIR__.'/../offers.php';
 $portalApp['letter']=offer_letter((int)$portalApp['id']);
 $portalApp['signed']=row('SELECT signer_name,signed_at FROM offer_acceptances WHERE application_id=?',[(int)$portalApp['id']]);
 // P5-M54: signing withdraws the other applications in progress; the letter says so before they sign.
 $portalApp['others']=candidate_is_employee((int)$worker['id'])?0:(int)val("SELECT COUNT(*) FROM applications WHERE candidate_id=? AND id<>? AND stage IN ('new','screening','interview','offered')",[$worker['id'],(int)$portalApp['id']]);
}
unset($portalApp);
// T10: how many open jobs fit this file and are not yet answered.
require_once __DIR__.'/../job-matches.php';
$jmWorkerRow=jm_worker();
$jobsForYou=$jmWorkerRow?count(array_filter(jm_jobs_for($jmWorkerRow),static fn($j)=>!$j['application']||$j['application']['stage']==='withdrawn')):0;
// T20: hotel charges the worker pays, from reconciled folios, with what the hotel printed.
require_once __DIR__.'/../folios.php';
$hotelCharges=fo_worker_charges((int)$worker['id']);
// Wave 2: one home. What self-service shows (what waits on them, a card per area) is part of it.
require_once __DIR__.'/../hr-requests.php';
$home=self_service_home((int)$worker['id']);
$extraWaiting=[];
$pendingTasks=count(array_filter($tasks,static fn($t)=>in_array($t['status'],['pending','rejected'],true)));
if($pendingTasks){$extraWaiting[]=['#onboarding',tn($pendingTasks,':n onboarding task to complete',':n onboarding tasks to complete'),'onboarding'];}
$offers=count(array_filter($applications,static fn($a)=>$a['stage']==='offered'&&!$a['has_contract']&&!$a['contract_required']&&empty($a['signed'])));
if($offers){$extraWaiting[]=['#applications',tn($offers,':n job offer to answer',':n job offers to answer'),'offer'];}
// P5-M32: today's shift: the room number first, then the clock-in, then the supervisor's answer.
require_once __DIR__.'/../clock-in.php';
$clock=[];
foreach($assignments as $a){
 if(!in_array($a['status'],['confirmed','travelling','on_site'],true)||($a['start_date']&&$a['start_date']>date('Y-m-d'))){continue;}
 [$day,$start,$label]=ci_shift_now((int)$a['id']);
 $clock[]=['placement_id'=>(int)$a['id'],'project'=>$a['title'],'shift'=>$label,'start'=>$start,'room'=>ci_room_due((int)$a['id']),'today'=>ci_today((int)$a['id'])];
 if(($r=ci_room_due((int)$a['id']))){$extraWaiting[]=['#clock',t('Enter your room number at :hotel',['hotel'=>$r['hotel']]),'room'];}
}
// P5-M31: registering with ADP, while there is work ahead or under way.
require_once __DIR__.'/../adp-registration.php';
$adpReg=adp_reg_settings();$adpStatus=adp_reg_status((int)$worker['id']);
$adpShow=$adpReg['code']!==''&&(bool)array_filter($assignments,static fn($a)=>in_array($a['status'],['confirmed','travelling','on_site'],true));
if($adpShow&&!$adpStatus['adp_registered_at']){$extraWaiting[]=['#adp',t('Register with ADP to be paid'),'adp'];}
if($tripAhead&&!$travelProfile){$extraWaiting[]=['#travel-details',t('Your travel details for the booking'),'travel'];}
$isCandidate=worker_is_candidate(user());
render('portal',compact('isCandidate','worker','tasks','assignments','lodging','travel','routes','details','applications','jobsForYou','hotelCharges','home','extraWaiting','travelAdvice','travelProfile','tripAhead','adpReg','adpStatus','adpShow','clock'));

<?php
require_once __DIR__.'/../qualifications.php';require_role('recruiter','hotels','payroll');$job=current_job();$jobId=(int)($job['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=$_POST['do'] ?? '';if($do==='clearance') require_role('recruiter');else require_role('hotels');
 if($do==='vehicle') {
  $plate=trim((string)($_POST['plate'] ?? ''));$name=trim((string)($_POST['name'] ?? ''));$seats=(int)($_POST['seats'] ?? 0);
  if($plate && $name && $seats>0 && !row('SELECT id FROM vehicles WHERE plate=?',[$plate])) q('INSERT INTO vehicles(name,plate,seats) VALUES (?,?,?)',[$name,$plate,$seats]);
 }
 if($do==='checkout') {
  $pid=(int)($_POST['placement_id'] ?? 0);$vid=(int)($_POST['vehicle_id'] ?? 0);$driver=trim((string)($_POST['driver_name'] ?? ''));
  db()->beginTransaction();$v=row("SELECT id FROM vehicles WHERE id=? AND status='available' FOR UPDATE",[$vid]);
  $placement=row('SELECT id FROM placements WHERE id=? AND job_id=?',[$pid,$jobId]);
  if($v && $placement && $driver && !row('SELECT id FROM vehicle_assignments WHERE vehicle_id=? AND checked_in_at IS NULL',[$vid])) {
   $worker=row('SELECT w.user_id,c.full_name FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN worker_accounts w ON w.candidate_id=c.id WHERE p.id=?',[$pid]);
   $terms=trim((string)($_POST['authorization_text'] ?? ''));
   if(!$worker || !$terms) { db()->rollBack();flash(t('Driver needs a portal account and approved vehicle agreement text.'),'err');redirect('/manning'); }
   q('INSERT INTO vehicle_assignments(vehicle_id,placement_id,driver_name,checked_out_at) VALUES (?,?,?,NULL)',[$vid,$pid,$worker['full_name']]);$assignmentId=(int)db()->lastInsertId();
   q('INSERT INTO signed_acknowledgements(placement_id,user_id,title,document_text,document_hash) VALUES (?,?,?,?,?)',[$pid,$worker['user_id'],'Vehicle use agreement',$terms,hash('sha256',$terms)]);$agreementId=(int)db()->lastInsertId();
   q('INSERT INTO vehicle_agreements(vehicle_assignment_id,acknowledgement_id) VALUES (?,?)',[$assignmentId,$agreementId]);
  }
  else flash(t('Vehicle unavailable or assignment invalid.'),'err');db()->commit();
 }
 if($do==='dispatch') {
  $id=(int)($_POST['assignment_id'] ?? 0);
  $a=row('SELECT a.*,p.candidate_id,s.status agreement_status,s.signed_at FROM vehicle_assignments a JOIN placements p ON p.id=a.placement_id JOIN vehicle_agreements v ON v.vehicle_assignment_id=a.id JOIN signed_acknowledgements s ON s.id=v.acknowledgement_id WHERE a.id=? AND p.job_id=?',[$id,$jobId]);
  $license=$a?row("SELECT id FROM worker_credentials WHERE candidate_id=? AND credential_type='driver_license' AND status='verified' AND document_id IS NOT NULL AND expires_on>=CURDATE()",[$a['candidate_id']]):null;
  if(!$a || !$license || qualification_gaps((int)$a['candidate_id'],$jobId) || $a['mvr_status']!=='approved' || $a['agreement_status']!=='signed') flash(t('A verified valid licence, approved MVR and signed vehicle agreement are required.'),'err');
  else q('UPDATE vehicle_assignments SET checked_out_at=NOW(),agreement_signed_at=? WHERE id=? AND checked_out_at IS NULL AND checked_in_at IS NULL',[$a['signed_at'],$id]);
 }
 if($do==='checkin') q('UPDATE vehicle_assignments a JOIN placements p ON p.id=a.placement_id SET a.checked_in_at=NOW() WHERE a.id=? AND p.job_id=? AND a.checked_in_at IS NULL',[(int)($_POST['assignment_id'] ?? 0),$jobId]);
 if($do==='clearance') {
  require_role('recruiter');$status=$_POST['mvr_status'] ?? '';
  if(in_array($status,['pending','approved','rejected'],true)) q('UPDATE vehicle_assignments a JOIN placements p ON p.id=a.placement_id SET a.mvr_status=? WHERE a.id=? AND p.job_id=?',[$status,(int)($_POST['assignment_id'] ?? 0),$jobId]);
 }
 log_activity('manning update','project',$jobId,$do);redirect('/manning');
}
$include=isset($_GET['released']);
$crew=rows("SELECT p.*,c.full_name,c.phone,j.title job_title,j.site_city,j.site_state,d.trade,d.shift_label,u.name supervisor,
 (SELECT h.name FROM lodging l JOIN hotels h ON h.id=l.hotel_id WHERE l.placement_id=p.id AND l.status IN ('held','booked','checked_in') ORDER BY l.id DESC LIMIT 1) hotel,
 (SELECT l.room_number FROM lodging l WHERE l.placement_id=p.id AND l.status IN ('held','booked','checked_in') ORDER BY l.id DESC LIMIT 1) room_number,
 (SELECT l.check_in FROM lodging l WHERE l.placement_id=p.id AND l.status IN ('held','booked','checked_in') ORDER BY l.id DESC LIMIT 1) hotel_in,
 (SELECT l.check_out FROM lodging l WHERE l.placement_id=p.id AND l.status IN ('held','booked','checked_in') ORDER BY l.id DESC LIMIT 1) hotel_out
 FROM placements p JOIN candidates c ON c.id=p.candidate_id JOIN jobs j ON j.id=p.job_id LEFT JOIN assignment_details d ON d.placement_id=p.id LEFT JOIN users u ON u.id=d.supervisor_id WHERE p.job_id=?".($include?'':" AND p.status NOT IN ('completed','cancelled')").' ORDER BY c.full_name',[$jobId]);
$vans=rows('SELECT a.*,v.name,v.plate,c.full_name FROM vehicle_assignments a JOIN vehicles v ON v.id=a.vehicle_id JOIN placements p ON p.id=a.placement_id JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? ORDER BY a.id DESC',[$jobId]);
$vehicles=rows("SELECT * FROM vehicles WHERE status='available' ORDER BY name");
$hotelCount=(int)val("SELECT COUNT(DISTINCT l.hotel_id) FROM lodging l JOIN placements p ON p.id=l.placement_id WHERE p.job_id=? AND l.status IN ('held','booked','checked_in')",[$jobId]);
$roomCount=(int)val("SELECT COUNT(*) FROM lodging l JOIN placements p ON p.id=l.placement_id WHERE p.job_id=? AND l.status IN ('held','booked','checked_in')",[$jobId]);
$flights=(int)val("SELECT COUNT(*) FROM travel t JOIN placements p ON p.id=t.placement_id WHERE p.job_id=? AND t.mode='flight' AND t.status IN ('booked','completed')",[$jobId]);
$onSite=(int)val("SELECT COUNT(*) FROM placements WHERE job_id=? AND status='on_site'",[$jobId]);
if(isset($_GET['export'])) {
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="manning.csv"');$out=fopen('php://output','w');
 fputcsv($out,['Employee','Job','Trade','Status','Location','State','Supervisor','Shift','Hotel','Room','In','Out','Hired','Released']);
 foreach($crew as $c) {
  $line=[];foreach(['full_name','job_title','trade','status','site_city','site_state','supervisor','shift_label','hotel','room_number','hotel_in','hotel_out','start_date','end_date'] as $key) { $value=(string)($c[$key] ?? '');$line[]=preg_match('/^[=+@\-\t\r]/',$value)?"'".$value:$value; }fputcsv($out,$line);
 }fclose($out);exit;
}
render('manning',compact('crew','vans','vehicles','hotelCount','roomCount','flights','onSite','include'));

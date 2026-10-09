<?php
require_once __DIR__.'/../offboarding.php';require_role('recruiter','hotels'); $job=current_job(); $jobId=(int)($job['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $do=(string)($_POST['do'] ?? ''); $pid=(int)($_POST['placement_id'] ?? 0);
 $placement=$pid?row('SELECT * FROM placements WHERE id=? AND job_id=?',[$pid,$jobId]):null;
 if(in_array($do,['issue','passenger','assignment','offboard'],true) && !$placement) { refuse(422, t('Select a placement in this project.')); }
 if($do==='wave') {
  require_role('recruiter');$name=trim((string)($_POST['name'] ?? ''));$date=(string)($_POST['arrival_date'] ?? '');$sid=(int)($_POST['supervisor_id'] ?? 0);$target=max(0,(int)($_POST['headcount_target'] ?? 0));
  if($jobId && $name && valid_date($date) && (!$sid || row("SELECT id FROM users WHERE id=? AND is_active=1 AND role<>'worker'",[$sid]))) q('INSERT INTO deployment_waves(job_id,name,arrival_date,supervisor_id,headcount_target) VALUES (?,?,?,?,?)',[$jobId,$name,$date,$sid?:null,$target]);
 }
 if($do==='wave_member') {
  require_role('recruiter');$wid=(int)($_POST['wave_id'] ?? 0);db()->beginTransaction();
  $wave=row('SELECT * FROM deployment_waves WHERE id=? AND job_id=? FOR UPDATE',[$wid,$jobId]);
  $p=row('SELECT id FROM placements WHERE id=? AND job_id=?',[$pid,$jobId]);
  $count=(int)val('SELECT COUNT(*) FROM wave_members WHERE wave_id=?',[$wid]);
  if($wave && $p && (!(int)$wave['headcount_target'] || $count<(int)$wave['headcount_target'])) q('INSERT IGNORE INTO wave_members(wave_id,placement_id) VALUES (?,?)',[$wid,$pid]);
  else flash(t('Wave is unavailable or full.'),'err');db()->commit();
 }
 if($do==='department_assignment') {
  require_role('recruiter');$did=(int)($_POST['department_id'] ?? 0);
  if($placement && row('SELECT id FROM project_departments WHERE id=? AND job_id=?',[$did,$jobId])) q('INSERT INTO placement_departments(placement_id,department_id) VALUES (?,?) ON DUPLICATE KEY UPDATE department_id=VALUES(department_id)',[$pid,$did]);
 }
 if($do==='asset') {
  require_role('admin'); $name=trim((string)($_POST['name'] ?? '')); $tag=trim((string)($_POST['asset_tag'] ?? ''));
  if($name && $tag && !row('SELECT id FROM equipment WHERE asset_tag=?',[$tag])) {
   $owner=$_POST['owner_type'] ?? 'agency';$client=(int)($_POST['owner_client_id'] ?? 0);
   if(!in_array($owner,['agency','client'],true) || ($owner==='client' && !row('SELECT id FROM clients WHERE id=?',[$client]))) {refuse(422, t('Select a valid equipment owner.'));}
   db()->beginTransaction();q('INSERT INTO equipment(name,asset_tag) VALUES (?,?)',[$name,$tag]);$equipmentId=(int)db()->lastInsertId();
   q('INSERT INTO equipment_ownership(equipment_id,owner_type,client_id,serial_number) VALUES (?,?,?,?)',[$equipmentId,$owner,$owner==='client'?$client:null,mb_substr(trim((string)($_POST['serial_number'] ?? '')),0,190)]);db()->commit();
  }
 }
 if($do==='issue') {
  $eid=(int)($_POST['equipment_id'] ?? 0); db()->beginTransaction();
  $asset=row('SELECT e.id,o.owner_type,o.client_id FROM equipment e LEFT JOIN equipment_ownership o ON o.equipment_id=e.id WHERE e.id=? FOR UPDATE',[$eid]);
  if($asset && $asset['owner_type']==='client' && (int)$asset['client_id']!==(int)val('SELECT client_id FROM jobs WHERE id=?',[$jobId])) $asset=null;
  if($asset && !row('SELECT id FROM equipment_issues WHERE equipment_id=? AND returned_at IS NULL',[$eid])) q('INSERT INTO equipment_issues(equipment_id,placement_id) VALUES (?,?)',[$eid,$pid]);
  else flash(t('Equipment is unavailable.'),'err'); db()->commit();
 }
 if($do==='return') q('UPDATE equipment_issues i JOIN placements p ON p.id=i.placement_id SET i.returned_at=NOW(),i.return_note=? WHERE i.id=? AND p.job_id=? AND i.returned_at IS NULL',[trim((string)($_POST['return_note'] ?? '')),(int)($_POST['issue_id'] ?? 0),$jobId]);
 if($do==='passenger') {
  $rid=(int)($_POST['run_id'] ?? 0); $stop=trim((string)($_POST['pickup_stop'] ?? '')); db()->beginTransaction();
  $run=row('SELECT * FROM shuttle_runs WHERE id=? AND job_id=? FOR UPDATE',[$rid,$jobId]);
  $count=(int)val('SELECT COUNT(*) FROM route_passengers WHERE run_id=?',[$rid]);
  if(!$run || !$stop || !$run['seats'] || $count >= (int)$run['seats']) flash(t('Route unavailable, pickup stop missing, or vehicle full.'),'err');
  else if(!row('SELECT id FROM route_passengers WHERE run_id=? AND placement_id=?',[$rid,$pid])) q('INSERT INTO route_passengers(run_id,placement_id,pickup_stop) VALUES (?,?,?)',[$rid,$pid,$stop]);
  db()->commit();
 }
 if($do==='attendance') {
  $status=(string)($_POST['attendance'] ?? '');
  if(in_array($status,['scheduled','boarded','absent'],true)) q('UPDATE route_passengers r JOIN shuttle_runs s ON s.id=r.run_id SET r.attendance=? WHERE r.id=? AND s.job_id=?',[$status,(int)($_POST['passenger_id'] ?? 0),$jobId]);
 }
 if($do==='assignment') {
  require_role('recruiter'); $trade=trim((string)($_POST['trade'] ?? '')); $sid=(int)($_POST['supervisor_id'] ?? 0);
  if($sid && !row("SELECT id FROM users WHERE id=? AND is_active=1 AND role<>'worker'",[$sid])) { refuse(422, t('Invalid supervisor.')); }
  if($trade) q('INSERT INTO assignment_details(placement_id,trade,supervisor_id,shift_label) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE trade=VALUES(trade),supervisor_id=VALUES(supervisor_id),shift_label=VALUES(shift_label)',[$pid,$trade,$sid?:null,trim((string)($_POST['shift_label'] ?? ''))]);
 }
 if($do==='offboard') {
  require_role('recruiter');
  if(offboarding_pending($pid,$jobId)){flash(t('Complete approved offboarding tasks before closing the assignment.'),'err');redirect('/operations');}
  if(val('SELECT COUNT(*) FROM equipment_issues WHERE placement_id=? AND returned_at IS NULL',[$pid]) || val('SELECT COUNT(*) FROM vehicle_assignments WHERE placement_id=? AND checked_in_at IS NULL',[$pid])) flash(t('Return outstanding equipment before completing the assignment.'),'err');
  else {
   db()->beginTransaction(); q("UPDATE placements SET status='completed',end_date=CURDATE() WHERE id=?",[$pid]);
   q("UPDATE lodging SET status='checked_out',check_out=CURDATE() WHERE placement_id=? AND status IN ('held','booked','checked_in')",[$pid]);
   q('DELETE r FROM route_passengers r JOIN shuttle_runs s ON s.id=r.run_id WHERE r.placement_id=? AND s.runs_on>CURDATE()',[$pid]);
   db()->commit();
  }
 }
 log_activity('operations update','project',$jobId,$do); redirect('/operations');
}
$crew=rows("SELECT p.id,c.full_name,d.trade,d.shift_label,u.name supervisor FROM placements p JOIN candidates c ON c.id=p.candidate_id LEFT JOIN assignment_details d ON d.placement_id=p.id LEFT JOIN users u ON u.id=d.supervisor_id WHERE p.job_id=? AND p.status NOT IN ('cancelled','completed')",[$jobId]);
$assets=rows('SELECT e.*,o.owner_type,o.serial_number,c.name owner_client FROM equipment e LEFT JOIN equipment_ownership o ON o.equipment_id=e.id LEFT JOIN clients c ON c.id=o.client_id ORDER BY e.name');
$clients=rows('SELECT id,name FROM clients ORDER BY name');
$departments=rows('SELECT * FROM project_departments WHERE job_id=? ORDER BY name',[$jobId]);
$issues=rows('SELECT i.*,e.name,e.asset_tag,c.full_name FROM equipment_issues i JOIN equipment e ON e.id=i.equipment_id JOIN placements p ON p.id=i.placement_id JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? AND i.returned_at IS NULL',[$jobId]);
$runs=rows('SELECT * FROM shuttle_runs WHERE job_id=? ORDER BY runs_on,depart_time',[$jobId]);
$passengers=rows('SELECT r.*,s.runs_on,s.driver,s.pickup_point,c.full_name FROM route_passengers r JOIN shuttle_runs s ON s.id=r.run_id JOIN placements p ON p.id=r.placement_id JOIN candidates c ON c.id=p.candidate_id WHERE s.job_id=? ORDER BY s.runs_on,s.depart_time,c.full_name',[$jobId]);
$supervisors=rows("SELECT id,name FROM users WHERE is_active=1 AND role<>'worker'");
$waves=rows('SELECT w.*,u.name supervisor,(SELECT COUNT(*) FROM wave_members m WHERE m.wave_id=w.id) assigned FROM deployment_waves w LEFT JOIN users u ON u.id=w.supervisor_id WHERE w.job_id=? ORDER BY w.arrival_date',[$jobId]);
render('operations',compact('crew','assets','issues','runs','passengers','supervisors','waves','clients','departments'));

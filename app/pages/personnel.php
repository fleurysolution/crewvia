<?php
require_once __DIR__.'/../offboarding.php';require_role('recruiter');$jobId=(int)(current_job()['id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST') {
 $pid=(int)($_POST['placement_id'] ?? 0);$kind=$_POST['kind'] ?? '';$trade=trim((string)($_POST['new_trade'] ?? ''));$effective=(string)($_POST['effective_on'] ?? '');$reason=trim((string)($_POST['reason'] ?? ''));
 if(!valid_date($effective) || $effective>date('Y-m-d') || !$reason || !in_array($kind,['promotion','transfer'],true)) { refuse(422, t('Provide a reason and an effective date no later than today. Scheduled changes are not enabled.')); }
 db()->beginTransaction();$p=row('SELECT p.*,d.trade FROM placements p LEFT JOIN assignment_details d ON d.placement_id=p.id WHERE p.id=? AND p.job_id=? FOR UPDATE',[$pid,$jobId]);
 if(!$p || in_array($p['status'],['completed','cancelled'],true)) { db()->rollBack();refuse(422, t('Active assignment required.')); }
 $target=null;
 if($kind==='promotion') {
  if(!$trade) { db()->rollBack();refuse(422, t('New trade or title required.')); }
  q('INSERT INTO assignment_details(placement_id,trade) VALUES (?,?) ON DUPLICATE KEY UPDATE trade=VALUES(trade)',[$pid,$trade]);
 }else {
  $target=(int)($_POST['target_job_id'] ?? 0);
  if($target===$jobId || !row("SELECT id FROM jobs WHERE id=? AND status<>'closed'",[$target])) { db()->rollBack();refuse(422, t('Select another open project.')); }
  if(offboarding_pending($pid,$jobId)){db()->rollBack();flash(t('Complete approved offboarding tasks before closing the assignment.'),'err');redirect('/personnel');}
  if(val('SELECT COUNT(*) FROM equipment_issues WHERE placement_id=? AND returned_at IS NULL',[$pid]) || val('SELECT COUNT(*) FROM vehicle_assignments WHERE placement_id=? AND checked_in_at IS NULL',[$pid])) { db()->rollBack();flash(t('Return equipment and vehicles before transferring.'),'err');redirect('/personnel'); }
  if(row("SELECT id FROM placements WHERE candidate_id=? AND job_id=? AND status NOT IN ('completed','cancelled')",[$p['candidate_id'],$target])) { db()->rollBack();refuse(409, t('Worker already has an active assignment on the target project.')); }
  q("UPDATE placements SET status='completed',end_date=? WHERE id=?",[$effective,$pid]);
  q("UPDATE lodging SET status='checked_out',check_out=? WHERE placement_id=? AND status IN ('held','booked','checked_in')",[$effective,$pid]);
  q('DELETE r FROM route_passengers r JOIN shuttle_runs s ON s.id=r.run_id WHERE r.placement_id=? AND s.runs_on>=?',[$pid,$effective]);
  q("INSERT INTO placements(candidate_id,job_id,status,start_date,created_by) VALUES (?,?,'offered',?,?)",[$p['candidate_id'],$target,$effective,uid()]);$newPid=(int)db()->lastInsertId();
  q('INSERT INTO assignment_details(placement_id,trade) VALUES (?,?)',[$newPid,$trade ?: ($p['trade'] ?: 'Unassigned')]);
 }
 q('INSERT INTO personnel_changes(placement_id,kind,previous_trade,new_trade,target_job_id,effective_on,reason,approved_by) VALUES (?,?,?,?,?,?,?,?)',[$pid,$kind,$p['trade'],$trade?:null,$target,$effective,$reason,uid()]);
 db()->commit();log_activity('approved personnel change','placement',$pid,$kind);redirect('/personnel');
}
$crew=rows("SELECT p.id,c.full_name FROM placements p JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? AND p.status NOT IN ('completed','cancelled')",[$jobId]);
$jobs=rows("SELECT id,title FROM jobs WHERE id<>? AND status<>'closed'",[$jobId]);
$changes=rows('SELECT x.*,c.full_name FROM personnel_changes x JOIN placements p ON p.id=x.placement_id JOIN candidates c ON c.id=p.candidate_id WHERE p.job_id=? ORDER BY x.id DESC',[$jobId]);
render('personnel',compact('crew','jobs','changes'));

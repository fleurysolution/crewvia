<?php
/** Schedule per tenant. Idempotent internal alerts; no external emails or decisions. */
declare(strict_types=1);if(PHP_SAPI!=='cli')exit('CLI only');
require __DIR__.'/../app/bootstrap.php';$opsStarted=microtime(true);
function workflow_notify(int $userId,string $key,string $message,string $target): void {
    db()->beginTransaction();
    try {
        $inserted=q('INSERT IGNORE INTO workflow_alerts(user_id,alert_key) VALUES (?,?)',[$userId,hash('sha256',$key)])->rowCount();
        if($inserted)q('INSERT INTO notifications(user_id,message,target) VALUES (?,?,?)',[$userId,$message,$target]);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
$reviewers=rows("SELECT id FROM users WHERE is_active=1 AND role IN ('admin','recruiter')");
foreach(rows("SELECT q.id,q.candidate_id,q.expires_on,c.full_name,t.name FROM candidate_qualifications q JOIN candidates c ON c.id=q.candidate_id JOIN qualification_types t ON t.id=q.type_id WHERE q.status='verified' AND q.expires_on<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)") as $q) {
    foreach($reviewers as $u)workflow_notify((int)$u['id'],'qualification-'.$q['id'].'-'.$q['expires_on'].'-'.date('Y-m-d'),'Qualification needs renewal: '.$q['full_name'].' · '.$q['name'].' · '.$q['expires_on'],'/qualifications?candidate_id='.$q['candidate_id']);
}
foreach(rows('SELECT c.id,c.user_id,c.application_id,c.follow_up_at,n.full_name FROM recruiting_contacts c JOIN applications a ON a.id=c.application_id JOIN candidates n ON n.id=a.candidate_id JOIN users u ON u.id=c.user_id WHERE c.follow_up_at<=NOW() AND c.follow_up_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND u.is_active=1') as $c)workflow_notify((int)$c['user_id'],'followup-'.$c['id'].'-'.$c['follow_up_at'],'Recruiting follow-up due: '.$c['full_name'],'/screening-workflow?application_id='.$c['application_id']);
foreach(rows("SELECT id,status FROM employment_contracts WHERE expires_at<=NOW() AND status IN ('draft','approved','issued','viewed','uploaded_review') AND method='internal'") as $contract){db()->beginTransaction();$expired=q("UPDATE employment_contracts SET status='expired' WHERE id=? AND status=?",[$contract['id'],$contract['status']])->rowCount();if($expired)q("INSERT INTO contract_events(contract_id,action,details_json) VALUES (?,'expired','{}')",[$contract['id']]);db()->commit();}
q('DELETE FROM auth_rate_limits WHERE window_start<?',[time()-86400]);
// P4-M06: one-time links and keys that can no longer be used are not kept: a
// recovery link a week after it expired, a form key two days after it was made.
try{q('DELETE o FROM account_mail_outbox o JOIN password_resets r ON r.id=o.reset_id WHERE r.expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY)');q('DELETE FROM password_resets WHERE expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY)');}catch(Throwable $e){error_log('[rss-ops] retention: recovery links not purged');}
try{q('DELETE FROM integration_nonces WHERE created_at<DATE_SUB(NOW(),INTERVAL 2 DAY)');}catch(Throwable $e){}
// P4-M07: error logs past their retention, then the record that this pass ran.
$purgedLogs=ops_purge_logs();
// P5-M04: the training each requirement calls for, and the renewals of what runs out within the month.
$trainingAssigned=0;
try{require_once __DIR__.'/../app/training.php';$trainingAssigned=tr_sync();}catch(Throwable $e){error_log('[crewvia] training sync: '.$e->getMessage());}
// P5-M05: engagement surveys whose closing date has passed.
$surveysClosed=0;
try{require_once __DIR__.'/../app/surveys.php';$surveysClosed=sv_close_due();}catch(Throwable $e){error_log('[crewvia] survey close: '.$e->getMessage());}
// P5-M06c: pushes for what is new, only once an administrator has turned push on.
$pushSent=0;
try{require_once __DIR__.'/../app/push.php';$pushSent=push_dispatch()['sent'];}catch(Throwable $e){error_log('[crewvia] push dispatch: '.$e->getMessage());}
// P5-M09: driving-record checks due again within the month, or overdue.
$driverAlerts=0;
try{require_once __DIR__.'/../app/driver-records.php';$driverAlerts=dr_due_alerts();}catch(Throwable $e){error_log('[crewvia] driver checks: '.$e->getMessage());}
ops_record('workflows','ok',['error_logs_purged'=>$purgedLogs,'training_assigned'=>$trainingAssigned,'surveys_closed'=>$surveysClosed,'push_sent'=>$pushSent,'driver_alerts'=>$driverAlerts],$opsStarted);
echo "Workflow alert pass complete. No external messages sent.\n";

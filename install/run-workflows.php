<?php
/** Schedule per tenant. Idempotent internal alerts; no external emails or decisions. */
declare(strict_types=1);if(PHP_SAPI!=='cli')exit('CLI only');
require __DIR__.'/../app/bootstrap.php';
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
echo "Workflow alert pass complete. No external messages sent.\n";

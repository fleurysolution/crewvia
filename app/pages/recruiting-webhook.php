<?php
require_once __DIR__.'/../recruiting-webhook.php';header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo '{"error":"POST required"}';exit;}
$channel=(string)($_GET['channel']??'');$entry=$config['recruiting_webhooks'][$channel]??null;
$body=(string)file_get_contents('php://input',false,null,0,131073);
if(!is_array($entry)||empty($entry['enabled'])||!recruiting_webhook_valid($body,(string)($_SERVER['HTTP_X_WORKFORCE_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_WORKFORCE_SIGNATURE']??''),(string)($entry['secret']??''))){http_response_code(401);echo '{"error":"Invalid signature"}';exit;}
try{$event=recruiting_webhook_payload($body);}catch(Throwable $e){http_response_code(422);echo '{"error":"Invalid application"}';exit;}
$digest=hash('sha256',$body);$eventKey=hash('sha256',$channel.'|'.$event['event_id']);
db()->beginTransaction();
try {
    q('INSERT IGNORE INTO recruiting_inbound_events(event_key,channel,payload_hash) VALUES (?,?,?)',[$eventKey,$channel,$digest]);
    $record=row('SELECT * FROM recruiting_inbound_events WHERE event_key=? FOR UPDATE',[$eventKey]);
    if(!hash_equals($record['payload_hash'],$digest)){db()->rollBack();http_response_code(409);echo '{"error":"Event ID conflicts with original payload"}';exit;}
    if($record['application_id']){db()->commit();echo json_encode(['application_id'=>(int)$record['application_id'],'duplicate'=>true]);exit;}
    $vacancy=row("SELECT v.id FROM vacancies v JOIN jobs j ON j.id=v.job_id LEFT JOIN requisition_publication p ON p.vacancy_id=v.id WHERE v.id=? AND v.is_open=1 AND j.status<>'closed' AND (p.expires_on IS NULL OR p.expires_on>=CURDATE()) FOR UPDATE",[$event['vacancy_id']]);
    if(!$vacancy){db()->rollBack();http_response_code(410);echo '{"error":"Vacancy closed or unavailable"}';exit;}
    // Preserve the submitted identity for recruiter review instead of merging unverified email matches.
    q('INSERT INTO candidates(full_name,email,phone,source) VALUES (?,?,?,?)',[trim($event['name']),trim($event['email']),trim($event['phone']),'Partner: '.mb_substr($channel,0,60)]);$cid=(int)db()->lastInsertId();
    q('INSERT INTO applications(candidate_id,vacancy_id) VALUES (?,?)',[$cid,$event['vacancy_id']]);$application=(int)db()->lastInsertId();
    q('UPDATE recruiting_inbound_events SET application_id=? WHERE event_key=?',[$application,$eventKey]);
    q('INSERT INTO candidate_events(candidate_id,event_type,detail) VALUES (?,?,?)',[$cid,'partner application received','Consent asserted by configured partner; event '.$event['event_id']]);
    db()->commit();http_response_code(201);echo json_encode(['application_id'=>$application,'duplicate'=>false]);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('[recruiting-webhook] Processing failed');http_response_code(500);echo '{"error":"Application could not be processed"}';}

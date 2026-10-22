<?php
require_once __DIR__.'/../recruiting-webhook.php';require_once __DIR__.'/../connectors.php';header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo '{"error":"POST required"}';exit;}
$channel=(string)($_GET['channel']??'');$entry=$config['recruiting_webhooks'][$channel]??null;
$body=(string)file_get_contents('php://input',false,null,0,131073);
// P4-M05: a secret can be rotated: 'secrets' lists the others still accepted.
$valid=false;if(is_array($entry)&&!empty($entry['enabled']))foreach(array_filter([(string)($entry['secret']??''),...array_map('strval',(array)($entry['secrets']??[]))]) as $secret){if(recruiting_webhook_valid($body,(string)($_SERVER['HTTP_X_WORKFORCE_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_WORKFORCE_SIGNATURE']??''),$secret)){$valid=true;break;}}
$connector='ats:'.(preg_match('/^[a-z0-9_-]{1,60}$/',$channel)?$channel:'unknown');$mode=$valid?connector_mode($connector):'disabled';
if(!$valid||$mode==='disabled'){integration_refusal('ats','application',$connector,$valid?'The channel is switched off':'Unknown channel, channel off, or the signature did not match');http_response_code(401);echo '{"error":"Invalid signature"}';exit;}
try{$event=recruiting_webhook_payload($body);}catch(Throwable $e){integration_refusal('ats','application',$connector,'Not a valid application: '.$e->getMessage());http_response_code(422);echo '{"error":"Invalid application"}';exit;}
// Sandbox: the partner's event is checked end to end and answered, and nothing is created.
if($mode==='sandbox'){
 $open=(bool)val("SELECT COUNT(*) FROM vacancies v JOIN jobs j ON j.id=v.job_id WHERE v.id=? AND v.is_open=1 AND v.posting_status = 'published' AND j.status<>'closed'",[$event['vacancy_id']]);
 integration_event('ats','application','ok',$connector,'Sandbox event '.mb_substr($event['event_id'],0,80).' verified; nothing created'.($open?'':' (its vacancy is closed: live, it would be refused)'));
 connector_sandbox_passed($connector,'Signed sandbox event '.mb_substr($event['event_id'],0,80).' verified');
 http_response_code(202);echo json_encode(['sandbox'=>true,'valid'=>true,'vacancy_open'=>$open,'created'=>false]);exit;
}
$digest=hash('sha256',$body);$eventKey=hash('sha256',$channel.'|'.$event['event_id']);
db()->beginTransaction();
try {
    q('INSERT IGNORE INTO recruiting_inbound_events(event_key,channel,payload_hash) VALUES (?,?,?)',[$eventKey,$channel,$digest]);
    $record=row('SELECT * FROM recruiting_inbound_events WHERE event_key=? FOR UPDATE',[$eventKey]);
    if(!hash_equals($record['payload_hash'],$digest)){db()->rollBack();http_response_code(409);echo '{"error":"Event ID conflicts with original payload"}';exit;}
    if($record['application_id']){db()->commit();integration_event('ats','application','duplicate',$connector,'Event '.mb_substr($event['event_id'],0,80).' already received');echo json_encode(['application_id'=>(int)$record['application_id'],'duplicate'=>true]);exit;}
    $vacancy=row("SELECT v.id FROM vacancies v JOIN jobs j ON j.id=v.job_id LEFT JOIN requisition_publication p ON p.vacancy_id=v.id WHERE v.id=? AND v.is_open=1 AND v.posting_status = 'published' AND j.status<>'closed' AND (p.expires_on IS NULL OR p.expires_on>=CURDATE()) FOR UPDATE",[$event['vacancy_id']]);
    if(!$vacancy){db()->rollBack();http_response_code(410);echo '{"error":"Vacancy closed or unavailable"}';exit;}
    // Preserve the submitted identity for recruiter review instead of merging unverified email matches.
    q('INSERT INTO candidates(full_name,email,phone,source) VALUES (?,?,?,?)',[trim($event['name']),trim($event['email']),trim($event['phone']),'Partner: '.mb_substr($channel,0,60)]);$cid=(int)db()->lastInsertId();
    q('INSERT INTO applications(candidate_id,vacancy_id) VALUES (?,?)',[$cid,$event['vacancy_id']]);$application=(int)db()->lastInsertId();
    q('UPDATE recruiting_inbound_events SET application_id=? WHERE event_key=?',[$application,$eventKey]);
    q('INSERT INTO candidate_events(candidate_id,event_type,detail) VALUES (?,?,?)',[$cid,'partner application received','Consent asserted by configured partner; event '.$event['event_id']]);
    db()->commit();integration_event('ats','application','ok',$connector,'Event '.mb_substr($event['event_id'],0,80).' created application '.$application.' for review');http_response_code(201);echo json_encode(['application_id'=>$application,'duplicate'=>false]);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('[recruiting-webhook] Processing failed');integration_event('ats','application','failed',$connector,'Processing failed; the partner may send it again');http_response_code(500);echo '{"error":"Application could not be processed"}';}

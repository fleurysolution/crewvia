<?php
require_once __DIR__.'/integrations.php';require_once __DIR__.'/gmail.php';
function docusign_settings(): array {
 global $config;$s=$config['docusign']??[];
 if(empty($s['enabled'])||(empty($s['access_token'])&&empty($s['jwt']['integration_key']))||!preg_match('/^[a-zA-Z0-9-]{1,100}$/',$s['account_id']??''))throw new RuntimeException('DocuSign is disabled or not configured.');
 $base=rtrim($s['base_url']??'','/');
 if(!preg_match('#^https://(?:demo|[a-z0-9-]+)\.docusign\.net/restapi$#',$base))throw new RuntimeException('Invalid DocuSign API endpoint.');$s['base_url']=$base;return $s;
}
/**
 * One DocuSign call (P4-M04). A read is retried; a write is sent once (an
 * envelope is found again by its transaction id, never sent twice). A 401
 * with a JWT-grant token means it expired early: one fresh token, once.
 */
function docusign_request(string $method,string $path,?array $payload=null,bool $binary=false): mixed {
 $s=docusign_settings();$url=$s['base_url'].'/v2.1/accounts/'.rawurlencode($s['account_id']).$path;
 $body=$payload!==null?json_encode($payload,JSON_THROW_ON_ERROR):null;
 for($try=0;$try<2;$try++){
  $r=integration_request('docusign',$method,$url,['Authorization: Bearer '.docusign_access_token($s,$try>0),'Content-Type: application/json'],$body,$method==='GET');
  if($r['status']!==401||empty($s['jwt']['integration_key']))break;
  integration_event('docusign','token','retry',null,'The API refused the cached token; asking for a fresh one');
 }
 if($r['status']<200||$r['status']>=300){integration_event('docusign',$method.' '.preg_replace('#/[a-zA-Z0-9-]{20,}#','/{id}',strtok($path,'?')),'failed',null,'HTTP '.$r['status']);throw new RuntimeException('DocuSign request failed; reconcile before retrying.');}
 return $binary?$r['body']:json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
}

/**
 * The token for the API: the configured access token, or one obtained by
 * the JWT grant (an RSA key Crewvia holds, signing for an impersonated
 * DocuSign user), cached encrypted until 5 minutes before it expires.
 */
function docusign_access_token(array $s,bool $fresh=false): string {
 $jwt=$s['jwt']??[];
 if(empty($jwt['integration_key']))return (string)$s['access_token'];
 $host=(string)($jwt['oauth_host']??'account-d.docusign.com');
 if(!in_array($host,['account-d.docusign.com','account.docusign.com'],true))throw new RuntimeException('Invalid DocuSign OAuth host.');
 $subject=hash('sha256',$jwt['integration_key'].'|'.($jwt['user_id']??''));
 if(!$fresh){$cached=row("SELECT * FROM integration_tokens WHERE provider='docusign' AND subject=? AND expires_at>DATE_ADD(NOW(),INTERVAL 5 MINUTE)",[$subject]);
  if($cached){try{return (string)token_decrypt($cached['encrypted_token'])['access_token'];}catch(Throwable $e){}}}
 $r=integration_request('docusign','POST','https://'.$host.'/oauth/token',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
   http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>docusign_jwt_assertion($jwt,time())]),true);
 $data=$r['status']===200?json_decode($r['body'],true):null;
 if(!is_array($data)||!is_string($data['access_token']??null)||$data['access_token']===''){integration_event('docusign','token','refused',null,'The JWT grant was refused (HTTP '.$r['status'].'): check the integration key, the user, the consent and the key pair');throw new RuntimeException('DocuSign token request failed; check the JWT grant configuration.');}
 $expires=time()+max(60,(int)($data['expires_in']??3600));
 q("INSERT INTO integration_tokens(provider,subject,encrypted_token,expires_at) VALUES ('docusign',?,?,?) ON DUPLICATE KEY UPDATE encrypted_token=VALUES(encrypted_token),expires_at=VALUES(expires_at),updated_at=NOW()",
   [$subject,token_encrypt(['access_token'=>$data['access_token']]),date('Y-m-d H:i:s',$expires)]);
 integration_event('docusign','token','ok',null,'Token issued by the JWT grant, valid until '.date('Y-m-d H:i',$expires));
 return $data['access_token'];
}

function docusign_b64url(string $raw): string { return rtrim(strtr(base64_encode($raw),'+/','-_'),'='); }

/** The signed assertion of the JWT grant: RS256, one hour, signature and impersonation. */
function docusign_jwt_assertion(array $jwt,int $now): string {
 $pem=!empty($jwt['private_key_path'])?(string)@file_get_contents((string)$jwt['private_key_path']):(string)($jwt['private_key']??'');
 $key=openssl_pkey_get_private($pem);
 if(!$key||!preg_match('/^[a-zA-Z0-9-]{8,100}$/',(string)$jwt['integration_key'])||!preg_match('/^[a-zA-Z0-9-]{8,100}$/',(string)($jwt['user_id']??'')))throw new RuntimeException('Invalid DocuSign JWT configuration.');
 $head=docusign_b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
 $claims=docusign_b64url(json_encode(['iss'=>$jwt['integration_key'],'sub'=>$jwt['user_id'],'aud'=>$jwt['oauth_host']??'account-d.docusign.com','iat'=>$now,'exp'=>$now+3600,'scope'=>'signature impersonation']));
 if(!openssl_sign($head.'.'.$claims,$sig,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('DocuSign JWT signing failed.');
 return $head.'.'.$claims.'.'.docusign_b64url($sig);
}
function docusign_envelope_payload(array $contract,array $recipient,string $pdf): array {
 return ['emailSubject'=>mb_substr($contract['title'],0,100),'status'=>'sent','notification'=>['useAccountDefaults'=>'false','expirations'=>['expireEnabled'=>'true','expireAfter'=>(string)max(1,(int)ceil((strtotime($contract['expires_at']??'+14 days')-time())/86400)),'expireWarn'=>'1']],'transactionId'=>$contract['provider_transaction_id'],
 'documents'=>[['documentBase64'=>base64_encode($pdf),'name'=>$contract['title'],'fileExtension'=>'pdf','documentId'=>'1']],
 'recipients'=>['signers'=>[['email'=>$recipient['email'],'name'=>$recipient['name'],'recipientId'=>'1','clientUserId'=>(string)$recipient['id'],'tabs'=>['signHereTabs'=>[['documentId'=>'1','pageNumber'=>(string)$contract['signature_page'],'xPosition'=>(string)$contract['signature_x'],'yPosition'=>(string)$contract['signature_y']]]]]]]];
}
function docusign_send(array $contract,array $recipient): string {
 $file=row("SELECT * FROM contract_files WHERE contract_id=? AND kind='original'",[$contract['id']]);if(!$file)throw new RuntimeException('Attach the contract PDF before DocuSign issuance.');
 $result=docusign_request('POST','/envelopes',docusign_envelope_payload($contract,$recipient,contract_read_pdf($file)));
 if(!preg_match('/^[a-zA-Z0-9-]{1,100}$/',$result['envelopeId']??''))throw new RuntimeException('Invalid envelope response.');return $result['envelopeId'];
}
function docusign_signing_url(array $contract,array $user): string {
 global $config;$return=rtrim($config['app_url']??'','/').'/contracts?id='.$contract['id'];if(!str_starts_with($return,'https://'))throw new RuntimeException('HTTPS is required for DocuSign.');
 $view=docusign_request('POST','/envelopes/'.rawurlencode($contract['envelope_id']).'/views/recipient',['returnUrl'=>$return,'authenticationMethod'=>'none','email'=>$user['email'],'userName'=>$user['name'],'recipientId'=>'1','clientUserId'=>(string)$user['id']]);
 $url=$view['url']??'';if(!preg_match('#^https://[a-zA-Z0-9.-]+\.docusign\.(?:net|com)/#',$url))throw new RuntimeException('Invalid signing URL.');return $url;
}

function docusign_reconcile(array $c): void {
 $id=(int)$c['id'];
   if(!$c['envelope_id']){
    $found=docusign_request('GET','/envelopes?transaction_ids='.rawurlencode($c['provider_transaction_id']));$items=$found['envelopes']??[];
    if(count($items)!==1||!preg_match('/^[a-zA-Z0-9-]{1,100}$/',$items[0]['envelopeId']??''))throw new RuntimeException('Provider dispatch requires manual reconciliation; do not resend.');
    $c['envelope_id']=$items[0]['envelopeId'];q("UPDATE employment_contracts SET envelope_id=?,status='issued',issued_at=COALESCE(issued_at,NOW()) WHERE id=?",[$c['envelope_id'],$id]);contract_issue_application($c);
   }
   $state=docusign_request('GET','/envelopes/'.rawurlencode($c['envelope_id']));$status=$state['status']??'';
   if($status==='completed'){
    $recipients=docusign_request('GET','/envelopes/'.rawurlencode($c['envelope_id']).'/recipients');$signers=$recipients['signers']??[];
    if(count($signers)!==1||($signers[0]['clientUserId']??'')!==(string)$c['worker_user_id']||strcasecmp($signers[0]['email']??'',$c['worker_email'])!==0||($signers[0]['status']??'')!=='completed')throw new RuntimeException('Provider signer does not match the candidate.');
    foreach(['signed'=>'combined','certificate'=>'certificate'] as $kind=>$document){if(!row('SELECT id FROM contract_files WHERE contract_id=? AND kind=?',[$id,$kind]))contract_store_pdf($id,$kind,docusign_request('GET','/envelopes/'.rawurlencode($c['envelope_id']).'/documents/'.$document,null,true));}
    db()->beginTransaction();$locked=row('SELECT * FROM employment_contracts WHERE id=? FOR UPDATE',[$id]);
    if(!in_array($locked['status'],['signed','voided'],true)){
     $a=row('SELECT a.*,v.job_id FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.id=? FOR UPDATE',[$c['application_id']]);
     if(!hash_equals($locked['content_hash'],$c['content_hash']))throw new RuntimeException('Contract integrity check failed.');
     contract_accept($locked,$a,(int)$c['worker_user_id'],$signers[0]['name']);contract_event($id,'provider_completion_verified',['completedDateTime'=>$state['completedDateTime']??null]);
    }db()->commit();
   }elseif(in_array($status,['declined','voided'],true)){q('UPDATE employment_contracts SET status=? WHERE id=? AND status<>?',[$status,$id,'signed']);contract_event($id,'provider_status',['status'=>$status]);}
   else contract_event($id,'provider_status',['status'=>$status]);

}

function docusign_valid_hmac(string $raw,array $headers,array $secrets): bool {
 if(strlen($raw)>131072)return false;
 foreach($secrets as $secret){if(!is_string($secret)||strlen($secret)<20)continue;$expected=base64_encode(hash_hmac('sha256',$raw,$secret,true));foreach($headers as $header)if(is_string($header)&&hash_equals($expected,$header))return true;}return false;
}


/**
 * A Connect notification (P4-M04): authenticated by HMAC over the raw
 * bytes against each configured secret (so a secret can be rotated),
 * matched to this account and to a contract sent here, then queued once:
 * the same payload twice is a duplicate. Returns the HTTP status to send.
 */
function docusign_webhook_handle(string $raw,array $headers,array $s): int {
 if(empty($s['enabled'])){integration_refusal('docusign','connect',null,'DocuSign is disabled');return 503;}
 if(!docusign_valid_hmac($raw,$headers,$s['connect_hmac_secrets']??[])){integration_refusal('docusign','connect',null,'The HMAC signature did not match');return 401;}
 try{$event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){integration_refusal('docusign','connect',null,'The body is not JSON');return 400;}
 $data=$event['data']??[];$envelope=$data['envelopeId']??'';
 if(($data['accountId']??'')!==($s['account_id']??'')||!is_string($envelope)||!preg_match('/^[a-zA-Z0-9-]{1,100}$/',$envelope)||!row('SELECT id FROM employment_contracts WHERE envelope_id=? AND method=?',[$envelope,'docusign'])){
  integration_refusal('docusign','connect',is_string($envelope)?mb_substr($envelope,0,100):null,'Not an envelope of this account sent from Crewvia');return 422;}
 $new=q('INSERT IGNORE INTO docusign_connect_events(payload_hash,envelope_id) VALUES (?,?)',[hash('sha256',$raw),$envelope])->rowCount()===1;
 integration_event('docusign','connect',$new?'ok':'duplicate',$envelope,$new?'Queued for reconciliation':'The same notification was already queued');
 return 200;
}

/**
 * Work the Connect queue (P4-M04). Each event is reconciled by asking
 * DocuSign itself, never by trusting the notification. A failure waits
 * 2, 4, 8, 16 minutes before the next attempt; after the fifth it is
 * marked failed with its reason, for an administrator to requeue.
 */
function docusign_process_connect_events(int $limit=10): array {
 require_once __DIR__.'/contracts.php';
 $done=['processed'=>0,'retry'=>0,'failed'=>0];
 $lock='workforce-docusign-connect';if(!(int)val('SELECT GET_LOCK(?,0)',[$lock]))return $done+['busy'=>true];
 try{
  foreach(rows("SELECT * FROM docusign_connect_events WHERE status='pending' AND attempts<5 AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY created_at LIMIT ".max(1,min(100,$limit))) as $event){
   q('UPDATE docusign_connect_events SET attempts=attempts+1 WHERE payload_hash=?',[$event['payload_hash']]);$attempt=(int)$event['attempts']+1;
   $c=row('SELECT c.*,a.candidate_id,v.job_id,u.id worker_user_id,u.name worker_name,u.email worker_email FROM employment_contracts c JOIN applications a ON a.id=c.application_id JOIN vacancies v ON v.id=a.vacancy_id JOIN worker_accounts w ON w.candidate_id=a.candidate_id JOIN users u ON u.id=w.user_id WHERE c.envelope_id=?',[$event['envelope_id']]);
   try{
    if(!$c)throw new RuntimeException('Unknown contract');
    if(!in_array($c['status'],['signed','voided','declined'],true))docusign_reconcile($c);
    q("UPDATE docusign_connect_events SET status='processed',processed_at=NOW(),last_error=NULL WHERE payload_hash=?",[$event['payload_hash']]);
    integration_event('docusign','reconcile','ok',$event['envelope_id'],'Attempt '.$attempt);$done['processed']++;
   }catch(Throwable $e){
    if(db()->inTransaction())db()->rollBack();
    $why=mb_substr($e->getMessage(),0,190);
    if($attempt>=5){q("UPDATE docusign_connect_events SET status='failed',last_error=? WHERE payload_hash=?",[$why,$event['payload_hash']]);integration_event('docusign','reconcile','failed',$event['envelope_id'],'Attempt '.$attempt.' of 5, given up: '.$why);$done['failed']++;}
    else{q('UPDATE docusign_connect_events SET next_attempt_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),last_error=? WHERE payload_hash=?',[2**$attempt,$why,$event['payload_hash']]);integration_event('docusign','reconcile','retry',$event['envelope_id'],'Attempt '.$attempt.' of 5 failed, next in '.(2**$attempt).' minutes: '.$why);$done['retry']++;}
   }
  }
 }finally{q('SELECT RELEASE_LOCK(?)',[$lock]);}
 return $done;
}

/** Put a failed Connect event back in the queue, from its first attempt. */
function docusign_connect_requeue(string $hash): bool {
 $ok=q("UPDATE docusign_connect_events SET status='pending',attempts=0,next_attempt_at=NULL WHERE payload_hash=? AND status='failed'",[$hash])->rowCount()===1;
 if($ok)integration_event('docusign','reconcile','retry',(string)val('SELECT envelope_id FROM docusign_connect_events WHERE payload_hash=?',[$hash]),'Requeued by an administrator');
 return $ok;
}

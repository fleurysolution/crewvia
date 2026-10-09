<?php
function docusign_settings(): array {
 global $config;$s=$config['docusign']??[];
 if(empty($s['enabled'])||empty($s['access_token'])||!preg_match('/^[a-zA-Z0-9-]{1,100}$/',$s['account_id']??''))throw new RuntimeException('DocuSign is disabled or not configured.');
 $base=rtrim($s['base_url']??'','/');
 if(!preg_match('#^https://(?:demo|[a-z0-9-]+)\.docusign\.net/restapi$#',$base))throw new RuntimeException('Invalid DocuSign API endpoint.');$s['base_url']=$base;return $s;
}
function docusign_request(string $method,string $path,?array $payload=null,bool $binary=false): mixed {
 $s=docusign_settings();$url=$s['base_url'].'/v2.1/accounts/'.rawurlencode($s['account_id']).$path;$ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$s['access_token'],'Content-Type: application/json']]);
 if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));$body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($body===false||$status<200||$status>=300)throw new RuntimeException('DocuSign request failed; reconcile before retrying.');
 return $binary?$body:json_decode($body,true,512,JSON_THROW_ON_ERROR);
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

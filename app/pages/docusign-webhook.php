<?php
require_once __DIR__.'/../docusign.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$s=$config['docusign']??[];if(empty($s['enabled'])){http_response_code(503);exit;}
$raw=file_get_contents('php://input',false,null,0,131073);$headers=[];for($i=1;$i<=3;$i++)$headers[]=$_SERVER['HTTP_X_DOCUSIGN_SIGNATURE_'.$i]??'';
if(!docusign_valid_hmac($raw,$headers,$s['connect_hmac_secrets']??[])){http_response_code(401);exit;}
try{$event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$data=$event['data']??[];$envelope=$data['envelopeId']??'';
 if(($data['accountId']??'')!==($s['account_id']??'')||!is_string($envelope)||!preg_match('/^[a-zA-Z0-9-]{1,100}$/',$envelope)||!row('SELECT id FROM employment_contracts WHERE envelope_id=? AND method=?',[$envelope,'docusign'])){http_response_code(422);exit;}
 q('INSERT IGNORE INTO docusign_connect_events(payload_hash,envelope_id) VALUES (?,?)',[hash('sha256',$raw),$envelope]);http_response_code(200);echo 'Queued';
}catch(JsonException $e){http_response_code(400);}

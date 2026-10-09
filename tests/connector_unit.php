<?php
$root=is_dir(__DIR__.'/test-app')?__DIR__.'/test-app':dirname(__DIR__);require $root.'/app/recruiting-webhook.php';
$checks=[];function connector_assert($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;echo 'PASS '.$name.PHP_EOL;}
$body=json_encode(['schema'=>'workforce.application.v1','event_id'=>'partner-42','name'=>'Candidate','email'=>'candidate@test.invalid','vacancy_id'=>1,'consent'=>true]);$secret=str_repeat('x',32);$stamp='1000';$signature=hash_hmac('sha256',$stamp.'.'.$body,$secret);
connector_assert('Signed application verified',recruiting_webhook_valid($body,$stamp,$signature,$secret,1000));
connector_assert('Changed application rejected',!recruiting_webhook_valid($body.' ',$stamp,$signature,$secret,1000));
connector_assert('Expired signature rejected',!recruiting_webhook_valid($body,$stamp,$signature,$secret,1400));
connector_assert('Missing secret rejected',!recruiting_webhook_valid($body,$stamp,$signature,'',1000));
connector_assert('Valid application parsed',recruiting_webhook_payload($body)['vacancy_id']===1);
try{recruiting_webhook_payload(str_replace('"consent":true','"consent":false',$body));$blocked=false;}catch(Throwable $e){$blocked=true;}connector_assert('Consent required',$blocked);
file_put_contents(__DIR__.'/connector-unit-results.json',json_encode($checks,JSON_PRETTY_PRINT));

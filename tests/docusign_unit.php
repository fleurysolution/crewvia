<?php
require_once (is_dir(__DIR__.'/test-app')?__DIR__.'/test-app/app/docusign.php':__DIR__.'/../app/docusign.php');$results=[];
function check_contract(string $label,bool $ok):void{global $results;if(!$ok)throw new RuntimeException($label);$results[]=$label;echo "PASS $label\n";}
$config=['docusign'=>['enabled'=>false]];try{docusign_settings();$off=false;}catch(RuntimeException $e){$off=true;}check_contract('DocuSign disabled by default',$off);
$config=['docusign'=>['enabled'=>true,'access_token'=>'synthetic','account_id'=>'test','base_url'=>'http://evil.test']];try{docusign_settings();$safe=false;}catch(RuntimeException $e){$safe=true;}check_contract('Provider endpoint rejects insecure URL',$safe);
$config['docusign']['base_url']='https://evil.test/restapi';try{docusign_settings();$safe=false;}catch(RuntimeException $e){$safe=true;}check_contract('Provider endpoint restricts domain',$safe);
$config['docusign']['base_url']='https://demo.docusign.net/restapi';check_contract('Developer endpoint accepted',docusign_settings()['base_url']===$config['docusign']['base_url']);
$c=['title'=>'Test','provider_transaction_id'=>'test-transaction','signature_page'=>2,'signature_x'=>100,'signature_y'=>200];$recipient=['id'=>42,'name'=>'Synthetic','email'=>'synthetic@test.invalid'];$payload=docusign_envelope_payload($c,$recipient,'%PDF-1.4');
check_contract('PDF content sent exactly',base64_decode($payload['documents'][0]['documentBase64'])==='%PDF-1.4');
check_contract('Embedded recipient bound to platform account',$payload['recipients']['signers'][0]['clientUserId']==='42');
check_contract('Configured signature coordinates retained',$payload['recipients']['signers'][0]['tabs']['signHereTabs'][0]['pageNumber']==='2');
check_contract('Dispatch reconciliation transaction retained',$payload['transactionId']==='test-transaction');
check_contract('Connect HMAC verifies raw bytes',docusign_valid_hmac('payload',[base64_encode(hash_hmac('sha256','payload','test-secret-test-secret',true))],['test-secret-test-secret']));
check_contract('Connect HMAC rejects tampering',!docusign_valid_hmac('changed',[base64_encode(hash_hmac('sha256','payload','test-secret-test-secret',true))],['test-secret-test-secret']));
check_contract('Connect HMAC rejects missing secrets',!docusign_valid_hmac('payload',[],[]));
file_put_contents(__DIR__.'/docusign-unit-results.json',json_encode($results,JSON_PRETTY_PRINT));

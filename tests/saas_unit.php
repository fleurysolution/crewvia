<?php
$appRoot=is_dir(__DIR__.'/test-app/app')?__DIR__.'/test-app':dirname(__DIR__);
require $appRoot.'/app/tenancy.php';
require $appRoot.'/app/saas.php';
$checks=[];
function check_saas(string $name, bool $condition): void { global $checks; if(!$condition) throw new RuntimeException($name); $checks[]=$name;echo 'PASS '.$name."\n"; }
function rejects(callable $call): bool { try{$call();return false;}catch(Throwable $e){return true;} }
$root=sys_get_temp_dir().'/crewvia-unit-'.bin2hex(random_bytes(6));mkdir($root.'/tenants',0700,true);
register_shutdown_function(function()use($root){foreach(glob($root.'/tenants/*')?:[] as $file)unlink($file);rmdir($root.'/tenants');rmdir($root);});
$a=['tenant_id'=>'agency-a','db_host'=>'localhost','db_name'=>'isolated_a','db_user'=>'a','db_pass'=>'','app_url'=>'https://agency-a.test','encryption_key'=>base64_encode(random_bytes(32))];
$b=['tenant_id'=>'agency-b','db_host'=>'localhost','db_name'=>'isolated_b','db_user'=>'b','db_pass'=>'','app_url'=>'https://agency-b.test','encryption_key'=>base64_encode(random_bytes(32))];
file_put_contents($root.'/tenants/a.php','<?php return '.var_export($a,true).';');
file_put_contents($root.'/tenants/b.php','<?php return '.var_export($b,true).';');
$base=['tenant_hosts'=>['agency-a.test'=>'a.php','agency-b.test'=>'b.php']];
$resolvedA=workforce_tenant_config($base,['HTTP_HOST'=>'agency-a.test:443'],$root);
$resolvedB=workforce_tenant_config($base,['HTTP_HOST'=>'AGENCY-B.TEST'],$root);
check_saas('Tenant hosts select separate databases',$resolvedA['db_name']!==$resolvedB['db_name']);
check_saas('Tenant sessions have separate cookie names',workforce_session_name($a)!==workforce_session_name($b));
check_saas('Tenant documents use separate storage namespaces',workforce_storage_path($a)!==workforce_storage_path($b));
check_saas('Unknown host fails closed',rejects(fn()=>workforce_tenant_config($base,['HTTP_HOST'=>'unknown.test'],$root)));
check_saas('Host header cannot select a filesystem path',rejects(fn()=>workforce_tenant_config($base,['HTTP_HOST'=>'../agency-a.test'],$root)));
check_saas('Tenant file traversal rejected',rejects(fn()=>workforce_tenant_config(['tenant_hosts'=>['agency-a.test'=>'../config.php']],['HTTP_HOST'=>'agency-a.test'],$root)));
check_saas('Single installation remains compatible',workforce_tenant_config(['db_name'=>'legacy'],[],$root)['db_name']==='legacy');
$now=time();$secret='whsec_synthetic_test_only';$payload=json_encode(['id'=>'evt_synthetic123','type'=>'test.event','data'=>['object'=>[]]]);
$signature=hash_hmac('sha256',$now.'.'.$payload,$secret);
check_saas('Stripe raw signature validates',saas_verify_event($payload,'t='.$now.',v1='.$signature,$secret,$now)['id']==='evt_synthetic123');
check_saas('Stripe rotated signatures validate',saas_verify_event($payload,'t='.$now.',v1=bad,v1='.$signature,$secret,$now)['id']==='evt_synthetic123');
check_saas('Tampered webhook rejected',rejects(fn()=>saas_verify_event($payload.' ','t='.$now.',v1='.$signature,$secret,$now)));
check_saas('Expired webhook rejected',rejects(fn()=>saas_verify_event($payload,'t='.$now.',v1='.$signature,$secret,$now+301)));
check_saas('Future webhook outside tolerance rejected',rejects(fn()=>saas_verify_event($payload,'t='.$now.',v1='.$signature,$secret,$now-301)));
check_saas('Missing webhook secret rejected',rejects(fn()=>saas_verify_event($payload,'t='.$now.',v1='.$signature,'',$now)));
check_saas('Oversized webhook rejected',rejects(fn()=>saas_verify_event(str_repeat('x',1048577),'t='.$now.',v1='.$signature,$secret,$now)));
$config=['commercial_mode'=>'demo'];check_saas('Demo does not enable Stripe',!saas_configured());
$config=['commercial_mode'=>'subscription','tenant_id'=>'agency-a'];check_saas('Missing payment credentials do not enable Stripe',!saas_configured());
file_put_contents(__DIR__.'/saas-unit-results.json',json_encode($checks,JSON_PRETTY_PRINT));

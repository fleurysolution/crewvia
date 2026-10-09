<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit('CLI only');
require __DIR__.'/../app/tenancy.php';
$config=require __DIR__.'/../config.php';$ids=[];$databases=[];$keys=[];$root=dirname(__DIR__);
foreach(($config['tenant_hosts'] ?? []) as $host=>$file) {
    $tenant=workforce_tenant_config($config,['HTTP_HOST'=>$host],$root);
    $id=$tenant['tenant_id'];$database=$tenant['db_host'].'/'.$tenant['db_name'];$key=hash('sha256',$tenant['encryption_key']);
    if(isset($ids[$id]) || isset($databases[$database]) || isset($keys[$key])) throw new RuntimeException('Duplicate workspace identity, database or encryption key. Each agency needs isolated configuration.');
    $ids[$id]=true;$databases[$database]=true;$keys[$key]=true;
    echo 'Verified workspace host: '.$host."\n";
}
echo 'Registered isolated workspaces: '.count($ids)."\n";

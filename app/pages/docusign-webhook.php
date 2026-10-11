<?php
require_once __DIR__.'/../docusign.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$raw=(string)file_get_contents('php://input',false,null,0,131073);$headers=[];for($i=1;$i<=3;$i++)$headers[]=$_SERVER['HTTP_X_DOCUSIGN_SIGNATURE_'.$i]??'';
$status=docusign_webhook_handle($raw,$headers,$config['docusign']??[]);
http_response_code($status);if($status===200)echo 'Queued';

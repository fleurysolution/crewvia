<?php
require __DIR__.'/../gmail.php';
if(!gmail_configured()) { http_response_code(503);exit(t('Google connection is not configured. Ask the administrator to complete OAuth setup.')); }
global $config;$_SESSION['google_state']=bin2hex(random_bytes(32));$_SESSION['google_started_at']=time();$_SESSION['google_connect_user']=uid();
$params=['client_id'=>$config['google_client_id'],'redirect_uri'=>$config['google_redirect_uri'],'response_type'=>'code','scope'=>'openid email profile https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send','access_type'=>'offline','prompt'=>'consent','state'=>$_SESSION['google_state']];
redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));

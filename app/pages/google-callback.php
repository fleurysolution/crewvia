<?php
require_once __DIR__.'/../gmail.php';require_once __DIR__.'/../security.php';global $config;
$state=(string)($_GET['state'] ?? '');$expected=(string)($_SESSION['google_state'] ?? '');$started=(int)($_SESSION['google_started_at'] ?? 0);$connectUid=(int)($_SESSION['google_connect_user'] ?? 0);
unset($_SESSION['google_state'],$_SESSION['google_started_at'],$_SESSION['google_connect_user']);
if(!$expected || !hash_equals($expected,$state) || time()-$started>600 || !gmail_configured()) { http_response_code(400);exit(t('Google authorization expired or invalid. Start again.')); }
if(!empty($_GET['error'])) { flash(t('Google authorization was not completed.'),'err');redirect('/login'); }
try {
 $tokens=google_http('https://oauth2.googleapis.com/token',['client_id'=>$config['google_client_id'],'client_secret'=>$config['google_client_secret'],'redirect_uri'=>$config['google_redirect_uri'],'code'=>(string)($_GET['code'] ?? ''),'grant_type'=>'authorization_code']);
 $profile=google_http('https://openidconnect.googleapis.com/v1/userinfo',null,(string)$tokens['access_token']);
 if(empty($profile['email_verified'])) throw new RuntimeException(t('Google email is not verified.'));
 $account=row('SELECT * FROM users WHERE email=? AND is_active=1',[(string)$profile['email']]);
 if(!$account || ($connectUid && $connectUid!==(int)$account['id'])) throw new RuntimeException(t('Use the Google account matching your existing RSS account.'));
 $tokens['expires_at']=time()+(int)($tokens['expires_in'] ?? 3600);
 q('INSERT INTO gmail_connections(user_id,email,encrypted_tokens) VALUES (?,?,?) ON DUPLICATE KEY UPDATE email=VALUES(email),encrypted_tokens=VALUES(encrypted_tokens)',[$account['id'],$profile['email'],token_encrypt($tokens)]);
 security_begin_login($account,'/inbox');
} catch(Throwable $e) { error_log('[rss-ops] Google authorization failed');flash(t('Google connection failed. Check configuration and use your registered account.'),'err');redirect('/login'); }

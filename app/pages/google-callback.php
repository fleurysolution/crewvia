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
 // Without read and send the inbox cannot work: refuse now rather than fail later.
 if(!gmail_scopes_ok((string)($tokens['scope'] ?? ''))) { integration_event('gmail','connect','refused','user:'.$account['id'],'Gmail read and send were not both granted',(int)$account['id']);flash(t('Allow Crewvia to read and send your Gmail, then connect again.'),'err');redirect('/login'); }
 gmail_store_connection((int)$account['id'],(string)$profile['email'],$tokens);
 security_begin_login($account,'/inbox');
} catch(Throwable $e) { error_log('[rss-ops] Google authorization failed');integration_event('gmail','connect','failed',null,'Authorization or account match failed');flash(t('Google connection failed. Check configuration and use your registered account.'),'err');redirect('/login'); }

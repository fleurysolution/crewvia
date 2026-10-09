<?php
require_login();require __DIR__.'/../gmail.php';$configured=gmail_configured();$connection=row('SELECT email FROM gmail_connections WHERE user_id=?',[uid()]);$messages=[];$selected=null;$error=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do'] ?? '')==='disconnect') { q('DELETE FROM gmail_connections WHERE user_id=?',[uid()]);log_activity('disconnected Gmail');redirect('/inbox'); }
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='send') {
 try {
  if(!$configured||!$connection)throw new RuntimeException('Connect your Gmail account first.');
  $to=trim((string)($_POST['recipient']??''));$subject=trim((string)($_POST['subject']??''));$body=trim((string)($_POST['body']??''));
  if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$to)||!$subject||mb_strlen($subject)>190||!$body||mb_strlen($body)>20000)throw new RuntimeException('Valid recipient, subject and message required.');
  require_once __DIR__.'/../security.php';if(!security_rate_limit('gmail-send',(string)uid(),30,3600))throw new RuntimeException('Email sending limit reached. Try again later.');
  $mime=gmail_compose($connection['email'],$to,$subject,$body);
  $result=google_http('https://gmail.googleapis.com/gmail/v1/users/me/messages/send',['raw'=>rtrim(strtr(base64_encode($mime),'+/','-_'),'=')],gmail_access_token(),true);
  if(empty($result['id']))throw new RuntimeException('Google request failed; reconnect or check provider configuration.');
  log_activity('sent Gmail message','user',uid(),'Provider message ID '.$result['id']);flash(t('Message accepted by Gmail.'));redirect('/inbox');
 }catch(Throwable $e){$error=t($e->getMessage());}
}
if($configured && $connection) {
 try {
  $token=gmail_access_token();$query=trim((string)($_GET['q'] ?? 'in:inbox'));
  $list=google_http('https://gmail.googleapis.com/gmail/v1/users/me/messages?'.http_build_query(['maxResults'=>20,'q'=>$query]),null,$token);
  foreach($list['messages'] ?? [] as $m) {
   $item=google_http('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($m['id']).'?format=metadata',null,$token);
   $headers=[];foreach($item['payload']['headers'] ?? [] as $h) $headers[strtolower($h['name'])]=$h['value'];
   $messages[]=['id'=>$item['id'],'subject'=>$headers['subject'] ?? '(No subject)','from'=>$headers['from'] ?? '', 'date'=>$headers['date'] ?? '', 'snippet'=>$item['snippet'] ?? ''];
  }
  $mid=(string)($_GET['message_id'] ?? '');
  if($mid && preg_match('/^[a-zA-Z0-9]+$/',$mid)) { $item=google_http('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($mid).'?format=full',null,$token);$selected=gmail_plain_text($item['payload'] ?? []);if(!$selected) $selected=$item['snippet'] ?? 'No plain-text body available.'; }
 } catch(Throwable $e) { $error=t($e->getMessage()); }
}
render('inbox',compact('configured','connection','messages','selected','error'));

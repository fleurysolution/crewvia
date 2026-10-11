<?php
/**
 * Gmail through Google OAuth: each user connects their own mailbox, read
 * and send. Off unless the OAuth client and a 32-byte key are configured.
 *
 * P4-M04:
 *   - tokens stay encrypted (AES-256-GCM); a refresh Google refuses, or a
 *     token that no longer decrypts, marks the connection to reconnect
 *     instead of failing on every page, and is recorded
 *   - the scopes granted are checked when connecting: without read and
 *     send the connection is refused, not discovered broken later
 *   - disconnecting revokes the token at Google, then deletes it here,
 *     even if Google cannot be reached (recorded either way)
 *   - reads and token refreshes are retried; a send never is, and its form
 *     carries a one-time key, so posting it twice sends it once
 */
require_once __DIR__ . '/integrations.php';

function gmail_configured(): bool {
 global $config;
 return !empty($config['google_client_id']) && !empty($config['google_client_secret']) && !empty($config['google_redirect_uri']) && strlen((string)base64_decode($config['encryption_key'] ?? '',true))===32;
}
function token_encrypt(array $tokens): string {
 global $config;$key=base64_decode($config['encryption_key'] ?? '',true);
 if(!$key || strlen($key)!==32) throw new RuntimeException('Configure a 32-byte encryption key.');
 $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($tokens,JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
 if($cipher===false) throw new RuntimeException('Token encryption failed.');
 return base64_encode($iv.$tag.$cipher);
}
function token_decrypt(string $encrypted): array {
 global $config;$raw=base64_decode($encrypted,true);$key=base64_decode($config['encryption_key'] ?? '',true);
 if(!$raw || strlen($raw)<29 || !$key || strlen($key)!==32) throw new RuntimeException('Invalid token configuration.');
 $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
 if($plain===false) throw new RuntimeException('Token decryption failed.');
 return json_decode($plain,true,512,JSON_THROW_ON_ERROR);
}

/**
 * One Google call. A read (no form) and the token endpoint are retried;
 * anything else is sent once. A refusal carries the HTTP status as its code.
 */
function google_http(string $url, ?array $form=null, ?string $bearer=null, bool $json=false, ?bool $retryable=null): array {
 $headers=['Accept: application/json'];if($json)$headers[]='Content-Type: application/json';if($bearer) $headers[]='Authorization: Bearer '.$bearer;
 $body=$form===null?null:($json?json_encode($form,JSON_THROW_ON_ERROR):http_build_query($form));
 $retryable??=($form===null || str_starts_with($url,'https://oauth2.googleapis.com/token'));
 $r=integration_request('gmail',$form===null?'GET':'POST',$url,$headers,$body,$retryable);
 if($r['status']<200 || $r['status']>=300) throw new RuntimeException('Google request failed; reconnect or check provider configuration.',(int)$r['status']);
 return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
}

/** The scopes the inbox needs: read, and send. */
function gmail_required_scopes(): array {
 return ['https://www.googleapis.com/auth/gmail.readonly','https://www.googleapis.com/auth/gmail.send'];
}
function gmail_scopes_ok(string $granted): bool {
 $have=preg_split('/\s+/',trim($granted)) ?: [];
 return !array_diff(gmail_required_scopes(),$have);
}

/** Keep a fresh connection: encrypted, active, with what was granted. */
function gmail_store_connection(int $userId,string $email,array $tokens): void {
 $tokens['expires_at']=time()+(int)($tokens['expires_in'] ?? 3600);
 q("INSERT INTO gmail_connections(user_id,email,encrypted_tokens,status,scopes,last_refreshed_at,last_error) VALUES (?,?,?,'active',?,NOW(),NULL)
    ON DUPLICATE KEY UPDATE email=VALUES(email),encrypted_tokens=VALUES(encrypted_tokens),status='active',scopes=VALUES(scopes),last_refreshed_at=NOW(),last_error=NULL",
   [$userId,$email,token_encrypt($tokens),mb_substr((string)($tokens['scope'] ?? ''),0,500)]);
 integration_event('gmail','connected','ok','user:'.$userId,null,$userId);
}

/** The connection can no longer be used until its owner connects again. */
function gmail_mark_reconnect(int $userId,string $why): void {
 q("UPDATE gmail_connections SET status='reconnect',last_error=? WHERE user_id=?",[mb_substr($why,0,190),$userId]);
 integration_event('gmail','token refresh','refused','user:'.$userId,$why,$userId);
}

function gmail_access_token(?int $userId=null): string {
 global $config;$userId??=uid();$connection=row('SELECT * FROM gmail_connections WHERE user_id=?',[$userId]);
 if(!$connection) throw new RuntimeException('Connect your Gmail account first.');
 if(($connection['status'] ?? 'active')==='reconnect') throw new RuntimeException('Reconnect Gmail to renew access.');
 try { $tokens=token_decrypt($connection['encrypted_tokens']); }
 catch(Throwable $e) { gmail_mark_reconnect($userId,'The stored token no longer decrypts (the encryption key changed?)');throw new RuntimeException('Reconnect Gmail to renew access.'); }
 if((int)($tokens['expires_at'] ?? 0) <= time()+60) {
  if(empty($tokens['refresh_token'])) { gmail_mark_reconnect($userId,'No refresh token was granted');throw new RuntimeException('Reconnect Gmail to renew access.'); }
  try {
   $fresh=google_http('https://oauth2.googleapis.com/token',['client_id'=>$config['google_client_id'],'client_secret'=>$config['google_client_secret'],'refresh_token'=>$tokens['refresh_token'],'grant_type'=>'refresh_token']);
  } catch(RuntimeException $e) {
   // 400 or 401: Google refused the refresh token (revoked, expired, password changed). Anything else may pass.
   if(in_array($e->getCode(),[400,401],true)) { gmail_mark_reconnect($userId,'Google refused the refresh token (HTTP '.$e->getCode().')');throw new RuntimeException('Reconnect Gmail to renew access.'); }
   integration_event('gmail','token refresh','failed','user:'.$userId,'HTTP '.$e->getCode(),$userId);
   throw $e;
  }
  $tokens=array_merge($tokens,$fresh);$tokens['expires_at']=time()+(int)($fresh['expires_in'] ?? 3600);
  q('UPDATE gmail_connections SET encrypted_tokens=?,last_refreshed_at=NOW(),last_error=NULL WHERE user_id=?',[token_encrypt($tokens),$userId]);
  integration_event('gmail','token refresh','ok','user:'.$userId,null,$userId);
 }
 return (string)$tokens['access_token'];
}

/** Revoke at Google, then forget here, whatever Google answers. */
function gmail_disconnect(int $userId): void {
 $connection=row('SELECT * FROM gmail_connections WHERE user_id=?',[$userId]);
 if(!$connection) return;
 try {
  $tokens=token_decrypt($connection['encrypted_tokens']);
  $token=(string)($tokens['refresh_token'] ?? $tokens['access_token'] ?? '');
  $r=$token===''?['status'=>0]:integration_request('gmail','POST','https://oauth2.googleapis.com/revoke',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['token'=>$token]),true);
  integration_event('gmail','revoke',$r['status']===200?'ok':'failed','user:'.$userId,$r['status']===200?null:'Google did not confirm the revocation (HTTP '.$r['status'].'); the token is deleted here',$userId);
 } catch(Throwable $e) {
  integration_event('gmail','revoke','failed','user:'.$userId,'The stored token could not be read; it is deleted here',$userId);
 }
 q('DELETE FROM gmail_connections WHERE user_id=?',[$userId]);
 integration_event('gmail','disconnected','ok','user:'.$userId,null,$userId);
}

function gmail_plain_text(array $part): string {
 if(($part['mimeType'] ?? '')==='text/plain' && !empty($part['body']['data'])) return (string)base64_decode(strtr($part['body']['data'],'-_','+/'));
 foreach($part['parts'] ?? [] as $child) { $text=gmail_plain_text($child);if($text!=='') return $text; }
 return '';
}

function gmail_compose(string $from,string $to,string $subject,string $body): string {
 foreach([$from,$to] as $address)if(!filter_var($address,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$address))throw new InvalidArgumentException('Invalid email address.');
 return 'From: '.$from."\r\nTo: ".$to."\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
}

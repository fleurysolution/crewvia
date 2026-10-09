<?php
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
function google_http(string $url, ?array $form=null, ?string $bearer=null, bool $json=false): array {
 $ch=curl_init($url);$headers=['Accept: application/json'];if($json)$headers[]='Content-Type: application/json';if($bearer) $headers[]='Authorization: Bearer '.$bearer;
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 if($form!==null) { curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$json?json_encode($form,JSON_THROW_ON_ERROR):http_build_query($form)); }
 $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($body===false || $code<200 || $code>=300) throw new RuntimeException('Google request failed; reconnect or check provider configuration.');
 return json_decode($body,true,512,JSON_THROW_ON_ERROR);
}
function gmail_access_token(): string {
 global $config;$connection=row('SELECT * FROM gmail_connections WHERE user_id=?',[uid()]);
 if(!$connection) throw new RuntimeException('Connect your Gmail account first.');
 $tokens=token_decrypt($connection['encrypted_tokens']);
 if((int)($tokens['expires_at'] ?? 0) <= time()+60) {
  if(empty($tokens['refresh_token'])) throw new RuntimeException('Reconnect Gmail to renew access.');
  $fresh=google_http('https://oauth2.googleapis.com/token',['client_id'=>$config['google_client_id'],'client_secret'=>$config['google_client_secret'],'refresh_token'=>$tokens['refresh_token'],'grant_type'=>'refresh_token']);
  $tokens=array_merge($tokens,$fresh);$tokens['expires_at']=time()+(int)($fresh['expires_in'] ?? 3600);
  q('UPDATE gmail_connections SET encrypted_tokens=? WHERE user_id=?',[token_encrypt($tokens),uid()]);
 }
 return (string)$tokens['access_token'];
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

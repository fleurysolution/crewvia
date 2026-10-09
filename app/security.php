<?php
/** Authentication primitives; secrets and recovery messages are encrypted at rest. */
function security_rate_limit(string $purpose, string $identity, int $maximum=10, int $window=900): bool
{
    $bucket=hash('sha256',$purpose.'|'.$identity);
    $now=time();
    q('INSERT INTO auth_rate_limits(bucket,window_start,attempts) VALUES (?,?,1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<=?,1,attempts+1),window_start=IF(window_start<=?,VALUES(window_start),window_start)',[$bucket,$now,$now-$window,$now-$window]);
    return (int)val('SELECT attempts FROM auth_rate_limits WHERE bucket=?',[$bucket])<=$maximum;
}
function totp_base32(string $raw): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$encoded='';
    foreach(str_split($raw) as $char)$bits.=str_pad(decbin(ord($char)),8,'0',STR_PAD_LEFT);
    foreach(str_split($bits,5) as $chunk)$encoded.=$alphabet[bindec(str_pad($chunk,5,'0'))];
    return $encoded;
}
function totp_decode(string $secret): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';$raw='';
    foreach(str_split(strtoupper($secret)) as $char){$position=strpos($alphabet,$char);if($position===false)throw new InvalidArgumentException('Invalid authenticator secret.');$bits.=str_pad(decbin($position),5,'0',STR_PAD_LEFT);}
    foreach(str_split($bits,8) as $chunk)if(strlen($chunk)===8)$raw.=chr(bindec($chunk));
    return $raw;
}
function totp_code(string $secret,int $step,int $digits=6): string
{
    $hash=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),totp_decode($secret),true);
    $offset=ord($hash[19])&15;$number=(unpack('N',substr($hash,$offset,4))[1]&0x7fffffff)%(10**$digits);
    return str_pad((string)$number,$digits,'0',STR_PAD_LEFT);
}
function totp_match(string $secret,string $code,int $last=-1,?int $now=null): ?int
{
    if(!preg_match('/^\d{6}$/D',$code))return null;
    $step=intdiv($now??time(),30);
    for($i=-1;$i<=1;$i++)if($step+$i>$last && hash_equals(totp_code($secret,$step+$i),$code))return $step+$i;
    return null;
}
function security_totp_verify(int $userId,string $code): bool
{
    $recovery=strtoupper(str_replace(['-',' '],'',$code));
    if(preg_match('/^[A-F0-9]{16}$/D',$recovery))return q('DELETE FROM account_recovery_codes WHERE user_id=? AND code_hash=?',[$userId,hash('sha256',$recovery)])->rowCount()===1;
    require_once __DIR__.'/gmail.php';db()->beginTransaction();
    try {
        $state=row('SELECT * FROM account_security WHERE user_id=? FOR UPDATE',[$userId]);
        if(!$state || !$state['encrypted_totp']){db()->rollBack();return false;}
        $secret=token_decrypt($state['encrypted_totp'])['secret'];
        $step=totp_match($secret,$code,(int)$state['last_totp_step']);
        if($step===null){db()->rollBack();return false;}
        q('UPDATE account_security SET last_totp_step=? WHERE user_id=?',[$step,$userId]);db()->commit();return true;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function security_finish_login(array $account,string $to='/'): never
{
    session_regenerate_id(true);
    $_SESSION['user']=array_intersect_key($account,array_flip(['id','name','email','role','locale']));
    $_SESSION['auth_version']=(int)val('SELECT session_version FROM account_security WHERE user_id=?',[$account['id']]);
    $_SESSION['authenticated_at']=time();
    q('UPDATE users SET last_login_at=NOW() WHERE id=?',[$account['id']]);
    log_activity('signed in');
    if(!str_starts_with($to,'/')||str_starts_with($to,'//')||preg_match('/[\\\\\x00-\x1f]/',rawurldecode($to)))$to='/';
    redirect($to);
}
function security_begin_login(array $account,string $to='/'): never
{
    if(val('SELECT encrypted_totp FROM account_security WHERE user_id=?',[$account['id']])) {
        session_regenerate_id(true);unset($_SESSION['user']);
        $_SESSION['mfa_pending']=['user_id'=>(int)$account['id'],'started'=>time(),'to'=>$to];redirect('/mfa');
    }
    security_finish_login($account,$to);
}

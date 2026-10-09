<?php
require_login();require_once __DIR__.'/../security.php';require_once __DIR__.'/../gmail.php';
$state=row('SELECT * FROM account_security WHERE user_id=?',[uid()]);$codes=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['do']??'');
    $me=row('SELECT password_hash FROM users WHERE id=?',[uid()]);
    $allowed=security_rate_limit('security-settings',(string)uid(),10,900);
    if(!$allowed || !password_verify((string)($_POST['password']??''),$me['password_hash'])){flash(t('That is not your current password.'),'err');redirect('/account-security');}
    if($action==='start'&&!($state['encrypted_totp']??null)) {
        $_SESSION['totp_setup']=['secret'=>totp_base32(random_bytes(20)),'started'=>time()];
    }
    if($action==='enable'&&!($state['encrypted_totp']??null)) {
        $setup=$_SESSION['totp_setup']??null;
        $step=$setup&&time()-$setup['started']<600?totp_match($setup['secret'],trim((string)($_POST['code']??''))):null;
        if($step===null){flash(t('Invalid or expired authentication code.'),'err');redirect('/account-security');}
        q('INSERT INTO account_security(user_id,encrypted_totp,last_totp_step,session_version) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE encrypted_totp=VALUES(encrypted_totp),last_totp_step=VALUES(last_totp_step),session_version=session_version+1',[uid(),token_encrypt(['secret'=>$setup['secret']]),$step]);
        q('DELETE FROM account_recovery_codes WHERE user_id=?',[uid()]);$recoveryCodes=[];
        for($i=0;$i<8;$i++){$code=strtoupper(bin2hex(random_bytes(8)));q('INSERT INTO account_recovery_codes(user_id,code_hash) VALUES (?,?)',[uid(),hash('sha256',$code)]);$recoveryCodes[]=implode('-',str_split($code,4));}
        $_SESSION['mfa_recovery_codes']=$recoveryCodes;
        unset($_SESSION['totp_setup']);$_SESSION['auth_version']=(int)val('SELECT session_version FROM account_security WHERE user_id=?',[uid()]);
        log_activity('enabled authenticator');flash(t('Two-step verification enabled.'));
    }
    if($action==='disable'&&($state['encrypted_totp']??null)) {
        if(!security_totp_verify(uid(),trim((string)($_POST['code']??'')))){flash(t('Invalid or expired authentication code.'),'err');redirect('/account-security');}
        q('UPDATE account_security SET encrypted_totp=NULL,last_totp_step=-1,session_version=session_version+1 WHERE user_id=?',[uid()]);
        q('DELETE FROM account_recovery_codes WHERE user_id=?',[uid()]);
        $_SESSION['auth_version']=(int)val('SELECT session_version FROM account_security WHERE user_id=?',[uid()]);
        log_activity('disabled authenticator');flash(t('Two-step verification disabled.'));
    }
    if($action==='revoke') {
        q('INSERT INTO account_security(user_id,session_version) VALUES (?,1) ON DUPLICATE KEY UPDATE session_version=session_version+1',[uid()]);
        $_SESSION['auth_version']=(int)val('SELECT session_version FROM account_security WHERE user_id=?',[uid()]);
        log_activity('revoked other sessions');flash(t('Other sessions revoked.'));
    }
    redirect('/account-security');
}
$setup=$_SESSION['totp_setup']??null;
if($setup&&time()-$setup['started']>=600){unset($_SESSION['totp_setup']);$setup=null;}
$enabled=!empty($state['encrypted_totp']);
$encryptionReady=strlen((string)base64_decode($config['encryption_key']??'',true))===32;
$recoveryCodes=$_SESSION['mfa_recovery_codes']??[];unset($_SESSION['mfa_recovery_codes']);
render('account-security',compact('enabled','setup','encryptionReady','recoveryCodes'));

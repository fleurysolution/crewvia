<?php
require_once __DIR__.'/../security.php';require_once __DIR__.'/../gmail.php';
$message=null;$token=(string)($_GET['token']??$_POST['token']??'');$reset=null;
if(preg_match('/^[a-f0-9]{64}$/D',$token))$reset=row('SELECT r.* FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>NOW() AND u.is_active=1',[hash('sha256',$token)]);
if($_SERVER['REQUEST_METHOD']==='POST') {
    $allowed=security_rate_limit('recover-ip',(string)($_SERVER['REMOTE_ADDR']??''),15,900);
    if($token!=='') {
        $password=(string)($_POST['password']??'');
        if(!$allowed||!$reset||strlen($password)<10||strlen($password)>200){http_response_code(422);$message=t('Recovery link expired or password invalid.');}
        else {
            db()->beginTransaction();
            $locked=row('SELECT * FROM password_resets WHERE id=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[$reset['id']]);
            if(!$locked){db()->rollBack();http_response_code(410);$message=t('Recovery link expired or password invalid.');}
            else {
                q('UPDATE users SET password_hash=?,must_change_pw=0 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$reset['user_id']]);
                q('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL',[$reset['user_id']]);
                q('INSERT INTO account_security(user_id,session_version) VALUES (?,1) ON DUPLICATE KEY UPDATE session_version=session_version+1',[$reset['user_id']]);
                db()->commit();unset($_SESSION['user'],$_SESSION['mfa_pending']);flash(t('Password reset. Sign in with your new password.'));redirect('/login');
            }
        }
    } else {
        $email=mb_strtolower(trim((string)($_POST['email']??'')));
        $byEmail=security_rate_limit('recover-email',$email,3,900);
        $account=$allowed&&$byEmail?row('SELECT id,email FROM users WHERE email=? AND is_active=1',[$email]):null;
        if($account && !empty($config['app_url']) && strlen((string)base64_decode($config['encryption_key']??'',true))===32) {
            $private=bin2hex(random_bytes(32));
            $body=['subject'=>'Reset your password','body'=>'Use this private link within 30 minutes: '.rtrim($config['app_url'],'/').'/recover?token='.$private];
            db()->beginTransaction();
            q('SELECT id FROM users WHERE id=? FOR UPDATE',[$account['id']]);
            q('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL',[$account['id']]);
            q('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$account['id'],hash('sha256',$private)]);$rid=(int)db()->lastInsertId();
            q('INSERT INTO account_mail_outbox(reset_id,recipient,encrypted_message) VALUES (?,?,?)',[$rid,$account['email'],token_encrypt($body)]);db()->commit();
        }
        $message=t('If this account exists, a recovery message has been queued.');
    }
}
?><!doctype html><html lang="<?= e(locale()) ?>"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= te('Recover account') ?></title><link rel="stylesheet" href="/assets/public-auth.css"><main class="auth-card"><h1><?= te('Recover account') ?></h1><?php if($message): ?><p role="alert"><?= e($message) ?></p><?php endif; ?><?php if(!$token||$reset): ?><form method="post"><?= csrf_field() ?><?php if($token): ?><input type="hidden" name="token" value="<?= e($token) ?>"><label><?= te('New password') ?><input name="password" type="password" required minlength="10" maxlength="200" autocomplete="new-password"></label><?php else: ?><label><?= te('Email') ?><input name="email" type="email" required maxlength="190" autocomplete="email"></label><?php endif; ?><button><?= te('Continue') ?></button></form><?php else: ?><p><?= te('Recovery link expired or password invalid.') ?></p><?php endif; ?><a href="/login"><?= te('Back to sign in') ?></a></main></html>

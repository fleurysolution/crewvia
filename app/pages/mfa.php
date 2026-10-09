<?php
require_once __DIR__.'/../security.php';
$pending=$_SESSION['mfa_pending']??null;$error=null;
if(!$pending||time()-(int)$pending['started']>300){unset($_SESSION['mfa_pending']);redirect('/login');}
if($_SERVER['REQUEST_METHOD']==='POST') {
    $allowed=security_rate_limit('mfa',(string)$pending['user_id'],6,300);
    if($allowed && security_totp_verify((int)$pending['user_id'],trim((string)($_POST['code']??'')))) {
        $account=row('SELECT * FROM users WHERE id=? AND is_active=1',[$pending['user_id']]);
        unset($_SESSION['mfa_pending']);if($account)security_finish_login($account,$pending['to']);
    }
    $error=t('Invalid or expired authentication code.');
}
?><!doctype html><html lang="<?= e(locale()) ?>"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= te('Two-step verification') ?></title><link rel="stylesheet" href="/assets/public-auth.css"><main class="auth-card"><h1><?= te('Two-step verification') ?></h1><?php if($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?><form method="post"><?= csrf_field() ?><label><?= te('Authenticator code') ?><input name="code" required pattern="([0-9]{6}|[A-Fa-f0-9]{4}(-[A-Fa-f0-9]{4}){3})" autocomplete="one-time-code"></label><p><?= te('Use an authenticator code or a saved recovery code.') ?></p><button><?= te('Verify') ?></button></form><p><a href="/login"><?= te('Back to sign in') ?></a></p></main></html>

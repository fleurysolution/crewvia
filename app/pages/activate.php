<?php
$token=(string)($_GET['token'] ?? $_POST['token'] ?? '');
$inv=row('SELECT i.*,c.full_name,c.email FROM invitations i JOIN candidates c ON c.id=i.candidate_id WHERE i.token_hash=? AND i.used_at IS NULL AND i.expires_at>NOW()',[hash('sha256',$token)]);
if(!$inv) { refuse(410, t('Invitation expired or already used. Ask recruiting for a new invitation.')); }
if($_SERVER['REQUEST_METHOD']==='POST') {
 $pass=(string)($_POST['password'] ?? '');
 require_once __DIR__.'/../security.php';
 if(!security_rate_limit('activation-ip',(string)($_SERVER['REMOTE_ADDR']??''),20,900)){refuse(429, t('Please wait before submitting again.'));}
 if(strlen($pass)>200){refuse(422, t('Password is too long.'));}
 if(strlen($pass)<12) { refuse(422, t('Use at least 12 characters.')); }
 db()->beginTransaction();
 $locked=row('SELECT id FROM invitations WHERE id=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[$inv['id']]);
 if(!$locked || row('SELECT id FROM users WHERE email=?',[$inv['email']]) || row('SELECT user_id FROM worker_accounts WHERE candidate_id=?',[$inv['candidate_id']])) { db()->rollBack(); refuse(409, t('Account already exists or invitation was used. Sign in or contact recruiting.')); }
 q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'worker',0)",[$inv['full_name'],$inv['email'],password_hash($pass,PASSWORD_DEFAULT)]);
 $id=(int)db()->lastInsertId(); q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)',[$id,$inv['candidate_id']]);
 q('UPDATE invitations SET used_at=NOW() WHERE id=?',[$inv['id']]); db()->commit(); redirect('/login');
}
render('activate',compact('token','inv'));

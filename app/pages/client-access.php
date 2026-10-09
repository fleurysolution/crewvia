<?php
require_role('admin');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['do'] ?? '');
    $clientId=(int)($_POST['client_id'] ?? 0);
    if(!row('SELECT id FROM clients WHERE id=?',[$clientId])) {refuse(422, t('Select a valid client.'));}
    if($action==='create') {
        $name=trim((string)($_POST['name'] ?? ''));
        $email=trim((string)($_POST['email'] ?? ''));
        if(!$name || mb_strlen($name)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || row('SELECT id FROM users WHERE email=?',[$email])) {
            flash(t('Enter a name and an unused valid email.'),'err');redirect('/client-access');
        }
        $temporary=bin2hex(random_bytes(12));
        db()->beginTransaction();
        try {
            q("INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,'client',1)",[$name,$email,password_hash($temporary,PASSWORD_DEFAULT)]);
            $newUser=(int)db()->lastInsertId();
            q('INSERT INTO client_access(user_id,client_id,granted_by) VALUES (?,?,?)',[$newUser,$clientId,uid()]);
            db()->commit();
            log_activity('created client portal account','user',$newUser);
            flash(t('Temporary client password: :password. Share privately; a change is required at first sign-in.',['password'=>$temporary]));
        } catch(Throwable $error) {if(db()->inTransaction())db()->rollBack();throw $error;}
    } elseif(in_array($action,['grant','revoke'],true)) {
        $target=(int)($_POST['user_id'] ?? 0);
        if(!row("SELECT id FROM users WHERE id=? AND role='client'",[$target])) {refuse(422, t('Select a client portal account.'));}
        if($action==='grant')q('INSERT IGNORE INTO client_access(user_id,client_id,granted_by) VALUES (?,?,?)',[$target,$clientId,uid()]);
        else q('DELETE FROM client_access WHERE user_id=? AND client_id=?',[$target,$clientId]);
        log_activity($action.' client access','user',$target,(string)$clientId);
    }
    redirect('/client-access');
}
$clients=rows('SELECT id,name FROM clients ORDER BY name');
$accounts=rows("SELECT id,name,email,is_active FROM users WHERE role='client' ORDER BY name");
$grants=rows('SELECT a.user_id,a.client_id,u.name,u.email,c.name client_name FROM client_access a JOIN users u ON u.id=a.user_id JOIN clients c ON c.id=a.client_id ORDER BY c.name,u.name');
render('client-access',compact('clients','accounts','grants'));

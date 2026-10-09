<?php
require_role('admin');
if($_SERVER['REQUEST_METHOD']==='POST') {
    q("UPDATE email_outbox o JOIN invitations i ON i.id=o.invitation_id SET o.status='pending' WHERE o.id=? AND o.status='failed' AND o.attempts<5 AND i.expires_at>NOW() AND i.used_at IS NULL",[(int)($_POST['id'] ?? 0)]);
    redirect('/email-delivery');
}
$messages=rows('SELECT id,recipient,status,attempts,created_at,sent_at FROM email_outbox ORDER BY id DESC LIMIT 100');
render('email-delivery',compact('messages'));

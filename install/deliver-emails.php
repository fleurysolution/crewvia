<?php
if(PHP_SAPI!=='cli') exit('CLI only');
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/gmail.php';
if(empty($config['email_delivery_enabled']) || !filter_var($config['email_from'] ?? '',FILTER_VALIDATE_EMAIL)) exit("Configure an approved PHP mail relay and email_from before delivery. No messages sent.\n");
for($i=0;$i<50;$i++) {
    db()->beginTransaction();
    $message=row("SELECT o.* FROM email_outbox o JOIN invitations i ON i.id=o.invitation_id WHERE i.used_at IS NULL AND i.expires_at>NOW() AND (o.status='pending' OR o.status='sending' AND o.lease_until<NOW()) AND o.attempts<5 ORDER BY o.id LIMIT 1 FOR UPDATE");
    if(!$message) {db()->commit();break;}
    q("UPDATE email_outbox SET status='sending',lease_until=DATE_ADD(NOW(),INTERVAL 5 MINUTE),attempts=attempts+1 WHERE id=?",[$message['id']]);db()->commit();
    try {
        $content=token_decrypt($message['encrypted_message']);
        $headers=['From'=>$config['email_from'],'Content-Type'=>'text/plain; charset=UTF-8'];
        $sent=mail($message['recipient'],'=?UTF-8?B?'.base64_encode($content['subject']).'?=',$content['body'],$headers);
        if(!$sent) throw new RuntimeException('Mail relay rejected message.');
        q("UPDATE email_outbox SET status='sent',sent_at=NOW(),lease_until=NULL WHERE id=?",[$message['id']]);
        echo 'Relay accepted queued message '.$message['id']."\n";
    }catch(Throwable $e) {
        q("UPDATE email_outbox SET status='failed',lease_until=NULL WHERE id=?",[$message['id']]);
        error_log('[workforce mail] Delivery failed for queue item '.$message['id']);
    }
}

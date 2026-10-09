<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('CLI only');
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/gmail.php';
if(empty($config['email_delivery_enabled'])||!filter_var($config['email_from']??'',FILTER_VALIDATE_EMAIL))exit("Configure the approved email relay first.\n");
for($i=0;$i<50;$i++) {
    db()->beginTransaction();
    $m=row("SELECT o.* FROM account_mail_outbox o JOIN password_resets r ON r.id=o.reset_id WHERE r.used_at IS NULL AND r.expires_at>NOW() AND (o.status='pending' OR o.status='sending' AND o.lease_until<NOW()) AND o.attempts<5 ORDER BY o.id LIMIT 1 FOR UPDATE");
    if(!$m){db()->commit();break;}
    q("UPDATE account_mail_outbox SET status='sending',attempts=attempts+1,lease_until=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?",[$m['id']]);db()->commit();
    try {
        $content=token_decrypt($m['encrypted_message']);
        if(!filter_var($m['recipient'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$m['recipient']))throw new RuntimeException('Invalid address');
        if(!mail($m['recipient'],'=?UTF-8?B?'.base64_encode($content['subject']).'?=',$content['body'],['From'=>$config['email_from'],'Content-Type'=>'text/plain; charset=UTF-8']))throw new RuntimeException('Relay rejected message');
        q("UPDATE account_mail_outbox SET status='sent',sent_at=NOW(),lease_until=NULL WHERE id=?",[$m['id']]);echo 'Relay accepted account message '.$m['id'].PHP_EOL;
    }catch(Throwable $e){q("UPDATE account_mail_outbox SET status='failed',lease_until=NULL WHERE id=?",[$m['id']]);error_log('[account-mail] Queue item '.$m['id'].' failed');}
}

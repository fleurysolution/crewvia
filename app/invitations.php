<?php
/** Prepare a verified-email activation flow; never generate or email a password. */
function workforce_invite_candidate(int $candidateId, bool $replace = false): ?string
{
    global $config;
    require_once __DIR__.'/gmail.php';
    $transaction=!db()->inTransaction();if($transaction) db()->beginTransaction();
    try {
    $candidate=row('SELECT * FROM candidates WHERE id=? FOR UPDATE',[$candidateId]);
    if(!$candidate || !filter_var($candidate['email'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Candidate needs a valid email.');
    if(row('SELECT user_id FROM worker_accounts WHERE candidate_id=?',[$candidateId])) {if($transaction) db()->commit();return null;}
    if(row('SELECT id FROM users WHERE email=?',[$candidate['email']])) throw new RuntimeException('An account already uses this email. Review candidate identity before activation.');
    if(!$replace && row('SELECT id FROM invitations WHERE candidate_id=? AND used_at IS NULL AND expires_at>NOW()',[$candidateId])) {if($transaction) db()->commit();return null;}
    $token=bin2hex(random_bytes(32));$url=rtrim($config['app_url'],'/').'/activate?token='.$token;
    // Walkthrough finding 14: the message named neither the agency nor the job, which reads like a phishing email.
    $brand=$config['app_name']??'Crewvia';try{$named=val("SELECT setting_value FROM platform_settings WHERE setting_key='brand_name'");if($named)$brand=(string)$named;}catch(Throwable $ignored){}
    $job=(string)(val("SELECT v.title FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.candidate_id=? ORDER BY a.id DESC LIMIT 1",[$candidateId])?:'');
    $first=trim((string)strtok((string)$candidate['full_name'],' '));
    $message=['subject'=>$brand.': activate your employee portal'.($job!==''?' for '.$job:''),
              'body'=>'Hello '.($first!==''?$first:'there').",\n\n"
                     .'Your application'.($job!==''?' for '.$job:'').' with '.$brand.' has moved forward. '
                     .'Activate your personal portal and choose your password with this private link, valid for 48 hours:'."\n\n".$url."\n\n"
                     .'Your offer, your papers and your pay will be there. If you did not apply to '.$brand.', ignore this email.'];
    $encrypted=token_encrypt($message);
        if($replace) q('UPDATE invitations SET expires_at=NOW() WHERE candidate_id=? AND used_at IS NULL',[$candidateId]);
        q('INSERT INTO invitations(candidate_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 2 DAY))',[$candidateId,hash('sha256',$token)]);$invitation=(int)db()->lastInsertId();
        q('INSERT INTO email_outbox(invitation_id,recipient,encrypted_message) VALUES (?,?,?)',[$invitation,$candidate['email'],$encrypted]);
        if($transaction) db()->commit();return $url;
    } catch(Throwable $e) { if($transaction && db()->inTransaction()) db()->rollBack();throw $e; }
}

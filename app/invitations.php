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
    // P5-M51: nobody on the do-not-rehire register (under any record) is invited to a portal.
    require_once __DIR__.'/rehire.php';if(rehire_block_for($candidateId)) throw new RuntimeException('On the do-not-rehire register.');
    if(row('SELECT user_id FROM worker_accounts WHERE candidate_id=?',[$candidateId])) {if($transaction) db()->commit();return null;}
    if(row('SELECT id FROM users WHERE email=?',[$candidate['email']])) throw new RuntimeException('An account already uses this email. Review candidate identity before activation.');
    // P5-M45: only an invitation that was emailed counts; the link offered on the page right after applying does not stop the email.
    if(!$replace && row('SELECT i.id FROM invitations i JOIN email_outbox o ON o.invitation_id=i.id WHERE i.candidate_id=? AND i.used_at IS NULL AND i.expires_at>NOW()',[$candidateId])) {if($transaction) db()->commit();return null;}
    $token=bin2hex(random_bytes(32));$url=rtrim($config['app_url'],'/').'/activate?token='.$token;
    // Walkthrough finding 14: the message named neither the agency nor the job, which reads like a phishing email.
    // P5-M48: the agency the candidate applied to, not the platform's name.
    $brand=agency_name();
    $job=(string)(val("SELECT v.title FROM applications a JOIN vacancies v ON v.id=a.vacancy_id WHERE a.candidate_id=? ORDER BY a.id DESC LIMIT 1",[$candidateId])?:'');
    $first=trim((string)strtok((string)$candidate['full_name'],' '));
    $message=['subject'=>$brand.': activate your candidate portal'.($job!==''?' for '.$job:''),
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

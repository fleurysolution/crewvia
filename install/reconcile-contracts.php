<?php
/** Process authenticated Connect events, per tenant. Never sends envelopes. */
declare(strict_types=1);if(PHP_SAPI!=='cli')exit('CLI only');require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/contracts.php';require_once __DIR__.'/../app/docusign.php';
if(empty($config['docusign']['enabled'])){echo "DocuSign disabled. No provider requests made.\n";exit;}
docusign_settings();$lockName='workforce-docusign-connect';if(!(int)val('SELECT GET_LOCK(?,0)',[$lockName]))exit("Another reconciliation worker is running.\n");
try{foreach(rows("SELECT * FROM docusign_connect_events WHERE status='pending' AND attempts<5 ORDER BY created_at LIMIT 10") as $event){
 q('UPDATE docusign_connect_events SET attempts=attempts+1 WHERE payload_hash=?',[$event['payload_hash']]);
 $c=row('SELECT c.*,a.candidate_id,v.job_id,u.id worker_user_id,u.name worker_name,u.email worker_email FROM employment_contracts c JOIN applications a ON a.id=c.application_id JOIN vacancies v ON v.id=a.vacancy_id JOIN worker_accounts w ON w.candidate_id=a.candidate_id JOIN users u ON u.id=w.user_id WHERE c.envelope_id=?',[$event['envelope_id']]);
 try{if(!$c)throw new RuntimeException('Unknown contract');if(!in_array($c['status'],['signed','voided','declined'],true))docusign_reconcile($c);q("UPDATE docusign_connect_events SET status='processed' WHERE payload_hash=?",[$event['payload_hash']]);}
 catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('DocuSign reconciliation requires review for contract '.(int)($c['id']??0));}
}}finally{q('SELECT RELEASE_LOCK(?)',[$lockName]);}
echo "Connect event pass complete. Check pending events for provider failures.\n";

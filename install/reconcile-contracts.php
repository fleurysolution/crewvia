<?php
/** Process authenticated Connect events, per tenant, with retries and backoff (P4-M04). Never sends envelopes. */
declare(strict_types=1);if(PHP_SAPI!=='cli')exit('CLI only');require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/contracts.php';require_once __DIR__.'/../app/docusign.php';
if(empty($config['docusign']['enabled'])){echo "DocuSign disabled. No provider requests made.\n";exit;}
docusign_settings();$done=docusign_process_connect_events(10);
if(!empty($done['busy']))exit("Another reconciliation worker is running.\n");
echo 'Connect events: '.$done['processed'].' processed, '.$done['retry'].' to retry, '.$done['failed']." failed (see Integrations).\n";

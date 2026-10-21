<?php
/**
 * P5-M100: the billing ledger's daily job. Writes the records not yet in the ledger, then seals every finished month
 * that is not sealed yet - on the 1st, the month before. Meant for cron, once a day:
 *
 *   5 0 * * * php /path/to/crewvia/install/billing-close.php
 *
 * Running it twice writes nothing twice and seals nothing twice.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/billing-ledger.php';

$opsStarted = microtime(true);
$written = bl_sync();
$sealed = bl_close_due();
$check = bl_verify();
ops_record('billing_close', $check['ok'] ? 'ok' : 'failed', ['written' => $written, 'sealed' => $sealed, 'chain_ok' => $check['ok']], $opsStarted);
echo 'Billing: ' . $written . ' record(s) written; sealed: ' . ($sealed ? implode(', ', $sealed) : 'none') . '; chain '
   . ($check['ok'] ? 'intact (' . $check['checked'] . ' records)' : 'BROKEN at record ' . $check['broken_at']) . PHP_EOL;
exit($check['ok'] ? 0 : 1);

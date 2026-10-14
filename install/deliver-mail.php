<?php
/**
 * Sends the emails queued for workers (P5-M30): itineraries and notices.
 * Up to 50 a run; run it every 5 minutes from cron (docs/OPERATIONS.md):
 *   php install/deliver-mail.php --quiet
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/notices.php';

$quiet = in_array('--quiet', $argv, true);
$done = mail_deliver();
if ($done['held'] !== null) {
    if (! $quiet) {
        echo 'Worker mail held: ' . $done['held'] . PHP_EOL;
    }
    exit(0);
}
if (! $quiet || $done['sent'] || $done['failed']) {
    echo sprintf('Worker mail: %d sent, %d not accepted by the relay.', $done['sent'], $done['failed']) . PHP_EOL;
}
exit($done['failed'] ? 1 : 0);

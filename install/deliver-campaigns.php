<?php
/**
 * Sends campaign emails (T7): up to 50 a run, at most 500 in any 24 hours.
 * In cron every 5 minutes (docs/OPERATIONS.md):
 *
 *   php install/deliver-campaigns.php --quiet
 *
 * Nothing is sent while email is switched off or the agency's postal address
 * is missing; the run says so. --quiet prints only when something went out
 * or failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/campaigns.php';

$quiet = in_array('--quiet', $argv, true);
$done = cm_deliver();
if ($done['held'] !== null) {
    if (! $quiet || ! str_starts_with($done['held'], 'daily limit')) {
        echo 'Campaign email held: ' . $done['held'] . PHP_EOL;
    }
    exit(0);
}
if (! $quiet || $done['sent'] || $done['failed']) {
    echo sprintf('Campaign email: %d sent, %d failed, %d skipped.', $done['sent'], $done['failed'], $done['skipped']) . PHP_EOL;
}
exit($done['failed'] ? 1 : 0);

<?php
/**
 * Sends campaign emails (T7): up to 50 a run, at most 500 in any 24 hours.
 * In cron every 5 minutes (docs/OPERATIONS.md):
 *
 *   php install/deliver-campaigns.php --quiet
 *
 * Nothing is sent while email is switched off or the agency's postal address
 * is missing; the run says so. --quiet prints only when something went out
 * or failed, or, once an hour, when emails wait behind a hold.
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
    // P5-M110b: cron mails whatever --quiet prints. A hold with nothing waiting is not news, and one waiting is said
    // once an hour (the first run of the hour), not every 5 minutes.
    $waiting = (int) val("SELECT COUNT(*) FROM email_campaign_recipients r JOIN email_campaigns m ON m.id = r.campaign_id
                          WHERE r.status = 'queued' AND m.status = 'sending' AND m.channel = 'email'");
    if (! $quiet || ($waiting && ! str_starts_with($done['held'], 'daily limit') && (int) date('i') < 5)) {
        echo 'Campaign email held: ' . $done['held'] . PHP_EOL;
    }
    // P5-M29: texts may have gone while email was held.
    if ($done['sent'] || $done['failed']) {
        echo sprintf('Campaign texts: %d sent, %d failed, %d skipped.', $done['sent'], $done['failed'], $done['skipped']) . PHP_EOL;
    }
    exit($done['failed'] ? 1 : 0);
}
if (! $quiet || $done['sent'] || $done['failed']) {
    echo sprintf('Campaign email: %d sent, %d failed, %d skipped.', $done['sent'], $done['failed'], $done['skipped']) . PHP_EOL;
}
exit($done['failed'] ? 1 : 0);

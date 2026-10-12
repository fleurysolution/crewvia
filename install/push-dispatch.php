<?php
/**
 * P5-M06c: send the pushes for everything new since the last pass.
 *
 *   php install/push-dispatch.php
 *
 * Meant for cron every minute once push is turned on; the scheduled
 * workflows also run it. While push is off it sends nothing and calls no
 * outside address.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/push.php';

if (! push_enabled()) {
    echo 'Push: off; nothing sent.' . PHP_EOL;
    exit(0);
}

$r = push_dispatch();
echo 'Push: ' . $r['sent'] . ' sent for ' . $r['notifications'] . ' new notification(s); ' . $r['ended'] . ' subscription(s) ended.' . PHP_EOL;

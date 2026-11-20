<?php
/**
 * P5-M114: the reminder 30 days before a YEARLY subscription renews. A monthly plan gets none.
 * Meant for cron, once a day:
 *
 *   15 6 * * * php /path/to/crewvia/install/renewal-reminders.php
 *
 * Running it twice sends nothing twice: one reminder per renewal date. A dedicated installation
 * (commercial_mode 'demo', the RSS install) has no Stripe subscription and sends nothing.
 * The reminder is queued; the 5-minute delivery job sends it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/saas-renewal.php';

if (! saas_configured()) {
    echo 'No Stripe subscription on this installation. Nothing to remind.' . PHP_EOL;
    exit(0);
}
try {
    $sent = saas_renewal_reminders(time());
    echo $sent ? 'Renewal reminder queued.' . PHP_EOL : 'No renewal reminder due.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Renewal reminder failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

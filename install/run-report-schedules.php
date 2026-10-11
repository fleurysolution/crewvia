<?php
/**
 * Run the report schedules that are due (P4-M03), once per slot, and erase
 * delivered files past their 30 days. Meant for cron, every hour:
 *
 *   7 * * * * php /path/to/crewvia/install/run-report-schedules.php
 *
 * Nothing is emailed: each recipient is told in Crewvia. Running it twice
 * in the same hour delivers nothing twice.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/report-builder.php';

$done = rb_run_due(date('Y-m-d H:i:s'));
echo $done ? implode(PHP_EOL, $done) . PHP_EOL : "Report schedules: nothing due.\n";

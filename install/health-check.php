<?php
/**
 * Health check for cron (P4-M07): the same checks as the System health page.
 *
 *   php install/health-check.php            every check, one line each
 *   php install/health-check.php --quiet    nothing when all is well; the
 *                                           failing and doubtful lines otherwise
 *
 * cron mails whatever a job prints, so scheduled with --quiet it mails only
 * when something needs looking at. Exit status: 0 all ok, 1 a check fails,
 * 2 a check needs looking at.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require __DIR__ . '/../app/bootstrap.php';

$quiet = in_array('--quiet', $argv, true);
$started = microtime(true);
$checks = ops_health(true);
$worst = in_array('fail', array_column($checks, 0), true) ? 'fail' : (in_array('warn', array_column($checks, 0), true) ? 'warn' : 'ok');

if (! $quiet || $worst !== 'ok') {
    echo 'Crewvia health on ' . gethostname() . ' (' . ($config['app_url'] ?? '') . '): ' . strtoupper($worst) . PHP_EOL;
    foreach ($checks as $name => [$status, $found]) {
        if (! $quiet || $status !== 'ok') {
            printf("  %-4s %-16s %s\n", strtoupper($status), $name, $found);
        }
    }
}
ops_record('health_check', $worst === 'ok' ? 'ok' : ($worst === 'warn' ? 'warning' : 'failed'),
    array_map(fn($c) => $c[0], array_filter($checks, fn($c) => $c[0] !== 'ok')), $started);
exit(['ok' => 0, 'fail' => 1, 'warn' => 2][$worst]);

<?php
/**
 * System health (P4-M07), for administrators: the same checks cron runs
 * (install/health-check.php), the backups on disk, the record of backups,
 * drills and scheduled jobs, and the errors of the last seven days grouped
 * by the place in the code they come from.
 */

declare(strict_types=1);

require_role('admin');

$checks = ops_health(true);
$backups = array_slice(ops_backups(), 0, 10);
$backupDir = ops_backup_dir();
try {
    $runs = rows('SELECT * FROM ops_runs ORDER BY id DESC LIMIT 30');
} catch (Throwable $e) {
    $runs = [];
}
$errors = ops_recent_errors(7);
$groups = array_slice(ops_error_groups($errors), 0, 50);

render('system-health', compact('checks', 'backups', 'backupDir', 'runs', 'errors', 'groups'));

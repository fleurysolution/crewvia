<?php
/**
 * Takes a backup of this workspace: the database and the documents, with a
 * manifest of fingerprints (P4-M07). See app/backup.php for what is in one.
 *
 *   php install/backup.php                 into backup_dir, or ~/crewvia-backups
 *   php install/backup.php --dir=/path     somewhere else (never under the web root)
 *   php install/backup.php --keep=14       how many to keep (default 14)
 *   php install/backup.php --quiet         print only on failure (for cron, which mails any output)
 *   --mysql-bin-dir=/path                  where mysqldump is, when it is not on the PATH
 *
 * The encryption key is not in the backup. Keep config.php's encryption_key
 * apart, off this server: without it a backup's documents and bank details
 * cannot be read.
 *
 * A backup on this server does not survive losing this server. Copy the
 * folder elsewhere as well (docs/OPERATIONS.md).
 *
 * Exit status 0 on success, 1 on failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/backup.php';

$opts = getopt('', ['dir:', 'keep:', 'quiet', 'mysql-bin-dir:']);
$dir = (string) ($opts['dir'] ?? ops_backup_dir() ?? '');
$keep = max(1, (int) ($opts['keep'] ?? 14));
$quiet = isset($opts['quiet']);
$started = microtime(true);

if ($dir === '') {
    fwrite(STDERR, "Backup FAILED: no backup folder. Set backup_dir in config.php or pass --dir.\n");
    exit(1);
}

$lock = @fopen(rtrim($dir, '/\\') . '.lock', 'c');
if ($lock && ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Backup FAILED: another backup is running.\n");
    exit(1);
}

try {
    $b = backup_create($dir, $opts['mysql-bin-dir'] ?? null);
    $removed = backup_prune($dir, $keep);
    $m = $b['manifest'];
    ops_record('backup', 'ok', ['name' => $m['name'], 'tables' => count($m['tables']), 'documents' => $m['storage_files'],
        'database_bytes' => $m['files']['database.sql.gz']['bytes'], 'storage_bytes' => $m['files']['storage.tar.gz']['bytes'], 'removed' => $removed], $started);
    if (! $quiet) {
        printf("Backup: %s in %s - database %.1f MB (%d tables), documents %.1f MB (%d files), %.1f s; %d kept, %d removed.\n",
            $m['name'], $dir, $m['files']['database.sql.gz']['bytes'] / 1048576, count($m['tables']),
            $m['files']['storage.tar.gz']['bytes'] / 1048576, $m['storage_files'], microtime(true) - $started, count(ops_backups($dir)), count($removed));
        echo "The encryption key is not in the backup: keep config.php's encryption_key off this server. Without it the documents and bank details in a backup cannot be read.\n";
    }
    exit(0);
} catch (Throwable $e) {
    ops_record('backup', 'failed', ['error' => mb_substr($e->getMessage(), 0, 500)], $started);
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

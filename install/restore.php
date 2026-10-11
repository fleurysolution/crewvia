<?php
/**
 * Restores a backup taken by install/backup.php (P4-M07).
 *
 * Every mode first checks the backup is whole: each file against its
 * SHA-256, and the encryption key configured here against the one the
 * backup was taken with. A backup that fails is not restored.
 *
 *   php install/restore.php --check <backup|latest>
 *       only the checks above
 *
 *   php install/restore.php --drill --target-db=NAME <backup|latest>
 *       restore drill: imports the database into NAME, an EMPTY database
 *       that is not this workspace's, compares every table with the
 *       manifest, decrypts a record, extracts and checks every document,
 *       then empties NAME again (--keep-target leaves it). Nothing of the
 *       live workspace is touched. On cPanel, create NAME under "MySQL
 *       Databases" and give the workspace's database user all rights on it.
 *
 *   php install/restore.php --live --confirm=<this workspace's database name> <backup|latest>
 *       disaster recovery: takes a safety backup of what is there now
 *       (--skip-safety-backup when the database is gone), empties the live
 *       database, imports the backup, puts the documents back (the current
 *       folder is kept beside it as storage.before-restore-...), and compares.
 *       Run php install/upgrade.php afterwards when the code is newer than
 *       the backup.
 *
 *   --mysql-bin-dir=/path    where mysql and mysqldump are, when not on the PATH
 *   --dir=/path              where "latest" looks (default: backup_dir, or ~/crewvia-backups)
 *
 * Exit status 0 when the restore matched the backup, 1 otherwise.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/backup.php';

$opts = getopt('', ['check', 'drill', 'live', 'target-db:', 'confirm:', 'keep-target', 'skip-safety-backup', 'mysql-bin-dir:', 'dir:'], $rest);
$which = (string) ($argv[$rest] ?? '');
$bin = $opts['mysql-bin-dir'] ?? null;
$started = microtime(true);
$say = fn(string $line) => print($line . PHP_EOL);
// Failures go to stderr: cron, with stdout sent to /dev/null, still mails them.
$warn = fn(string $line) => fwrite(STDERR, $line . PHP_EOL);
$fail = function (string $why, string $kind = '') use ($started): never {
    if ($kind !== '') { ops_record($kind, 'failed', ['error' => mb_substr($why, 0, 500)], $started); }
    fwrite(STDERR, 'Restore FAILED: ' . $why . PHP_EOL);
    exit(1);
};

$modes = array_values(array_intersect(['check', 'drill', 'live'], array_keys($opts)));
if (count($modes) !== 1 || $which === '') {
    fwrite(STDERR, "Usage: php install/restore.php --check|--drill --target-db=NAME|--live --confirm=DB <backup folder|latest>\n");
    exit(2);
}
$mode = $modes[0];
$kind = $mode === 'drill' ? 'restore_drill' : ($mode === 'live' ? 'restore' : '');

$path = backup_resolve($which, $opts['dir'] ?? null);
if ($path === null) { $fail('no backup at ' . $which . ' (a folder holding manifest.json)', $kind); }
$manifest = json_decode((string) file_get_contents($path . '/manifest.json'), true);

$problems = backup_verify($path);
if ($problems) { $fail(basename($path) . ': ' . implode('; ', $problems), $kind); }
$say('Backup ' . basename($path) . ' is whole: every file matches its fingerprint, and the key is the one it was taken with.');
if ($mode === 'check') { exit(0); }

$live = (string) $config['db_name'];

if ($mode === 'drill') {
    $target = (string) ($opts['target-db'] ?? '');
    if (! preg_match('/^[A-Za-z0-9_]{1,64}$/D', $target)) { $fail('give --target-db, the name of an empty database to restore into', $kind); }
    if ($target === $live) { $fail('the drill never restores into the live database; use --live for a disaster recovery', $kind); }
    try {
        $pdo = backup_pdo($target);
    } catch (Throwable $e) {
        $fail('cannot open the database ' . $target . ': create it first and give this database user all rights on it', $kind);
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() > 0) {
        $fail('the database ' . $target . ' is not empty; a drill restores only into an empty database', $kind);
    }
    try {
        backup_import($path, $target, $bin);
        $diff = backup_compare($pdo, $manifest);
        $decrypt = backup_try_decrypt($pdo);
        $scratch = dirname($path) . '/.drill-' . date('Ymd-His');
        $docProblems = backup_extract_storage($path, $scratch);
        backup_remove_tree($scratch);
        if (! isset($opts['keep-target'])) { backup_empty_database($pdo); }
    } catch (Throwable $e) {
        try { backup_empty_database($pdo); } catch (Throwable $ignored) {}
        $fail($e->getMessage(), $kind);
    }
    $ok = ! $diff && ! $docProblems && ! str_starts_with($decrypt, 'FAILED');
    $detail = ['backup' => basename($path), 'tables' => count($manifest['tables']), 'documents' => $manifest['storage_files'], 'differences' => array_slice($diff, 0, 20),
               'document_problems' => array_slice($docProblems, 0, 20), 'decrypt' => $decrypt, 'target_kept' => isset($opts['keep-target'])];
    ops_record($kind, $ok ? 'ok' : 'failed', $detail, $started);
    $say(sprintf('Drill: %d tables restored into %s%s, %d documents extracted and checked; %s; %.1f s.', count($manifest['tables']), $target,
        isset($opts['keep-target']) ? ' (kept)' : ' and emptied again', $manifest['storage_files'], $decrypt, microtime(true) - $started));
    foreach (array_merge($diff, $docProblems) as $d) { $warn('  DIFFERENT ' . $d); }
    $ok ? $say('Restore drill PASSED: the backup restores to what was backed up.') : $warn('Restore drill FAILED: see the differences above.');
    exit($ok ? 0 : 1);
}

// ── disaster recovery into the live workspace ──
if (($opts['confirm'] ?? '') !== $live) {
    $fail('a live restore replaces everything in ' . $live . '. Repeat its name: --confirm=' . $live);
}
if (! isset($opts['skip-safety-backup'])) {
    try {
        $safety = backup_create(dirname($path), $bin);
        $say('Safety backup of what was there: ' . basename($safety['path']) . '.');
    } catch (Throwable $e) {
        $fail('the safety backup failed (' . $e->getMessage() . '). If the database is gone, add --skip-safety-backup.');
    }
}
$storage = workforce_storage_path($config);
$incoming = $storage . '.restoring';
backup_remove_tree($incoming);
$docProblems = backup_extract_storage($path, $incoming);
if ($docProblems) {
    backup_remove_tree($incoming);
    $fail('the documents in the backup do not check out: ' . implode('; ', array_slice($docProblems, 0, 5)));
}
$pdo = backup_pdo($live);
backup_empty_database($pdo);
try {
    backup_import($path, $live, $bin);
} catch (Throwable $e) {
    backup_remove_tree($incoming);
    $fail($e->getMessage() . '. The live database is now EMPTY and the documents are untouched. '
        . (isset($safety) ? 'What was there is in the safety backup ' . basename($safety['path']) . ': restore it with --live --skip-safety-backup.' : 'No safety backup was taken.'), 'restore');
}
$aside = null;
if (is_dir($storage)) {
    $aside = $storage . '.before-restore-' . date('Ymd-His');
    rename($storage, $aside);
}
rename($incoming, $storage);
// Other workspaces' folders live under the base folder and are not in its backup: they go back.
if ($aside && empty($config['tenant_id']) && is_dir($aside . '/tenants') && ! is_dir($storage . '/tenants')) {
    rename($aside . '/tenants', $storage . '/tenants');
}
$diff = backup_compare($pdo, $manifest);
$decrypt = backup_try_decrypt($pdo);
$ok = ! $diff && ! str_starts_with($decrypt, 'FAILED');
ops_record('restore', $ok ? 'ok' : 'failed', ['backup' => basename($path), 'differences' => array_slice($diff, 0, 20), 'decrypt' => $decrypt, 'previous_documents' => $aside ? basename($aside) : null], $started);
$say(sprintf('Live restore of %s into %s: %d tables, %d documents; %s; %.1f s.', basename($path), $live, count($manifest['tables']), $manifest['storage_files'], $decrypt, microtime(true) - $started));
if ($aside) { $say('The documents folder that was there is kept as ' . basename($aside) . '. Delete it once the restore is confirmed.'); }
foreach ($diff as $d) { $warn('  DIFFERENT ' . $d); }
if (($manifest['commit'] ?? '') !== backup_commit()) {
    $say('The backup was taken with code ' . $manifest['commit'] . '; this is ' . backup_commit() . '. Run php install/upgrade.php now.');
}
$ok ? $say('Live restore PASSED.') : $warn('Live restore FAILED: see the differences above.');
exit($ok ? 0 : 1);

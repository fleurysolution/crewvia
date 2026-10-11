<?php
/**
 * Backup and restore (P4-M07), shared by install/backup.php and
 * install/restore.php.
 *
 * A backup is one folder, crewvia-YYYYMMDD-HHMMSS, holding:
 *   database.sql.gz     mysqldump of the workspace database, with triggers
 *   storage.tar.gz      the documents folder (résumés, contracts, ...)
 *   storage-files.json  every document with its SHA-256
 *   manifest.json       the SHA-256 of the three files above, every table
 *                       with its row count and checksum, the code commit,
 *                       and a check value of the encryption key; signed
 *                       with a key derived from the encryption key
 *
 * The encryption key itself is never in a backup. Without it, the documents
 * and bank details in a backup cannot be read: it is kept apart, offline.
 *
 * Nothing here runs a shell. The database tools are started directly with
 * their arguments, and the password goes to them in a private options file,
 * never on a command line another user could read.
 */

declare(strict_types=1);

require_once __DIR__ . '/gmail.php';

const BACKUP_FORMAT = 1;

/** Host, port or socket from db_host, which may carry DSN parts ("127.0.0.1;port=3308"). */
function backup_db_target(array $config): array
{
    $parts = explode(';', (string) $config['db_host']);
    $out = ['host' => trim($parts[0]) ?: 'localhost', 'port' => null, 'socket' => null];
    foreach (array_slice($parts, 1) as $p) {
        [$k, $v] = array_map('trim', explode('=', $p, 2) + [1 => '']);
        if ($k === 'port') { $out['port'] = (int) $v; }
        if ($k === 'unix_socket') { $out['socket'] = $v; }
    }
    return $out;
}

function backup_pdo(string $dbName): PDO
{
    global $config;
    return new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $dbName), $config['db_user'], $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** A private options file for mysql and mysqldump; the caller deletes it. */
function backup_options_file(): string
{
    global $config;
    $t = backup_db_target($config);
    $quote = fn(string $v): string => '"' . addcslashes($v, "\\\"") . '"';
    $lines = ['[client]', 'user=' . $quote((string) $config['db_user']), 'password=' . $quote((string) $config['db_pass']), 'host=' . $quote($t['host'])];
    if ($t['port']) { $lines[] = 'port=' . $t['port']; }
    if ($t['socket']) { $lines[] = 'socket=' . $quote($t['socket']); }
    $file = tempnam(sys_get_temp_dir(), 'cwv');
    chmod($file, 0600);
    file_put_contents($file, implode("\n", $lines) . "\n");
    return $file;
}

/** mysql or mysqldump: from --mysql-bin-dir, mysql_bin_dir in the configuration, MYSQL_BIN_DIR, or the PATH. */
function backup_tool(string $name, ?string $binDir = null): string
{
    global $config;
    $dir = $binDir ?: ((string) ($config['mysql_bin_dir'] ?? '') ?: (string) (getenv('MYSQL_BIN_DIR') ?: ''));
    $exe = $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    return $dir !== '' ? rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe : $exe;
}

function backup_commit(): string
{
    $git = dirname(__DIR__) . '/.git';
    $head = trim((string) @file_get_contents($git . '/HEAD'));
    if (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        $head = trim((string) @file_get_contents($git . '/' . $ref));
        if ($head === '' && is_file($git . '/packed-refs')) {
            foreach (file($git . '/packed-refs') ?: [] as $line) {
                if (str_ends_with(trim($line), ' ' . $ref)) { $head = strtok($line, ' '); }
            }
        }
    }
    return preg_match('/^[0-9a-f]{40}$/', $head) ? substr($head, 0, 7) : 'unknown';
}

/** A value that says whether two keys are the same, and nothing about the key. */
function backup_key_check(?string $keyB64 = null): string
{
    global $config;
    return substr(hash('sha256', 'crewvia-key-check|' . (string) base64_decode((string) ($keyB64 ?? $config['encryption_key'] ?? ''), true)), 0, 16);
}

/**
 * The manifest's signature: an HMAC under a key derived from the encryption
 * key. The fingerprints catch a damaged or incomplete copy; the signature
 * catches files and manifest changed together, which needs the key.
 */
function backup_signature(array $manifest, ?string $keyB64 = null): string
{
    global $config;
    unset($manifest['signature']);
    $key = hash_hmac('sha256', 'crewvia-backup-manifest', (string) base64_decode((string) ($keyB64 ?? $config['encryption_key'] ?? ''), true), true);
    return hash_hmac('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES), $key);
}

/** Every base table with its exact row count and checksum. */
function backup_table_state(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll() as $r) {
        $t = $r['t'];
        $out[$t] = ['rows' => (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn(),
                    'checksum' => (string) $pdo->query('CHECKSUM TABLE `' . $t . '`')->fetch()['Checksum']];
    }
    return $out;
}

function backup_triggers(PDO $pdo): array
{
    return array_column($pdo->query('SELECT trigger_name AS n FROM information_schema.triggers WHERE trigger_schema = DATABASE() ORDER BY trigger_name')->fetchAll(), 'n');
}

/** The documents folder to back up; the base folder leaves other workspaces' folders out. */
function backup_storage_files(string $storage, bool $skipTenants): array
{
    $files = [];
    if (! is_dir($storage)) {
        return $files;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (! $f->isFile()) { continue; }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($storage) + 1));
        if ($skipTenants && str_starts_with($rel, 'tenants/')) { continue; }
        if (preg_match('#(^|/)\.health-#', $rel)) { continue; }
        $files[$rel] = ['sha256' => hash_file('sha256', $f->getPathname()), 'bytes' => $f->getSize()];
    }
    ksort($files);
    return $files;
}

function backup_file_entry(string $path): array
{
    return ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
}

/** Runs a database tool with stdin from $in (callable writing chunks) or stdout to $out (callable reading chunks). */
function backup_run_tool(array $command, ?callable $feed, ?callable $drain): array
{
    $err = tempnam(sys_get_temp_dir(), 'cwe');
    $spec = [0 => $feed ? ['pipe', 'r'] : ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $drain ? ['pipe', 'w'] : ['file', $err . '.out', 'w'], 2 => ['file', $err, 'w']];
    $proc = proc_open($command, $spec, $pipes);
    if (! is_resource($proc)) {
        @unlink($err);
        return [127, 'could not start ' . basename($command[0])];
    }
    if ($feed) { $feed($pipes[0]); fclose($pipes[0]); }
    if ($drain) { $drain($pipes[1]); fclose($pipes[1]); }
    $code = proc_close($proc);
    $message = trim((string) @file_get_contents($err));
    @unlink($err);
    @unlink($err . '.out');
    return [$code, $message];
}

/** Deletes a folder and everything in it. */
function backup_remove_tree(string $dir): void
{
    if (! is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}

/**
 * Takes a backup into $dir. Returns ['path' => ..., 'manifest' => ...] or throws
 * RuntimeException with what went wrong; a failed backup leaves no folder behind.
 */
function backup_create(string $dir, ?string $binDir = null): array
{
    global $config;
    if (! extension_loaded('phar') || ! extension_loaded('zlib')) {
        throw new RuntimeException('PHP needs the phar and zlib extensions to archive the documents.');
    }
    if (($why = ops_backup_dir_refusal($dir)) !== null) {
        throw new RuntimeException($why);
    }
    if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
        throw new RuntimeException('Cannot create the backup folder ' . $dir . '.');
    }
    $started = microtime(true);
    $name = 'crewvia-' . date('Ymd-His') . (empty($config['tenant_id']) ? '' : '-' . $config['tenant_id']);
    $path = $dir . '/' . $name;
    if (is_dir($path)) {
        throw new RuntimeException('A backup named ' . $name . ' already exists.');
    }
    mkdir($path, 0700);
    try {
        $pdo = backup_pdo((string) $config['db_name']);
        $tables = backup_table_state($pdo);
        $triggers = backup_triggers($pdo);

        // The database, streamed through gzip.
        $options = backup_options_file();
        $tail = '';
        try {
            $gz = gzopen($path . '/database.sql.gz', 'wb6');
            [$code, $message] = backup_run_tool([backup_tool('mysqldump', $binDir), '--defaults-extra-file=' . $options, '--single-transaction', '--quick', '--routines', '--triggers',
                '--hex-blob', '--default-character-set=utf8mb4', '--no-tablespaces', (string) $config['db_name']], null,
                function ($out) use ($gz, &$tail): void {
                    while (! feof($out)) {
                        $chunk = (string) fread($out, 1 << 20);
                        gzwrite($gz, $chunk);
                        $tail = substr($tail . $chunk, -200);
                    }
                });
            gzclose($gz);
        } finally {
            @unlink($options);
        }
        if ($code !== 0 || ! str_contains($tail, '-- Dump completed')) {
            throw new RuntimeException('mysqldump failed (exit ' . $code . '): ' . mb_substr($message, 0, 400));
        }

        // The documents.
        $storage = workforce_storage_path($config);
        $files = backup_storage_files($storage, empty($config['tenant_id']));
        // Built in one pass: adding files one by one rewrites the archive each time.
        file_put_contents($path . '/CREWVIA-BACKUP.txt', 'Documents of ' . $name . "\n");
        $map = ['CREWVIA-BACKUP.txt' => $path . '/CREWVIA-BACKUP.txt'];
        foreach (array_keys($files) as $rel) {
            $map['files/' . $rel] = $storage . '/' . $rel;
        }
        $tar = new PharData($path . '/storage.tar');
        $tar->buildFromIterator(new ArrayIterator($map));
        $tar->compress(Phar::GZ);
        unset($tar);
        Phar::unlinkArchive($path . '/storage.tar');
        unlink($path . '/CREWVIA-BACKUP.txt');
        file_put_contents($path . '/storage-files.json', json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $manifest = [
            'format' => BACKUP_FORMAT,
            'name' => $name,
            'created_at' => date('c', (int) $started),
            'seconds' => round(microtime(true) - $started, 2),
            'database' => (string) $config['db_name'],
            'tenant' => (string) ($config['tenant_id'] ?? ''),
            'server_version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
            'php_version' => PHP_VERSION,
            'commit' => backup_commit(),
            'key_check' => backup_key_check(),
            'storage_files' => count($files),
            'files' => [
                'database.sql.gz' => backup_file_entry($path . '/database.sql.gz'),
                'storage.tar.gz' => backup_file_entry($path . '/storage.tar.gz'),
                'storage-files.json' => backup_file_entry($path . '/storage-files.json'),
            ],
            'tables' => $tables,
            'triggers' => $triggers,
            'note' => 'Row counts and checksums are read just before the dump; a change made during the backup can make one differ.',
        ];
        $manifest['signature'] = backup_signature($manifest);
        file_put_contents($path . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        foreach (glob($path . '/*') ?: [] as $f) { @chmod($f, 0600); }
        return ['path' => $path, 'manifest' => $manifest];
    } catch (Throwable $e) {
        backup_remove_tree($path);
        throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
    }
}

/** Keeps the newest $keep backups in $dir; returns the names removed. */
function backup_prune(string $dir, int $keep): array
{
    $removed = [];
    foreach (array_slice(ops_backups($dir), max(1, $keep)) as $b) {
        backup_remove_tree($b['path']);
        $removed[] = basename($b['path']);
    }
    return $removed;
}

/** A backup folder by path, or the newest in the backup folder for "latest". */
function backup_resolve(string $which, ?string $dir = null): ?string
{
    if ($which === 'latest') {
        $all = ops_backups($dir);
        return $all ? $all[0]['path'] : null;
    }
    return is_file($which . '/manifest.json') ? rtrim($which, '/\\') : null;
}

/** Problems with a backup before anything is restored from it; empty when it is whole. */
function backup_verify(string $path): array
{
    global $config;
    $problems = [];
    $manifest = json_decode((string) @file_get_contents($path . '/manifest.json'), true);
    if (! is_array($manifest) || ($manifest['format'] ?? null) !== BACKUP_FORMAT) {
        return ['manifest.json is missing or not a Crewvia backup manifest'];
    }
    foreach ($manifest['files'] as $name => $want) {
        if (! is_file($path . '/' . $name)) {
            $problems[] = $name . ' is missing';
        } elseif (! hash_equals((string) $want['sha256'], hash_file('sha256', $path . '/' . $name))) {
            $problems[] = $name . ' does not match its fingerprint: altered or incomplete';
        }
    }
    // The key it was taken with: the current one, or the previous one during or
    // after a rotation (GAP-1002), set back as previous_encryption_key.
    $keyUsed = null;
    foreach (['encryption_key', 'previous_encryption_key'] as $name) {
        if (! empty($config[$name]) && ($manifest['key_check'] ?? '') === backup_key_check((string) $config[$name])) {
            $keyUsed = (string) $config[$name];
            break;
        }
    }
    if ($keyUsed === null) {
        $problems[] = 'the encryption key configured here is not the one this backup was taken with: its documents and bank details could not be read. Put the original key in config.php first (as previous_encryption_key, if the key was rotated since)';
    } elseif (! hash_equals(backup_signature($manifest, $keyUsed), (string) ($manifest['signature'] ?? ''))) {
        $problems[] = 'manifest.json is not signed by this workspace: it was changed after the backup was taken';
    }
    return $problems;
}

/** Imports a verified backup's database into $dbName, which the caller has emptied or checked empty. */
function backup_import(string $path, string $dbName, ?string $binDir = null): void
{
    $options = backup_options_file();
    try {
        [$code, $message] = backup_run_tool([backup_tool('mysql', $binDir), '--defaults-extra-file=' . $options, '--default-character-set=utf8mb4', $dbName],
            function ($in) use ($path): void {
                $gz = gzopen($path . '/database.sql.gz', 'rb');
                while (! gzeof($gz)) { fwrite($in, (string) gzread($gz, 1 << 20)); }
                gzclose($gz);
            }, null);
    } finally {
        @unlink($options);
    }
    if ($code !== 0) {
        throw new RuntimeException('The database import failed (exit ' . $code . '): ' . mb_substr($message, 0, 400));
    }
}

/** Drops every table of $dbName (and with them their triggers). */
function backup_empty_database(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
        $pdo->exec(($r['k'] === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . $r['t'] . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** How a restored database differs from the manifest; empty when it is the same. */
function backup_compare(PDO $pdo, array $manifest): array
{
    $now = backup_table_state($pdo);
    $diff = [];
    foreach (array_diff_key($manifest['tables'], $now) as $t => $_) { $diff[] = $t . ': missing'; }
    foreach (array_diff_key($now, $manifest['tables']) as $t => $_) { $diff[] = $t . ': not in the backup'; }
    foreach (array_intersect_key($manifest['tables'], $now) as $t => $want) {
        if ($now[$t]['rows'] !== $want['rows']) {
            $diff[] = $t . ': ' . $now[$t]['rows'] . ' rows, the backup has ' . $want['rows'];
        } elseif ($now[$t]['checksum'] !== $want['checksum']) {
            $diff[] = $t . ': same rows, different content';
        }
    }
    $missingTriggers = array_diff($manifest['triggers'] ?? [], backup_triggers($pdo));
    foreach ($missingTriggers as $tr) { $diff[] = 'trigger ' . $tr . ': missing'; }
    return $diff;
}

/** Decrypts one encrypted record of the restored database with the key configured here. */
function backup_try_decrypt(PDO $pdo): string
{
    foreach (['worker_bank_details', 'worker_bank_change_requests'] as $t) {
        try {
            $v = $pdo->query('SELECT encrypted_details FROM `' . $t . '` LIMIT 1')->fetchColumn();
        } catch (Throwable $e) {
            continue;
        }
        if ($v) {
            try {
                return token_decrypt((string) $v) ? 'an encrypted bank record decrypts with the configured key' : 'FAILED: an encrypted bank record did not decrypt';
            } catch (Throwable $e) {
                return 'FAILED: an encrypted bank record did not decrypt (' . $e->getMessage() . ')';
            }
        }
    }
    return 'no encrypted record to try';
}

/**
 * Extracts the documents into $dest (which must not exist) and checks each
 * against storage-files.json. Returns the problems found.
 */
function backup_extract_storage(string $path, string $dest): array
{
    $list = json_decode((string) file_get_contents($path . '/storage-files.json'), true) ?: [];
    $tmp = $dest . '.extract';
    backup_remove_tree($tmp);
    mkdir($tmp, 0700, true);
    $tar = new PharData($path . '/storage.tar.gz');
    $names = [];
    foreach (new RecursiveIteratorIterator($tar) as $entry) {
        $full = str_replace('\\', '/', $entry->getPathname());
        $names[] = substr($full, strpos($full, 'storage.tar.gz/') + strlen('storage.tar.gz/'));
    }
    $problems = [];
    foreach ($names as $n) {
        if ($n !== 'CREWVIA-BACKUP.txt' && (! str_starts_with($n, 'files/') || str_contains($n, '..') || ! isset($list[substr($n, 6)]))) {
            $problems[] = 'unexpected entry in the archive: ' . $n;
        }
    }
    if ($problems) {
        backup_remove_tree($tmp);
        return $problems;
    }
    $tar->extractTo($tmp, null, true);
    unset($tar);
    mkdir($dest, 0700, true);
    foreach ($list as $rel => $want) {
        $from = $tmp . '/files/' . $rel;
        if (! is_file($from) || ! hash_equals($want['sha256'], hash_file('sha256', $from))) {
            $problems[] = $rel . ': missing or different';
            continue;
        }
        if (! is_dir(dirname($dest . '/' . $rel))) { mkdir(dirname($dest . '/' . $rel), 0700, true); }
        rename($from, $dest . '/' . $rel);
    }
    backup_remove_tree($tmp);
    return $problems;
}

<?php
/**
 * Rotates the workspace encryption key (GAP-1002). See app/key-rotation.php.
 *
 *   php install/rotate-key.php --check
 *       counts every encrypted value and document by the key that opens it;
 *       fails if any opens with neither
 *
 *   php install/rotate-key.php --start [--dir=/backups] [--limit=N]
 *       1. checks that everything opens with the current key
 *       2. takes a backup (into backup_dir, ~/crewvia-backups, or --dir)
 *       3. writes a new random key into config.php, keeping the current one
 *          as previous_encryption_key: the site keeps working throughout
 *       4. re-encrypts every value and document with the new key
 *          (--limit=N: at most N this run; run --resume for the rest)
 *       5. when nothing needs the old key any more, removes it from config.php
 *
 *   php install/rotate-key.php --resume [--limit=N]
 *       continues an interrupted or limited rotation (steps 4 and 5)
 *
 * Neither key is ever printed. Copy the new key from config.php into the
 * password manager afterwards, and keep the old one with the backups taken
 * before the rotation: to restore one of those, set the old key as
 * previous_encryption_key.
 *
 * Exit status: 0 done, 1 refused or failed, 3 rotation still under way.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/backup.php';
require_once __DIR__ . '/../app/key-rotation.php';

$opts = getopt('', ['check', 'start', 'resume', 'dir:', 'limit:', 'mysql-bin-dir:']);
$modes = array_values(array_intersect(['check', 'start', 'resume'], array_keys($opts)));
if (count($modes) !== 1) {
    fwrite(STDERR, "Usage: php install/rotate-key.php --check | --start [--dir=PATH] [--limit=N] | --resume [--limit=N]\n");
    exit(2);
}
$mode = $modes[0];
$limit = max(0, (int) ($opts['limit'] ?? 0));
$started = microtime(true);
$fail = function (string $why) use ($mode, $started): never {
    if ($mode !== 'check') { ops_record('key_rotation', 'failed', ['step' => $mode, 'error' => mb_substr($why, 0, 500)], $started); }
    fwrite(STDERR, 'Key rotation FAILED: ' . $why . PHP_EOL);
    exit(1);
};
$report = function (array $scan): void {
    foreach ($scan as $store => $s) {
        printf("  %-46s %6d current key  %6d previous key  %6d unreadable%s\n", $store, $s['current'], $s['previous'], $s['unreadable'],
               $s['unreadable_ids'] ? '  (' . implode(', ', array_slice($s['unreadable_ids'], 0, 5)) . (count($s['unreadable_ids']) > 5 ? ', ...' : '') . ')' : '');
    }
};

if (! crypto_key_ready()) { $fail('no valid 32-byte encryption_key is configured.'); }

if ($mode === 'check') {
    $scan = kr_scan();
    [$cur, $prev, $bad] = kr_totals($scan);
    echo "Encrypted values and documents, by the key that opens them:\n";
    $report($scan);
    printf("Total: %d with the current key, %d with the previous key, %d unreadable.\n", $cur, $prev, $bad);
    if ($bad) { fwrite(STDERR, "Key rotation check FAILED: some values open with no configured key (listed above). Resolve them before rotating.\n"); exit(1); }
    if ($prev) { echo "A rotation is under way: run php install/rotate-key.php --resume.\n"; exit(3); }
    echo "Everything opens with the current key.\n";
    exit(0);
}

try {
    $configFile = kr_config_file();
} catch (Throwable $e) {
    $fail($e->getMessage());
}

if ($mode === 'start') {
    if (! empty($config['previous_encryption_key'])) {
        $fail('a rotation is already under way (previous_encryption_key is set): run --resume.');
    }
    $scan = kr_scan();
    [$cur, , $bad] = kr_totals($scan);
    if ($bad) {
        $report($scan);
        $fail($bad . ' value(s) or document(s) open with no configured key (listed above); nothing was changed.');
    }
    $dir = (string) ($opts['dir'] ?? ops_backup_dir() ?? '');
    if ($dir === '') { $fail('no backup folder: set backup_dir in config.php or pass --dir. A rotation never starts without a backup.'); }
    try {
        $b = backup_create($dir, $opts['mysql-bin-dir'] ?? null);
    } catch (Throwable $e) {
        $fail('the backup before the rotation failed: ' . $e->getMessage() . '. Nothing was changed.');
    }
    echo 'Backup before the rotation: ' . $b['manifest']['name'] . ' (taken with the current key).' . PHP_EOL;
    $old = (string) $config['encryption_key'];
    $new = base64_encode(random_bytes(32));
    try {
        kr_write_config($configFile, $new, $old);
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }
    $config['encryption_key'] = $new;
    $config['previous_encryption_key'] = $old;
    log_activity('started an encryption key rotation', 'encryption_key', 0, $cur . ' values and documents to re-encrypt; backup ' . $b['manifest']['name']);
    echo "New key written to config.php; the previous one is kept beside it until the rotation is complete. The site keeps working.\n";
}

// ── re-encrypt, then finish ──
if (empty($config['previous_encryption_key'])) {
    $fail('no rotation is under way (previous_encryption_key is not set). Use --start.');
}
try {
    [$done, $raced] = kr_reencrypt($limit);
} catch (Throwable $e) {
    $fail($e->getMessage() . ' Run --resume once it is resolved: the site keeps working with both keys meanwhile.');
}
$scan = kr_scan();
[$cur, $prev, $bad] = kr_totals($scan);
printf("Re-encrypted %d value(s) and document(s) with the new key this run%s.\n", $done, $raced ? sprintf(', %d skipped because they changed meanwhile', $raced) : '');
if ($prev || $bad) {
    $report($scan);
    ops_record('key_rotation', 'warning', ['re_encrypted' => $done, 'remaining' => $prev, 'unreadable' => $bad], $started);
    echo sprintf("%d still need the previous key%s. Run php install/rotate-key.php --resume; the site keeps working with both keys meanwhile.\n",
                 $prev, $bad ? sprintf(' and %d open with neither', $bad) : '');
    exit(3);
}
try {
    kr_write_config($configFile, (string) $config['encryption_key'], null);
} catch (Throwable $e) {
    $fail('everything is re-encrypted, but removing the previous key failed: ' . $e->getMessage());
}
log_activity('completed an encryption key rotation', 'encryption_key', 0, $cur . ' values and documents now under the new key');
ops_record('key_rotation', 'ok', ['values_and_documents' => $cur, 're_encrypted_this_run' => $done], $started);
printf("Rotation complete: all %d encrypted values and documents open with the new key alone, and the previous key is removed from config.php (%.1f s).\n", $cur, microtime(true) - $started);
echo "Now: copy the new encryption_key from config.php into the password manager. Keep the OLD key with the backups taken before today: to restore one, set it as previous_encryption_key.\n";
exit(0);

<?php
/**
 * Encryption key rotation (GAP-1002), for install/rotate-key.php.
 *
 * What is encrypted with the workspace key:
 *  - every database column named encrypted_* (found in the schema, so a
 *    table added later is covered): bank details, two-step secrets, OAuth
 *    tokens, queued mail, import sources;
 *  - the document files under storage: resumes/, contracts/, documents/.
 *
 * A rotation never leaves the workspace unable to read anything. The new
 * key is configured first, with the old one kept as previous_encryption_key
 * (crypto_open() reads with either, everything new is written with the new
 * one); then each value and file is re-encrypted, compare-and-set so a
 * change made meanwhile by the application is never overwritten; only when
 * nothing needs the old key any more is it removed from the configuration.
 * Interrupted, it is simply run again.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

const KR_FILE_STORES = ['resumes', 'contracts', 'documents'];

/** [table, column, primary key columns] for every encrypted_* column. */
function kr_columns(): array
{
    $out = [];
    foreach (rows("SELECT c.table_name AS t, c.column_name AS c FROM information_schema.columns c
                     JOIN information_schema.tables x ON x.table_schema = c.table_schema AND x.table_name = c.table_name AND x.table_type = 'BASE TABLE'
                    WHERE c.table_schema = DATABASE() AND c.column_name LIKE 'encrypted\\_%' ORDER BY c.table_name, c.column_name") as $r) {
        $pk = array_column(rows("SELECT column_name AS c FROM information_schema.key_column_usage
                                  WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ordinal_position", [$r['t']]), 'c');
        if (! $pk) {
            throw new RuntimeException('Table ' . $r['t'] . ' has no primary key: its encrypted values cannot be rotated safely.');
        }
        $out[] = [$r['t'], $r['c'], $pk];
    }
    return $out;
}

/**
 * The non-empty values of one encrypted column, 500 at a time, in primary
 * key order (keyset paging, so rows changed meanwhile are neither skipped
 * nor read twice). Each row: ['key' => [pk column => value], 'v' => value].
 */
function kr_values(string $table, string $column, array $pk): Generator
{
    $cols = implode(', ', array_map(fn($c) => "`$c`", $pk));
    $after = null;
    do {
        $where = "`$column` IS NOT NULL AND `$column` <> ''" . ($after !== null ? ' AND (' . $cols . ') > (' . implode(', ', array_fill(0, count($pk), '?')) . ')' : '');
        $batch = rows("SELECT $cols, `$column` AS __v FROM `$table` WHERE $where ORDER BY $cols LIMIT 500", $after ?? []);
        foreach ($batch as $row) {
            $key = array_intersect_key($row, array_flip($pk));
            $after = array_values($key);
            yield ['key' => $key, 'v' => (string) $row['__v']];
        }
    } while (count($batch) === 500);
}

/** Every document file, store => [path...]. */
function kr_files(): array
{
    global $config;
    $out = [];
    foreach (KR_FILE_STORES as $store) {
        $dir = workforce_storage_path($config) . '/' . $store;
        $out[$store] = is_dir($dir) ? array_values(array_filter(glob($dir . '/*') ?: [], 'is_file')) : [];
    }
    return $out;
}

/** 'current', 'previous' or 'unreadable'. */
function kr_classify(string $raw, ?string $current, ?string $previous): string
{
    if ($current !== null && crypto_open_with($raw, $current) !== null) { return 'current'; }
    if ($previous !== null && crypto_open_with($raw, $previous) !== null) { return 'previous'; }
    return 'unreadable';
}

function kr_keys(): array
{
    global $config;
    return [crypto_raw_key($config['encryption_key'] ?? null), crypto_raw_key($config['previous_encryption_key'] ?? null)];
}

/**
 * Every encrypted value and file, counted by the key that opens it:
 * [store => ['current' => n, 'previous' => n, 'unreadable' => n, 'unreadable_ids' => [...]]].
 */
function kr_scan(): array
{
    [$current, $previous] = kr_keys();
    $out = [];
    $tally = function (string $store, string $kind, string $id) use (&$out): void {
        $out[$store] ??= ['current' => 0, 'previous' => 0, 'unreadable' => 0, 'unreadable_ids' => []];
        $out[$store][$kind]++;
        if ($kind === 'unreadable' && count($out[$store]['unreadable_ids']) < 20) { $out[$store]['unreadable_ids'][] = $id; }
    };
    foreach (kr_columns() as [$table, $column, $pk]) {
        $out[$table . '.' . $column] ??= ['current' => 0, 'previous' => 0, 'unreadable' => 0, 'unreadable_ids' => []];
        foreach (kr_values($table, $column, $pk) as $r) {
            $raw = base64_decode($r['v'], true);
            $tally($table . '.' . $column, $raw === false ? 'unreadable' : kr_classify($raw, $current, $previous), implode('/', $r['key']));
        }
    }
    foreach (kr_files() as $store => $paths) {
        $out['files/' . $store] ??= ['current' => 0, 'previous' => 0, 'unreadable' => 0, 'unreadable_ids' => []];
        foreach ($paths as $path) {
            $raw = file_get_contents($path);
            $tally('files/' . $store, $raw === false ? 'unreadable' : kr_classify($raw, $current, $previous), basename($path));
        }
    }
    return $out;
}

/** Totals of a scan: [current, previous, unreadable]. */
function kr_totals(array $scan): array
{
    return [array_sum(array_column($scan, 'current')), array_sum(array_column($scan, 'previous')), array_sum(array_column($scan, 'unreadable'))];
}

/**
 * Re-encrypts with the current key whatever only the previous key opens.
 * $limit > 0 stops after that many. Returns [re-encrypted, skipped because changed meanwhile].
 */
function kr_reencrypt(int $limit = 0): array
{
    [$current, $previous] = kr_keys();
    if ($current === null || $previous === null) {
        throw new RuntimeException('No rotation is under way: both encryption_key and previous_encryption_key must be configured.');
    }
    $done = 0;
    $raced = 0;
    foreach (kr_columns() as [$table, $column, $pk]) {
        $match = implode(' AND ', array_map(fn($c) => "`$c` = ?", $pk));
        foreach (kr_values($table, $column, $pk) as $r) {
            $raw = base64_decode($r['v'], true);
            if ($raw === false || crypto_open_with($raw, $current) !== null || ($plain = crypto_open_with($raw, $previous)) === null) {
                continue;
            }
            // Compare-and-set: if the application changed the value meanwhile, it wrote it with the new key already.
            $n = q("UPDATE `$table` SET `$column` = ? WHERE $match AND `$column` = ?", [base64_encode(crypto_seal($plain, $current)), ...array_values($r['key']), $r['v']])->rowCount();
            $n ? $done++ : $raced++;
            if ($limit > 0 && $done >= $limit) { return [$done, $raced]; }
        }
    }
    foreach (kr_files() as $paths) {
        foreach ($paths as $path) {
            $raw = file_get_contents($path);
            if ($raw === false || crypto_open_with($raw, $current) !== null || ($plain = crypto_open_with($raw, $previous)) === null) {
                continue;
            }
            // Written beside it and renamed over it: a reader sees the old file or the new one, never half of one.
            $tmp = $path . '.rotating';
            if (file_put_contents($tmp, crypto_seal($plain, $current), LOCK_EX) === false || ! rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Could not rewrite ' . basename(dirname($path)) . '/' . basename($path) . '.');
            }
            $done++;
            if ($limit > 0 && $done >= $limit) { return [$done, $raced]; }
        }
    }
    return [$done, $raced];
}

/** The configuration file that holds this workspace's key. */
function kr_config_file(): string
{
    global $config;
    if (! empty($config['tenant_id'])) {
        throw new RuntimeException('This is the workspace ' . $config['tenant_id'] . ', configured under tenants/: rotating a workspace key there is not supported yet. Only the main config.php key is rotated by this tool.');
    }
    $file = dirname(__DIR__) . '/config.php';
    if (! is_file($file) || ! is_writable($file)) {
        throw new RuntimeException('config.php is missing or not writable by this user.');
    }
    return $file;
}

/**
 * Sets encryption_key to $new and previous_encryption_key to $previous (or
 * removes it), in place, keeping every other line. Written to a temporary
 * file and renamed over the original, then read back by a separate PHP: if
 * anything other than those two values differs, the original is put back.
 */
function kr_write_config(string $file, string $new, ?string $previous): void
{
    $original = (string) file_get_contents($file);
    $before = kr_read_config($file);
    $text = preg_replace('/,?\s*([\'"])previous_encryption_key\1\s*=>\s*([\'"])[A-Za-z0-9+\/=]*\2/', '', $original, -1, $removed);
    if ($removed > 1) {
        throw new RuntimeException('config.php names previous_encryption_key more than once: correct it by hand first.');
    }
    $text = preg_replace_callback('/([\'"])encryption_key\1(\s*=>\s*)([\'"])[A-Za-z0-9+\/=]*\3/',
        fn($m) => "'encryption_key'" . $m[2] . "'" . $new . "'" . ($previous !== null ? ", 'previous_encryption_key'" . $m[2] . "'" . $previous . "'" : ''),
        $text, -1, $found);
    if ($found !== 1) {
        throw new RuntimeException('config.php does not hold exactly one encryption_key entry written as \'encryption_key\' => \'...\': change it by hand.');
    }
    $tmp = $file . '.rotating';
    file_put_contents($tmp, $text, LOCK_EX);
    @chmod($tmp, fileperms($file) & 0777);
    if (! rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace config.php.');
    }
    $after = kr_read_config($file);
    $expected = $before;
    unset($expected['previous_encryption_key']);
    $expected['encryption_key'] = $new;
    if ($previous !== null) { $expected['previous_encryption_key'] = $previous; }
    ksort($expected);
    ksort($after);
    if ($after !== $expected) {
        file_put_contents($file, $original, LOCK_EX);
        throw new RuntimeException('config.php did not read back as expected after the change; the original was put back.');
    }
}

/** config.php as a separate PHP process reads it. */
function kr_read_config(string $file): array
{
    $p = proc_open([PHP_BINARY, '-r', 'echo json_encode(require $argv[1]);', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    $cfg = json_decode((string) $out, true);
    if (! is_array($cfg)) {
        throw new RuntimeException('config.php could not be read back.');
    }
    return $cfg;
}

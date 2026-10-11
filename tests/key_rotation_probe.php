<?php
/**
 * The key rotation test's eyes, on the test database only.
 *   digest              every encrypted value and document, as the SHA-256 of what it decrypts to
 *   opens <base64 key>  how many values and documents that one key opens
 *   documents           a stored resume and a stored proof, with their recorded fingerprints
 *   reset-login <email> forgets that account's sign-in count (many suites sign in as it)
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/key-rotation.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$each = function (callable $fn): void {
    foreach (kr_columns() as [$table, $column, $pk]) {
        foreach (kr_values($table, $column, $pk) as $r) {
            $fn($table . '.' . $column . '#' . implode('/', $r['key']), (string) base64_decode($r['v'], true));
        }
    }
    foreach (kr_files() as $store => $paths) {
        foreach ($paths as $path) {
            $fn('files/' . $store . '/' . basename($path), (string) file_get_contents($path));
        }
    }
};

$mode = (string) ($argv[1] ?? 'digest');
if ($mode === 'digest') {
    $out = [];
    $each(function (string $id, string $raw) use (&$out): void {
        $plain = crypto_open($raw);
        $out[$id] = $plain === null ? null : hash('sha256', $plain);
    });
    echo json_encode($out);
} elseif ($mode === 'opens') {
    $key = crypto_raw_key((string) $argv[2]);
    $n = 0;
    $each(function (string $id, string $raw) use (&$n, $key): void {
        if (crypto_open_with($raw, $key) !== null) { $n++; }
    });
    echo json_encode($n);
} elseif ($mode === 'reset-login') {
    q('DELETE FROM auth_rate_limits WHERE bucket = ?', [hash('sha256', 'login-email|' . mb_strtolower((string) $argv[2]))]);
    echo json_encode(true);
} elseif ($mode === 'documents') {
    $stored = fn(string $dir, array $r) => is_file(workforce_storage_path($config) . '/' . $dir . '/' . $r['storage_name']);
    $resume = null;
    foreach (rows('SELECT id, storage_name, file_hash FROM candidate_resumes ORDER BY id') as $r) { if ($stored('resumes', $r)) { $resume = $r; break; } }
    $proof = null;
    foreach (rows('SELECT id, storage_name, file_hash FROM worker_documents ORDER BY id') as $r) { if ($stored('documents', $r)) { $proof = $r; break; } }
    echo json_encode(['resume' => $resume, 'proof' => $proof]);
}

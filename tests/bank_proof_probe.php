<?php
/**
 * The P5-M63 test's eyes, on the test database only: the bank change requests, with their proof, whether
 * the file on disk is readable as plain bytes (it must not be), and how many times a proof was looked at.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
global $config;
$dir = workforce_storage_path($config) . '/bank-proofs/';
$out = [];
foreach (rows('SELECT id, candidate_id, last_four, status, proof_storage_name, proof_mime FROM worker_bank_change_requests ORDER BY id') as $r) {
    $file = $r['proof_storage_name'] ? @file_get_contents($dir . $r['proof_storage_name']) : false;
    $out[] = ['id' => (int) $r['id'], 'last_four' => $r['last_four'], 'status' => $r['status'], 'proof_mime' => $r['proof_mime'],
              'proof_on_disk_plain' => $file === false ? null : str_starts_with($file, "\x89PNG"),
              'proof_views' => (int) val("SELECT COUNT(*) FROM worker_bank_access WHERE candidate_id = ? AND action = 'viewed the proof'", [(int) $r['candidate_id']])];
}
echo json_encode($out);

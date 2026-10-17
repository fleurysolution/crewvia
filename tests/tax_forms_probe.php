<?php
/**
 * The P5-M64 test's eyes, on the test database only: the walkthrough worker's project, the form tasks,
 * the forms signed (whether a number can be read in what is stored, whether the hash still matches),
 * who opened them, the employment record, the bank requests and what the worker was told.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/crypto.php';
global $config;

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$uid = (int) val("SELECT id FROM users WHERE email = 'wk-w@test.invalid'");
$cid = (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [$uid]);
$job = (int) val('SELECT job_id FROM placements WHERE candidate_id = ? ORDER BY id LIMIT 1', [$cid]);
$secrets = array_filter(explode(',', (string) ($argv[1] ?? '')));
$forms = [];
foreach (rows('SELECT * FROM worker_tax_forms WHERE candidate_id = ? ORDER BY id', [$cid]) as $f) {
    $json = crypto_open((string) base64_decode((string) $f['encrypted_payload'], true));
    $forms[] = ['id' => (int) $f['id'], 'form' => $f['form'], 'status' => $f['status'], 'ssn_last_four' => $f['ssn_last_four'], 'signer' => $f['signer_name'],
                'secret_in_storage' => (bool) array_filter($secrets, static fn($s) => str_contains((string) $f['encrypted_payload'], $s)),
                'hash_ok' => $json !== null && hash_equals((string) $f['payload_hash'], hash('sha256', $json)),
                'data' => $json !== null ? json_decode($json, true) : null,
                'employer_raw' => (string) ($f['encrypted_employer'] ?? ''),
                'witnessed' => (bool) $f['witness_id'], 'has_signature' => ! empty($f['encrypted_signature']),
                'everify' => $f['everify_case'] ? [$f['everify_case'], $f['everify_result']] : null,
                'access' => array_column(rows('SELECT action FROM worker_tax_form_access WHERE form_id = ? ORDER BY id', [(int) $f['id']]), 'action')];
}
echo json_encode([
    'cid' => $cid, 'job' => $job,
    'requirements' => rows('SELECT id, title, form_kind FROM onboarding_requirements WHERE job_id = ? ORDER BY id', [$job]),
    'tasks' => rows('SELECT t.id, r.form_kind AS kind, t.status, t.response FROM onboarding_tasks t JOIN onboarding_requirements r ON r.id = t.requirement_id JOIN placements p ON p.id = t.placement_id WHERE p.candidate_id = ? ORDER BY t.id', [$cid]),
    'forms' => $forms,
    'clearance' => row('SELECT w4_status, i9_status, w4_form_id, i9_form_id, first_day, review_due_on FROM employment_clearance WHERE candidate_id = ?', [$cid]),
    'bank' => rows('SELECT id, status, last_four FROM worker_bank_change_requests WHERE candidate_id = ? ORDER BY id', [$cid]),
    'bank_verified' => (bool) val("SELECT COUNT(*) FROM worker_bank_details WHERE candidate_id = ? AND status = 'verified'", [$cid]),
    'copies' => array_map(static function ($k) use ($config) {
        $raw = @file_get_contents(workforce_storage_path($config) . '/documents/' . $k['storage_name']);
        return ['list' => $k['list'], 'title' => $k['title'], 'by_office' => (int) $k['by_office'], 'document_id' => (int) $k['document_id'], 'restricted' => (int) $k['restricted'],
                'doc_status' => $k['status'], 'plain_on_disk' => $raw !== false && str_starts_with($raw, "\x89PNG")];
    }, rows('SELECT k.*, d.restricted, d.status, d.storage_name FROM i9_document_copies k JOIN worker_documents d ON d.id = k.document_id WHERE k.candidate_id = ? ORDER BY k.id DESC', [$cid])),
    'told' => array_column(rows('SELECT message FROM notifications WHERE user_id = ? ORDER BY id', [$uid]), 'message'),
]);

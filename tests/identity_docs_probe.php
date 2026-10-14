<?php
/**
 * The P5-M33 test's eyes, on the test database only.
 *   docs <candidate>       their documents: type, restricted, status
 *   w4 <candidate>         their W-4 status, in person, witness
 *   codes <candidate>      their signing codes (never the code: only its hash's length and the times)
 *   refused                how many refused openings of identity documents were logged
 *   notices <user>         their notification count and the last one
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'docs') {
    echo json_encode(rows('SELECT id, document_type, restricted, status FROM worker_documents WHERE candidate_id = ? ORDER BY id', [(int) $arg]));
} elseif ($cmd === 'w4') {
    echo json_encode(row('SELECT w4_status, w4_document_id, w4_in_person_at, w4_witness_id FROM employment_clearance WHERE candidate_id = ?', [(int) $arg]) ?: null);
} elseif ($cmd === 'codes') {
    echo json_encode(rows('SELECT LENGTH(code_hash) AS hash_length, TIMESTAMPDIFF(MINUTE, created_at, expires_at) AS minutes, used_at, outcome, created_by FROM signing_codes WHERE candidate_id = ? ORDER BY id', [(int) $arg]));
} elseif ($cmd === 'refused') {
    echo json_encode((int) val("SELECT COUNT(*) FROM activity WHERE action = 'was refused an identity document'"));
} elseif ($cmd === 'notices') {
    $last = row('SELECT message, target FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]);
    echo json_encode(['count' => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [(int) $arg]), 'last' => $last['message'] ?? '', 'target' => $last['target'] ?? '']);
}

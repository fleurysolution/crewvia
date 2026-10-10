<?php
/** Read-only: one person's self-service state, as JSON. The encrypted blob is returned so the test can prove it is not plain. */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$cid = (int) ($argv[1] ?? 0);

echo json_encode([
    'candidate'     => row('SELECT full_name, email, phone, city, state FROM candidates WHERE id = ?', [$cid]),
    'bank_in_use'   => row('SELECT last_four, bank_label, status FROM worker_bank_details WHERE candidate_id = ?', [$cid]),
    'bank_requests' => rows('SELECT id, last_four, bank_label, status, review_note, encrypted_details FROM worker_bank_change_requests WHERE candidate_id = ? ORDER BY id', [$cid]),
    'details'       => rows('SELECT id, field, old_value, new_value, status, review_note FROM profile_change_requests WHERE candidate_id = ? ORDER BY id', [$cid]),
    'confirmations' => rows('SELECT placement_id FROM details_confirmations WHERE candidate_id = ?', [$cid]),
    'access'        => array_column(rows('SELECT action FROM worker_bank_access WHERE candidate_id = ? ORDER BY id', [$cid]), 'action'),
    'events'        => array_column(rows("SELECT event_type FROM candidate_events WHERE candidate_id = ? AND event_type LIKE 'detail change%' ORDER BY id", [$cid]), 'event_type'),
]);

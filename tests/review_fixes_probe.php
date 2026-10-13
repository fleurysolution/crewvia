<?php
/**
 * The P5-M19 test's eyes, on the test database only: the fixture's
 * accounts, trusted phones, reviews and events.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/review_fixes.json'), true);
$users = implode(',', array_map('intval', $fx['ids']));
$cands = implode(',', array_map('intval', $fx['cands']));
$places = implode(',', array_map('intval', $fx['places']));
echo json_encode([
    'active' => array_column(rows("SELECT id, is_active FROM users WHERE id IN ($users)"), 'is_active', 'id'),
    'phones' => rows("SELECT user_id, revoked_at, revoke_reason FROM device_grants WHERE user_id IN ($users)"),
    'reviews' => rows("SELECT placement_id, grade, would_rehire, note, reviewed_by FROM assignment_reviews WHERE placement_id IN ($places)"),
    'events' => rows("SELECT candidate_id, event_type, detail FROM candidate_events WHERE candidate_id IN ($cands) AND event_type IN ('portal access locked','assignment review') ORDER BY id"),
]);

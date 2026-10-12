<?php
/**
 * The P5-M07 test's eyes and hands, on the test database only.
 *   read          templates, contacts, preferences, events and stage of the fixture's people
 *   phone <raw>   what msg_phone() makes of a number
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/messaging.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'phone') {
    echo json_encode(msg_phone((string) ($argv[2] ?? '')));
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/messaging.json'), true);
$cands = implode(',', array_map('intval', $fx['cands']));
echo json_encode([
    'templates' => rows('SELECT id, name, language, purpose, body, is_active FROM message_templates ORDER BY id'),
    'contacts' => rows("SELECT candidate_id, user_id, outcome, template_id, note FROM candidate_calls WHERE candidate_id IN ($cands) ORDER BY id"),
    'preferences' => rows("SELECT candidate_id, no_texts_since, no_calls_since, reason FROM contact_preferences WHERE candidate_id IN ($cands)"),
    'events' => rows("SELECT candidate_id, event_type, detail FROM candidate_events WHERE candidate_id IN ($cands) AND event_type = 'contact_preference' ORDER BY id"),
    'candidates' => rows("SELECT id, stage, last_contact_at FROM candidates WHERE id IN ($cands) ORDER BY id"),
]);

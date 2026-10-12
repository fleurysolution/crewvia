<?php
/**
 * The P5-M08 test's eyes and hands, on the test database only.
 *   read               credentials (sealed?), mode, texts, calls, contacts, preferences, notifications
 *   api <base>         points Twilio's address at the test's stand-in (debug only)
 *   phone <user> <n>   gives a staff member their own phone number
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/twilio.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$cmd = $argv[1] ?? 'read';
if ($cmd === 'api') {
    q("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('twilio_test_api_base', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [(string) $argv[2]]);
    exit(0);
}
if ($cmd === 'phone') {
    q('UPDATE users SET phone = ? WHERE id = ?', [(string) $argv[3], (int) $argv[2]]);
    exit(0);
}
$fx = json_decode((string) file_get_contents(__DIR__ . '/twilio.json'), true);
$cands = implode(',', array_map('intval', $fx['cands']));
$cred = row("SELECT * FROM provider_credentials WHERE provider = 'twilio'");
echo json_encode([
    'sealed' => $cred ? ['public' => json_decode((string) $cred['public_json'], true), 'raw' => (string) $cred['encrypted_secret'], 'opens' => tw_credentials()['auth_token'] ?? null] : null,
    'mode' => tw_mode(),
    'connector' => row("SELECT mode, sandbox_passed_at, authorization_ref FROM connector_modes WHERE connector = 'twilio'"),
    'sms' => rows('SELECT id, candidate_id, user_id, direction, phone, body, mode, provider_sid, status, error_code FROM sms_messages ORDER BY id'),
    'calls' => rows('SELECT id, candidate_id, user_id, staff_phone, candidate_phone, token, mode, provider_sid, status FROM voice_calls ORDER BY id'),
    'contacts' => rows("SELECT candidate_id, user_id, outcome, note FROM candidate_calls WHERE candidate_id IN ($cands) ORDER BY id"),
    'preferences' => rows("SELECT candidate_id, no_texts_since, no_calls_since, reason FROM contact_preferences WHERE candidate_id IN ($cands)"),
    'notifications' => rows('SELECT user_id, message, target FROM notifications WHERE user_id = ? ORDER BY id', [(int) $fx['ids']['rec']]),
    'events' => rows("SELECT action, outcome, reference, detail FROM integration_events WHERE provider = 'twilio' ORDER BY id"),
]);

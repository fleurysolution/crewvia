<?php
/**
 * The P5-M06c test's eyes and hands, on the test database only.
 *   read                     keys (public, and whether the private one is sealed), subscriptions, deliveries, settings
 *   notify <user> <target>   one Crewvia notification
 *   bump <user>              the account's sign-in version moves (password change)
 *   verify <authorization>   checks a push's VAPID header against the stored key; prints its claims
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/push.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

$fx = json_decode((string) file_get_contents(__DIR__ . '/push.json'), true);
$cmd = $argv[1] ?? 'read';
if ($cmd === 'notify') {
    q("INSERT INTO notifications (user_id, message, target) VALUES (?, 'Push test notice for a named person', ?)", [(int) $argv[2], (string) $argv[3]]);
    exit(0);
}
if ($cmd === 'bump') {
    q('INSERT INTO account_security(user_id,session_version) VALUES (?,1) ON DUPLICATE KEY UPDATE session_version=session_version+1', [(int) $argv[2]]);
    exit(0);
}
if ($cmd === 'verify') {
    $b64d = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    if (! preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(\S+)$/', (string) $argv[2], $m)) { echo json_encode(['ok' => false, 'why' => 'shape']); exit(0); }
    $priv = openssl_pkey_get_private((string) crypto_open((string) base64_decode((string) val('SELECT encrypted_private_key FROM push_keys WHERE id = 1'))));
    $public = openssl_pkey_get_details($priv)['key'];
    $raw = $b64d($m[3]);
    $int = static function (string $x): string { $x = ltrim($x, "\0"); if (ord($x[0]) & 0x80) { $x = "\0" . $x; } return "\x02" . chr(strlen($x)) . $x; };
    $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
    $der = "\x30" . chr(strlen($der)) . $der;
    echo json_encode(['ok' => openssl_verify($m[1] . '.' . $m[2], $der, $public, OPENSSL_ALGO_SHA256) === 1, 'k_matches' => $m[4] === push_public_key(),
                      'header' => json_decode($b64d($m[1]), true), 'claims' => json_decode($b64d($m[2]), true), 'now' => time()]);
    exit(0);
}

$users = implode(',', array_map('intval', $fx['ids']));
$sealed = (string) val('SELECT encrypted_private_key FROM push_keys WHERE id = 1');
echo json_encode([
    'public_key' => push_public_key(),
    'private_sealed' => $sealed !== '' && ! str_contains($sealed, 'PRIVATE KEY') && ! str_contains((string) base64_decode($sealed), 'PRIVATE KEY') && crypto_open((string) base64_decode($sealed)) !== null,
    'subs' => rows("SELECT id, user_id, endpoint, endpoint_hash, kinds, failures, last_status, ended_at, end_reason FROM push_subscriptions WHERE user_id IN ($users) ORDER BY id"),
    'deliveries' => rows("SELECT d.subscription_id, d.reason, d.status FROM push_deliveries d JOIN push_subscriptions s ON s.id = d.subscription_id WHERE s.user_id IN ($users) ORDER BY d.id"),
    'enabled' => push_enabled(),
    'cursor' => (int) val("SELECT setting_value FROM platform_settings WHERE setting_key = 'push_cursor'"),
    'max_notification' => (int) val('SELECT COALESCE(MAX(id), 0) FROM notifications'),
]);

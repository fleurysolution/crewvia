<?php
/**
 * The P5-M30 test's eyes, on the test database only.
 *   profile <candidate>   what is stored for them (the address as stored, and opened)
 *   leg <placement>       the inbound booking
 *   outbox <candidate>    the worker mail queued for them, opened
 *   notices <user>        their notification count and the last one
 *   advice <job>          the project's advice
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/crypto.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
$open = static function ($v): ?string {
    $raw = base64_decode((string) $v, true);
    return $raw === false ? null : crypto_open($raw);
};
if ($cmd === 'profile') {
    $p = row('SELECT * FROM travel_profiles WHERE candidate_id = ?', [(int) $arg]);
    echo json_encode($p ? ['legal_name' => $p['legal_name'], 'airport' => $p['airport'], 'notes' => $p['notes'], 'stored' => $p['encrypted_address'], 'address' => $open($p['encrypted_address'])] : null);
} elseif ($cmd === 'leg') {
    echo json_encode(row("SELECT id, mode, carrier, reference, depart_from, arrive_at, depart_time, arrive_time, cost, ticket_details, status FROM travel WHERE placement_id = ? AND direction = 'inbound' AND status <> 'cancelled'", [(int) $arg]));
} elseif ($cmd === 'outbox') {
    echo json_encode(array_map(static fn($m) => ['purpose' => $m['purpose'], 'recipient' => $m['recipient'], 'status' => $m['status'], 'stored' => $m['encrypted_message'], 'message' => json_decode((string) $open($m['encrypted_message']), true)],
        rows('SELECT * FROM mail_outbox WHERE candidate_id = ? ORDER BY id', [(int) $arg])));
} elseif ($cmd === 'notices') {
    $last = row('SELECT message, target FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $arg]);
    echo json_encode(['count' => (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [(int) $arg]), 'last' => $last['message'] ?? '', 'target' => $last['target'] ?? '']);
} elseif ($cmd === 'advice') {
    echo json_encode(array_column(rows('SELECT body FROM travel_advisories WHERE job_id = ? ORDER BY sort_order, id', [(int) $arg]), 'body'));
}

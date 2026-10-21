<?php
/**
 * P5-M100: the hub reads Crewvia's billing count and revenue (contract v1.1, sections 6 and 7).
 *
 *   GET /api/billing-count?month=YYYY-MM
 *   GET /api/revenue?month=YYYY-MM
 *
 * Server to server. Signed like POST /api/inquiry, over the query string:
 *   X-FS-Product: crewvia
 *   X-FS-Timestamp: <unix seconds>
 *   X-FS-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + raw query string)>
 * The secret is FS_HUB_SECRET_CREWVIA, from the environment (the server's own config as a fallback, never the
 * repository). More than 300 seconds apart, either way, is refused. No name, telephone, site, client detail or rate
 * ever leaves here: months, counts and hashes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing-ledger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    $reply(400, ['error' => 'bad_request', 'detail' => 'GET only']);
}

$secret = (string) (getenv('FS_HUB_SECRET_CREWVIA') ?: ($_SERVER['FS_HUB_SECRET_CREWVIA'] ?? ($config['fs_hub_secret_crewvia'] ?? '')));
if ($secret === '') {
    $reply(500, ['error' => 'server_error']);
}

$product = (string) ($_SERVER['HTTP_X_FS_PRODUCT'] ?? '');
$stamp = (string) ($_SERVER['HTTP_X_FS_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_FS_SIGNATURE'] ?? '');
if ($stamp === '' || $signature === '' || $product === '') {
    $reply(401, ['error' => 'bad_signature']);
}
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $stamp . '.' . $query, $secret);
if (! hash_equals($expected, $signature)) {
    $reply(401, ['error' => 'bad_signature']);
}
if (! ctype_digit($stamp) || abs(time() - (int) $stamp) > 300) {
    $reply(401, ['error' => 'stale_timestamp']);
}
if ($product !== 'crewvia') {
    $reply(404, ['error' => 'unknown_product']);
}

$month = (string) ($_GET['month'] ?? '');
if (! bl_valid_month($month)) {
    $reply(400, ['error' => 'bad_request', 'detail' => 'month must be YYYY-MM']);
}

bl_sync();
$closing = bl_closing($month);
$people = $closing ? (int) $closing['billable_people'] : bl_month($month)['people'];
$events = $closing ? (int) $closing['event_count'] : bl_month($month)['events'];
$iso = static fn(?string $utc): ?string => $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;

if ($path === '/api/billing-count') {
    $reply(200, [
        'product'         => 'crewvia',
        'client'          => (string) ($config['billing_client'] ?? 'rss'),
        'month'           => $month,
        'billable_people' => $people,
        'event_count'     => $events,
        'ledger_hash'     => $closing ? (string) $closing['ledger_hash'] : null,
        'sealed_at'       => $iso($closing ? (string) $closing['sealed_at'] : null),
    ]);
}

// /api/revenue: the subscription only. One-time amounts are the hub's own quotes (decision of 21 Oct 2026).
$body = [
    'product'              => 'crewvia',
    'month'                => $month,
    'currency'             => 'USD',
    'monthly_recurring'    => number_format(bl_amount($people), 2, '.', ''),
    'one_time'             => '0.00',
    'active_subscriptions' => 1,
    'generated_at'         => gmdate('Y-m-d\TH:i:s\Z'),
];
// The checksum: SHA-256 of the values above, in this order, joined by "|".
$body['checksum'] = hash('sha256', implode('|', array_map('strval', $body)));
$body['monthly_recurring'] = (float) $body['monthly_recurring'];
$body['one_time'] = (float) $body['one_time'];
$reply(200, $body);

<?php
/**
 * The P2-M07 test's eyes, on the test database only: the two workers'
 * requests and notifications. "tamper <request id>" alters an issued
 * letter, so the test can show it no longer opens.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }

if (($argv[1] ?? '') === 'tamper') {
    q("UPDATE hr_requests SET letter_text = CONCAT(letter_text, ' Salary: a million.') WHERE id = ?", [(int) $argv[2]]);
    exit(0);
}

$a = (int) ($argv[1] ?? 0);
$b = (int) ($argv[2] ?? 0);
$num = static fn(array $rows, array $keys): array => array_map(static function (array $r) use ($keys): array {
    foreach ($keys as $k) {
        if (isset($r[$k])) { $r[$k] = $r[$k] + 0; }
    }
    return $r;
}, $rows);

echo json_encode([
    'requests' => $num(rows('SELECT id, reference, candidate_id, kind, desk, status, include_pay, letter_text, letter_sha256 FROM hr_requests WHERE candidate_id IN (?, ?) ORDER BY id', [$a, $b]), ['id', 'candidate_id', 'include_pay']),
    'replies'  => $num(rows('SELECT x.request_id, x.by_worker FROM hr_request_replies x JOIN hr_requests h ON h.id = x.request_id WHERE h.candidate_id IN (?, ?) ORDER BY x.id', [$a, $b]), ['request_id', 'by_worker']),
    'notified' => $num(rows("SELECT user_id, message FROM notifications WHERE target LIKE '/hr-requests%' ORDER BY id"), ['user_id']),
]);

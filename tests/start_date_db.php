<?php
/**
 * P5-M56 on the isolated test database only: the placement made from an offer starts on the
 * requisition's start date, or today when that date has passed ("starts immediately", as the
 * offer letter says) - never on a day already gone.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/placement-rates.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}

$job = (int) val('SELECT id FROM jobs ORDER BY id LIMIT 1');
$vacancy = static function (?string $starts) use ($job): int {
    q("INSERT INTO vacancies (job_id, title, description, is_open, openings, starts_on) VALUES (?, 'P5-M56 start', 'Synthetic.', 1, 1, ?)", [$job, $starts]);
    return (int) db()->lastInsertId();
};
$past = $vacancy(date('Y-m-d', strtotime('-31 days')));
$future = $vacancy(date('Y-m-d', strtotime('+20 days')));
$none = $vacancy(null);

check('A requisition whose start has passed: the placement starts today', offer_start_date($past) === date('Y-m-d'), (string) offer_start_date($past));
check('A requisition starting later: the placement starts that day', offer_start_date($future) === date('Y-m-d', strtotime('+20 days')), (string) offer_start_date($future));
check('No start date on the requisition: none on the placement', offer_start_date($none) === null);
q('DELETE FROM vacancies WHERE id IN (?, ?, ?)', [$past, $future, $none]);

file_put_contents(__DIR__ . '/start-date-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

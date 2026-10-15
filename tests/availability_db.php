<?php
/**
 * P5-M45 at the database level, on the isolated test database only, after
 * availability_http.py: what "available from" reads once the date has come,
 * then rollback (the columns gone, the history kept) and upgrade, once.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/availability.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: this test only runs against rss_ops_test.\n"); exit(2); }

$results = [];
function check(string $label, bool $condition, string $detail = ''): void
{
    global $results;
    if (! $condition) { fwrite(STDERR, 'FAIL ' . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL); exit(1); }
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function run_php(string $script): array
{
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
$app = __DIR__ . '/test-app';

check('Available from a date reads as available now once the date has come, and as the date before it',
      availability_words('from', date('Y-m-d', strtotime('-1 day'))) === 'Available now' && str_starts_with(availability_words('from', date('Y-m-d', strtotime('+5 days'))), 'Available from')
      && availability_tone('from', date('Y-m-d', strtotime('+5 days'))) === 'amber' && availability_words(null, null) === '');
$cid = (int) val("SELECT id FROM candidates WHERE email = 'av-quinn@test.invalid' ORDER BY id LIMIT 1");
check('A start date already passed is recorded as available now', availability_set($cid, 'from', date('Y-m-d', strtotime('-2 days'))) === null
      && val('SELECT availability FROM candidates WHERE id = ?', [$cid]) === 'available');

$column = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'candidates' AND column_name = 'availability'");
$history = (int) val("SELECT COUNT(*) FROM candidate_events WHERE event_type = 'availability'");
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m45.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback drops the availability and keeps its history', ! $column() && $history >= 3 && (int) val("SELECT COUNT(*) FROM candidate_events WHERE event_type = 'availability'") === $history);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade brings it back and says so', $code === 0 && $column() === 1 && str_contains($first, 'Availability:'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M45', $code === 0 && ! str_contains($second, 'Availability:'), $second);

file_put_contents(__DIR__ . '/av-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

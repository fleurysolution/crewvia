<?php
/**
 * P5-M44 at the database level, on the isolated test database only, after
 * reviews_http.py: rollback (the review and interview tables gone, the
 * history on the applications kept) and upgrade (back, announced once).
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

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
$table = static fn(string $t): int => (int) val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
$history = static fn(): int => (int) val("SELECT COUNT(*) FROM application_events WHERE note LIKE 'Passed to % for review.%' OR note LIKE 'Interview done:%'");

check('Before the rollback the reviews and interviews are recorded, and told in the applications\' history',
      (int) val('SELECT COUNT(*) FROM application_reviews') >= 2 && (int) val('SELECT COUNT(*) FROM interviews') >= 1 && $history() >= 3);
$before = $history();
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m44.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes both tables and keeps the history in words', ! $table('application_reviews') && ! $table('interviews') && $history() === $before);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade brings them back and says what the office can do', $code === 0 && $table('application_reviews') && $table('interviews') && str_contains($first, 'Candidate reviews:'), $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M44', $code === 0 && ! str_contains($second, 'Candidate reviews:'), $second);

file_put_contents(__DIR__ . '/rv-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

<?php
/**
 * P5-M55 on the isolated test database only, after onboarding_scope_http.py: the rollback drops the
 * "crew on site too" choice; the upgrade brings it back and removes, once, the untouched tasks the
 * crew on site was given - and only those (what somebody answered, and the new hires' tasks, stay).
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
$column = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'onboarding_requirements' AND column_name = 'for_crew'");

// The crew on site given a requirement as the earlier code did: one task untouched, one answered.
$onSite = (int) val("SELECT id FROM placements WHERE status = 'on_site' ORDER BY id LIMIT 1");
$job = (int) val('SELECT job_id FROM placements WHERE id = ?', [$onSite]);
q("INSERT INTO onboarding_requirements (job_id, title, instructions) VALUES (?, 'P5-M55 old style A', 'Given to everybody.')", [$job]);
$untouchedReq = (int) db()->lastInsertId();
q("INSERT INTO onboarding_requirements (job_id, title, instructions) VALUES (?, 'P5-M55 old style B', 'Given to everybody.')", [$job]);
$answeredReq = (int) db()->lastInsertId();
q('INSERT INTO onboarding_tasks (placement_id, requirement_id) VALUES (?, ?)', [$onSite, $untouchedReq]);
q("INSERT INTO onboarding_tasks (placement_id, requirement_id, status, response) VALUES (?, ?, 'submitted', 'Done already.')", [$onSite, $answeredReq]);
$newHireTasks = (int) val("SELECT COUNT(*) FROM onboarding_tasks t JOIN placements p ON p.id = t.placement_id WHERE p.status IN ('offered','confirmed','travelling')");

foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m55.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback drops the "crew on site too" choice', $column() === 0);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade brings it back and says how many untouched tasks it removed from the crew on site',
      $code === 0 && $column() === 1 && (bool) preg_match('/Untouched tasks removed from the crew on site: [1-9]\d*/', $first), $first);
check('The crew\'s untouched task is gone; what they answered stays',
      ! val('SELECT COUNT(*) FROM onboarding_tasks WHERE placement_id = ? AND requirement_id = ?', [$onSite, $untouchedReq])
      && (string) val('SELECT response FROM onboarding_tasks WHERE placement_id = ? AND requirement_id = ?', [$onSite, $answeredReq]) === 'Done already.');
check('The new hires keep their tasks',
      (int) val("SELECT COUNT(*) FROM onboarding_tasks t JOIN placements p ON p.id = t.placement_id WHERE p.status IN ('offered','confirmed','travelling')") === $newHireTasks, (string) $newHireTasks);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M55', $code === 0 && ! str_contains($second, 'Untouched tasks removed'), $second);

file_put_contents(__DIR__ . '/onboarding-scope-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

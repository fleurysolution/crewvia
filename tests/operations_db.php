<?php
/**
 * P4-M07 at the database level, on the isolated test database only:
 * rollback and upgrade of ops_runs, a job that still runs while the table
 * is gone, silence on a repeat, the backup folder refusals.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/backup.php';

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
$table = static fn(): bool => (bool) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ops_runs'");
$app = __DIR__ . '/test-app';

check('P4-M07 is in place before the rollback', $table());
q("INSERT INTO ops_runs(kind,status,started_at,finished_at) VALUES ('workflows','ok',NOW(),NOW())");
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p4-m07.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the record of runs', ! $table());
ops_record('workflows', 'ok', [], microtime(true));
check('A job still runs and records nothing while the table is gone (no exception)', ! $table());
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces operations: errors logged privately, health at /health, no backup until install/backup.php runs',
      $code === 0 && str_contains($first, 'Operations: errors logged privately') && str_contains($first, 'no backup is taken until install/backup.php runs'), $first);
check('The table is back', $table());
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P4-M07', $code === 0 && ! str_contains($second, 'Operations:'), $second);
ops_record('workflows', 'ok', ['probe' => 1], microtime(true) - 1.5);
$last = ops_last_run('workflows');
check('A run is recorded with its kind, state, duration and host', $last && $last['status'] === 'ok' && (float) $last['seconds'] >= 1.4 && $last['host'] !== '' && json_decode($last['detail'], true) === ['probe' => 1], json_encode($last));

check('A backup folder under public_html is refused', ops_backup_dir_refusal('/home/someone/public_html/crewvia-backups') !== null);
check('so is one under www or htdocs', ops_backup_dir_refusal('C:/xampp/htdocs/backups') !== null && ops_backup_dir_refusal('/var/www/backups') !== null);
check('and one inside the application folder', ops_backup_dir_refusal($app . '/backups') !== null);
check('A folder in the home directory is accepted', ops_backup_dir_refusal('/home/someone/crewvia-backups') === null);
check('The database host is read with its port, as config.php writes it', backup_db_target(['db_host' => '127.0.0.1;port=3308']) === ['host' => '127.0.0.1', 'port' => 3308, 'socket' => null]);

file_put_contents(__DIR__ . '/ops-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

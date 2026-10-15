<?php
/**
 * P5-M42 on the isolated test database only, after indeed_import_http.py and
 * add_applicant_http.py: an Indeed applicant's screening score is the share of
 * the board's requirements met, and nobody is screened out for it; the
 * rollback clears it and files a diploma as another document again; the
 * upgrade fills the scores again from the note the import left and brings the
 * diploma kind back, once.
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
$score = static fn(string $email): array => row('SELECT a.screening_score, a.screened_out FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE c.email = ? ORDER BY a.id DESC LIMIT 1', [$email]) ?: [];

check('An Indeed applicant who meets every requirement on the board scores 100, one who meets half scores 50',
      (float) ($score('in-one@test.invalid')['screening_score'] ?? -1) === 100.0 && (float) ($score('in-two@test.invalid')['screening_score'] ?? -1) === 50.0,
      json_encode([$score('in-one@test.invalid'), $score('in-two@test.invalid')]));
check('Nobody is screened out for the board\'s verdict: the office decides', (int) ($score('in-two@test.invalid')['screened_out'] ?? 1) === 0);

$kindColumn = static fn(): string => (string) val("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'candidate_resumes' AND column_name = 'kind'");
$diplomas = (int) val("SELECT COUNT(*) FROM candidate_resumes WHERE kind = 'certificate'");
check('Before the rollback a diploma is its own kind of document', str_contains($kindColumn(), "'certificate'") && $diplomas >= 1, (string) $diplomas);

// Newest first, as a rollback is run: P5-M43 (built on these scores) before P5-M42.
foreach (array_merge(preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m43.sql')),
                     preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m42.sql'))) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback clears the board scores and the marker',
      array_key_exists('screening_score', $score('in-one@test.invalid')) && $score('in-one@test.invalid')['screening_score'] === null && ! val("SELECT COUNT(*) FROM platform_settings WHERE setting_key = 'board_scores_filled'"));

check('and files every diploma as another document, the file kept', ! str_contains($kindColumn(), "'certificate'") && (int) val("SELECT COUNT(*) FROM candidate_resumes r JOIN candidates c ON c.id = r.candidate_id WHERE c.email = 'add-emma@test.invalid' AND r.kind = 'other'") === 1);

[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade brings the diploma kind back and says the office adds applicants by hand', str_contains($kindColumn(), "'certificate'") && str_contains($first, 'Add an applicant'), $first);
check('Upgrade scores the board applicants again from the note the import left',
      $code === 0 && str_contains($first, 'Job board applicants:') && str_contains($first, ' scored from the board')
      && (float) ($score('in-one@test.invalid')['screening_score'] ?? -1) === 100.0 && (float) ($score('in-two@test.invalid')['screening_score'] ?? -1) === 50.0, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M42', $code === 0 && ! str_contains($second, 'Job board applicants:') && ! str_contains($second, 'Add an applicant'), $second);

file_put_contents(__DIR__ . '/bs-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

<?php
/**
 * P5-M47 on the isolated test database only, after poster_http.py: in test
 * mode, an email to a candidate goes to the test inbox under their first name
 * (with the last name's initial when two share it), marked with who it was
 * for, answering to the recruiting lead; office staff are never redirected;
 * test mode off sends to the candidate. Then rollback and upgrade.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/mail-routing.php';
require_once __DIR__ . '/test-app/app/campaigns.php';

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
$set = static function (string $k, string $v): void {
    q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
};
$app = __DIR__ . '/test-app';

// The candidates of this test.
foreach ([['Otis Armstrong', 'mr-otis@test.invalid'], ['Andrew Porter Gonzalez', 'mr-andrew-g@test.invalid'], ['Andrew Sheppard', 'mr-andrew-s@test.invalid']] as [$n, $e]) {
    q("INSERT INTO candidates (full_name, email, stage) VALUES (?,?,'new')", [$n, $e]);
}
$set('candidate_mail_test', '1');
$set('candidate_mail_test_to', 'solutionsfleury+{first}@gmail.com');
$set('candidate_mail_reply_to', 'pa@rsinc.com');

$otis = mail_route('mr-otis@test.invalid');
check('In test mode a candidate\'s email goes to the test inbox under their first name, marked with who it was for',
      $otis['to'] === 'solutionsfleury+otis@gmail.com' && $otis['prefix'] === '[TEST for Otis Armstrong] ' && $otis['redirected'], json_encode($otis));
check('Two candidates with the same first name get the last name\'s initial',
      mail_route('mr-andrew-g@test.invalid')['to'] === 'solutionsfleury+andrewg@gmail.com' && mail_route('mr-andrew-s@test.invalid')['to'] === 'solutionsfleury+andrews@gmail.com',
      mail_route('mr-andrew-g@test.invalid')['to']);
check('Office staff always get their own emails', mail_route('wk-rec@test.invalid') === ['to' => 'wk-rec@test.invalid', 'prefix' => '', 'redirected' => false]);
check('A candidate\'s email answers to the recruiting lead; a campaign keeps its recruiter',
      (mail_candidate_headers(['From' => 'x@test.invalid'], 'mr-otis@test.invalid')['Reply-To'] ?? '') === 'pa@rsinc.com'
      && mail_candidate_headers(['Reply-To' => 'rec@test.invalid'], 'mr-otis@test.invalid')['Reply-To'] === 'rec@test.invalid'
      && ! isset(mail_candidate_headers([], 'wk-rec@test.invalid')['Reply-To']));

$capture = __DIR__ . '/mail-routing.jsonl';
@unlink($capture);
$set('mail_capture_path', $capture);
cm_mail('mr-otis@test.invalid', 'Your portal', 'Hello Otis', ['From' => 'capture@test.invalid']);
$sent = array_map(static fn($l) => json_decode($l, true), file($capture) ?: []);
check('What is sent goes to the test inbox with the marked subject and the reply address, the body untouched',
      count($sent) === 1 && $sent[0]['to'] === 'solutionsfleury+otis@gmail.com' && $sent[0]['subject'] === '[TEST for Otis Armstrong] Your portal'
      && $sent[0]['body'] === 'Hello Otis' && ($sent[0]['headers']['Reply-To'] ?? '') === 'pa@rsinc.com', json_encode($sent));

// P5-M48: the invitation names the agency the candidate applied to, and calls it their candidate portal.
require_once __DIR__ . '/test-app/app/invitations.php';
$set('agency_name', 'RSS Inc.');
$otisId = (int) val("SELECT id FROM candidates WHERE email = 'mr-otis@test.invalid'");
workforce_invite_candidate($otisId);
$queued = token_decrypt((string) val('SELECT o.encrypted_message FROM email_outbox o JOIN invitations i ON i.id = o.invitation_id WHERE i.candidate_id = ? ORDER BY o.id DESC LIMIT 1', [$otisId]));
check('The invitation names the agency (RSS Inc.), not the platform, and calls it their candidate portal',
      str_starts_with((string) $queued['subject'], 'RSS Inc.: activate your candidate portal') && str_contains((string) $queued['body'], 'with RSS Inc. has moved forward') && agency_name() === 'RSS Inc.', json_encode($queued));
$set('agency_name', '');
check('Without an agency name, the platform name stands in', agency_name() !== '' && agency_name() !== 'RSS Inc.');

$set('candidate_mail_test', '0');
check('Test mode off: the candidate receives it', mail_route('mr-otis@test.invalid')['to'] === 'mr-otis@test.invalid');

foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m47.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the settings', ! val("SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE 'candidate_mail_%'"));
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade switches test mode on, with the test inbox and pa@rsinc.com for replies, and says so',
      $code === 0 && mail_test_mode() && mail_reply_to() === 'pa@rsinc.com' && mail_test_pattern() === 'solutionsfleury+{first}@gmail.com' && str_contains($first, 'Candidate emails: test mode is on'), $first);
$set('candidate_mail_test', '0');
[$code, $second] = run_php($app . '/install/upgrade.php');
check('An upgrade never turns test mode back on once the office turned it off', $code === 0 && ! mail_test_mode() && ! str_contains($second, 'Candidate emails:'), $second);
$set('candidate_mail_test', '1');
q("DELETE FROM platform_settings WHERE setting_key = 'mail_capture_path'");

file_put_contents(__DIR__ . '/mr-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

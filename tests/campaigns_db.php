<?php
/**
 * P5-M18 (T7) at the database level, on the isolated test database only:
 * rollback (people and their text/call preferences kept, only the email
 * opt-out and the campaigns go) and upgrade, silence on a repeat; then the
 * fixture for campaigns_http.py: a recruiter, an administrator, Logistics;
 * an open chemical requisition in Katy, TX, and one on a closed project;
 * one person for each rule (an account holder in Houston, someone in Austin
 * without an account, one in Dallas, one who unsubscribed, one on the
 * do-not-rehire register, one with no valid email, one already applying,
 * one in another trade, one with a switched-off account), and 55
 * electricians to need an administrator's approval. Names start "Aaa" so
 * they come first in any list. Mail is captured to a file (debug only).
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
$tables = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('email_campaigns','email_campaign_recipients')");
$optOut = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'contact_preferences' AND column_name = 'no_email_since'");
$app = __DIR__ . '/test-app';

check('P5-M18 is in place before the rollback', $tables() === 2 && $optOut() === 1);
$prefs = (int) val('SELECT COUNT(*) FROM contact_preferences');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m18.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes campaigns and the email opt-out, and keeps every text and call preference', $tables() === 0 && $optOut() === 0 && (int) val('SELECT COUNT(*) FROM contact_preferences') === $prefs);
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade announces campaign email and what is left to do', $code === 0 && str_contains($first, 'Campaign email: one email about a requisition to a filtered list') && str_contains($first, 'deliver-campaigns.php') && $tables() === 2 && $optOut() === 1, $first);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M18', $code === 0 && ! str_contains($second, 'Campaign email:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$ids = [];
foreach (['rec' => 'recruiter', 'admin' => 'admin', 'hotels' => 'hotels'] as $k => $role) {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Campaign ' . $k, 'cm-' . $k . '@test.invalid', $password, $role]);
    $ids[$k] = (int) db()->lastInsertId();
}
q("INSERT INTO clients(name) VALUES ('Campaign client')");
$client = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status,site_city,site_state) VALUES (?,'P5-M18 Katy refinery','active','Katy','TX')", [$client]);
$job = (int) db()->lastInsertId();
q("INSERT INTO jobs(client_id,title,status) VALUES (?,'P5-M18 finished','closed')", [$client]);
$closedJob = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline,pay_rate,per_diem_rate,shift,starts_on) VALUES (?,'P5-M18 chemical operator','Synthetic.',1,5,'chemical',42,75,'12 hours, days',DATE_ADD(CURDATE(), INTERVAL 10 DAY))", [$job]);
$vacancy = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline) VALUES (?,'P5-M18 electrician','Synthetic.',1,40,'electrical')", [$job]);
$electric = (int) db()->lastInsertId();
q("INSERT INTO vacancies(job_id,title,description,is_open,openings,discipline) VALUES (?,'P5-M18 gone','Synthetic.',1,1,'chemical')", [$closedJob]);
$gone = (int) db()->lastInsertId();

$cands = [];
$person = static function (string $k, string $name, ?string $email, string $trade, string $city) use (&$cands): int {
    q("INSERT INTO candidates(full_name,email,stage,discipline,city,state) VALUES (?,?,'contacted',?,?,'TX')", [$name, $email, $trade, $city]);
    return $cands[$k] = (int) db()->lastInsertId();
};
$person('ana', 'Aaa Ana Houston', 'cm-ana@test.invalid', 'chemical', 'Houston');
$person('ben', 'Aaa Ben Austin', 'cm-ben@test.invalid', 'chemical', 'Austin');
$person('ivy', 'Aaa Ivy Dallas', 'cm-ivy@test.invalid', 'chemical', 'Dallas');
$person('cal', 'Aaa Cal Unsubscribed', 'cm-cal@test.invalid', 'chemical', 'Houston');
$person('dee', 'Aaa Dee Norehire', 'cm-dee@test.invalid', 'chemical', 'Houston');
$person('eve', 'Aaa Eve Noemail', 'not-an-email', 'chemical', 'Houston');
$person('fay', 'Aaa Fay Applied', 'cm-fay@test.invalid', 'chemical', 'Houston');
$person('gus', 'Aaa Gus Mechanical', 'cm-gus@test.invalid', 'mechanical', 'Houston');
$person('hal', 'Aaa Hal Switchedoff', 'cm-hal@test.invalid', 'chemical', 'Houston');
q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', ['Campaign worker', 'cm-ana-login@test.invalid', $password, 'worker']);
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [(int) db()->lastInsertId(), $cands['ana']]);
q('INSERT INTO users(name,email,password_hash,role,must_change_pw,is_active) VALUES (?,?,?,?,0,0)', ['Campaign off', 'cm-hal-login@test.invalid', $password, 'worker']);
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [(int) db()->lastInsertId(), $cands['hal']]);
q('INSERT INTO contact_preferences(candidate_id,no_email_since) VALUES (?,NOW())', [$cands['cal']]);
q("INSERT INTO employee_profiles(candidate_id,rehire_status) VALUES (?,'ineligible')", [$cands['dee']]);
q("INSERT INTO applications(candidate_id,vacancy_id,stage) VALUES (?,?,'new')", [$cands['fay'], $vacancy]);
$bulk = [];
for ($i = 1; $i <= 55; $i++) {
    $bulk[] = $person('bulk' . $i, sprintf('Aaa Bulk %02d', $i), sprintf('cm-bulk%02d@test.invalid', $i), 'electrical', 'Houston');
}
$capture = __DIR__ . '/campaign-mail.jsonl';
@unlink($capture);
q("INSERT INTO platform_settings(setting_key,setting_value) VALUES ('mail_capture_path',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$capture]);
// P5-M47: this test checks delivery to the real recipient, so candidates' emails are live here (test mode is mail_routing_db.php's).
q("INSERT INTO platform_settings(setting_key,setting_value) VALUES ('candidate_mail_test','0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
q("DELETE FROM platform_settings WHERE setting_key = 'campaign_postal_address'");

file_put_contents(__DIR__ . '/campaigns.json', json_encode(['ids' => $ids, 'cands' => $cands, 'bulk' => $bulk, 'vacancy' => $vacancy, 'electric' => $electric, 'gone' => $gone, 'capture' => $capture]));
file_put_contents(__DIR__ . '/cm-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

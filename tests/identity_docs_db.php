<?php
/**
 * P5-M33 at the database level, on the isolated test database only:
 * rollback (documents and W-4 statuses kept) and upgrade, silence on a
 * repeat, existing identity documents restricted by the upgrade; then the
 * fixture for identity_docs_http.py, on the walkthrough fixture: a second
 * recruiter, a payroll clerk, and a worker with a portal.
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
$parts = static fn(): int => (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('identity_readers','signing_codes')")
                           + (int) val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'worker_documents' AND column_name = 'restricted') OR (table_name = 'employment_clearance' AND column_name IN ('w4_in_person_at','w4_witness_id')))");
$app = __DIR__ . '/test-app';
$wk = json_decode((string) file_get_contents(__DIR__ . '/walkthrough.json'), true);

check('P5-M33 is in place before the rollback', $parts() === 5);
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Aaa Identity Legacy','id-legacy@test.invalid','placed')");
$legacy = (int) db()->lastInsertId();
$doc = static function (string $type) use ($legacy, $wk): int {
    q('INSERT INTO worker_documents(candidate_id,uploaded_by,document_type,original_name,storage_name,mime_type,file_hash) VALUES (?,?,?,?,?,?,?)', [$legacy, $wk['ids']['rec'], $type, 'x.pdf', bin2hex(random_bytes(32)), 'application/pdf', str_repeat('0', 64)]);
    return (int) db()->lastInsertId();
};
$docs = (int) val('SELECT COUNT(*) FROM worker_documents');
foreach (preg_split('/;\s*\n/', (string) file_get_contents($app . '/install/rollback/p5-m33.sql')) as $chunk) {
    $statement = trim(implode("\n", array_filter(explode("\n", $chunk), fn($l) => ! str_starts_with(ltrim($l), '--'))));
    if ($statement !== '') { db()->exec($statement); }
}
check('Rollback removes the restriction, the readers, the codes and the witness, and keeps every document', $parts() === 0 && (int) val('SELECT COUNT(*) FROM worker_documents') === $docs);
$ssn = $doc('Social Security card');
$license = $doc("Driver's license");
$receipt = $doc('Receipt');
$w4 = $doc('W4');
[$code, $first] = run_php($app . '/install/upgrade.php');
check('Upgrade restricts the identity documents already stored, and says how many', $code === 0 && str_contains($first, 'existing identity document') && str_contains($first, 'W-4 in person:') && $parts() === 5, $first);
$restricted = static fn(int $id): int => (int) val('SELECT restricted FROM worker_documents WHERE id = ?', [$id]);
check('A Social Security card and a license are restricted; a receipt and a W-4 are not', $restricted($ssn) === 1 && $restricted($license) === 1 && $restricted($receipt) === 0 && $restricted($w4) === 0);
[$code, $second] = run_php($app . '/install/upgrade.php');
check('A second upgrade is silent for P5-M33', $code === 0 && ! str_contains($second, 'Identity documents:') && ! str_contains($second, 'W-4 in person:'), $second);

$password = password_hash('TestPassword123!', PASSWORD_DEFAULT);
$user = static function (string $name, string $email, string $role) use ($password): int {
    q('INSERT INTO users(name,email,password_hash,role,must_change_pw) VALUES (?,?,?,?,0)', [$name, $email, $password, $role]);
    return (int) db()->lastInsertId();
};
$rec2 = $user('Identity second recruiter', 'id-rec2@test.invalid', 'recruiter');
$payroll = $user('Identity payroll', 'id-payroll@test.invalid', 'payroll');
$workerUser = $user('Identity worker', 'id-worker@test.invalid', 'worker');
q("INSERT INTO candidates(full_name,email,stage) VALUES ('Aaa Identity Worker','id-worker@test.invalid','placed')");
$worker = (int) db()->lastInsertId();
q('INSERT INTO worker_accounts(user_id,candidate_id) VALUES (?,?)', [$workerUser, $worker]);
q("INSERT INTO placements(candidate_id,job_id,status,start_date,pay_rate) VALUES (?,?,'confirmed',DATE_ADD(CURDATE(), INTERVAL 3 DAY),45)", [$worker, $wk['job']]);
file_put_contents(__DIR__ . '/identity_docs.json', json_encode(compact('rec2', 'payroll', 'workerUser', 'worker')));
file_put_contents(__DIR__ . '/id-db-results.json', json_encode($results, JSON_PRETTY_PRINT));

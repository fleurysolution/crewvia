<?php
/**
 * P5-M79 on the test database: who is ready for ADP, the record built from what was signed, the new-hire file,
 * and the API (token, hire, direct deposit) with ADP's answers stood in - nothing leaves the machine.
 * The SSN and bank numbers are test values.
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';
require_once __DIR__ . '/test-app/app/adp.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
$check = static function (string $label, bool $ok, $detail = ''): void {
    if (! $ok) { fwrite(STDERR, "FAIL $label " . json_encode($detail) . "\n"); exit(1); }
    echo "PASS $label\n";
};
$admin = (int) val('SELECT MIN(id) FROM users');
q("INSERT INTO clients (name) VALUES ('ADP test client')");
q("INSERT INTO jobs (client_id, title, status, site_state) VALUES (?, 'ADP test plant', 'active', 'IN')", [(int) db()->lastInsertId()]);
$job = (int) db()->lastInsertId();
$person = static function (string $name) use ($job): int {
    q("INSERT INTO candidates (full_name, email, phone, stage) VALUES (?, ?, '555-060-6006', 'new')", [$name, strtolower(str_replace(' ', '.', $name)) . '@test.invalid']);
    $id = (int) db()->lastInsertId();
    q("INSERT INTO placements (candidate_id, job_id, status, start_date, pay_rate, bill_rate) VALUES (?, ?, 'confirmed', '2026-11-02', 38, 58)", [$id, $job]);
    q("INSERT INTO employee_profiles (candidate_id, employment_type) VALUES (?, 'hourly')", [$id]);
    return $id;
};
$form = static function (int $cid, string $kind, array $p) use ($admin): void {
    $json = json_encode($p);
    q("INSERT INTO worker_tax_forms (candidate_id, form, encrypted_payload, payload_hash, ssn_last_four, signer_name, signed_at, signed_by, status)
       VALUES (?,?,?,?,?,?,NOW(),?, 'accepted')", [$cid, $kind, base64_encode(crypto_seal($json)), hash('sha256', $json), substr((string) ($p['ssn'] ?? ''), -4) ?: null, 'Test Signer', $admin]);
};

$ready = $person('Avery Ready');
$base = ['first_name' => 'Avery', 'middle_initial' => 'K', 'last_name' => 'Ready', 'address' => '12 Main St', 'apartment' => '', 'city' => 'Gary', 'state' => 'IN', 'zip' => '46402', 'ssn' => '212551234'];
$form($ready, 'w4', $base + ['filing_status' => 'single', 'two_jobs' => false, 'exempt' => false, 'step3_total' => 2000, 'other_income' => 0, 'deductions' => 0, 'extra_withholding' => 25]);
$form($ready, 'i9', $base + ['birth_date' => '1995-01-15', 'email' => 'avery.ready@test.invalid', 'phone' => '555-060-6006']);
q("INSERT INTO worker_bank_details (candidate_id, encrypted_details, last_four, bank_label, status, submitted_by, reviewed_by, reviewed_at) VALUES (?,?,?,?, 'verified', ?, ?, NOW())",
  [$ready, token_encrypt(['account_number' => '000123456789', 'routing_number' => '021000021', 'bank_name' => 'Test Bank', 'account_holder' => 'Avery Ready']), '6789', 'Test Bank', $admin, $admin]);
$waiting = $person('Blake Waiting');
$wjson = json_encode(['first_name' => 'Blake']);
q("INSERT INTO worker_tax_forms (candidate_id, form, encrypted_payload, payload_hash, signer_name, signed_at, signed_by, status) VALUES (?, 'w4', ?, ?, 'Blake Waiting', NOW(), ?, 'submitted')",
  [$waiting, base64_encode(crypto_seal($wjson)), hash('sha256', $wjson), $admin]);
$idle = $person('Casey Idle');

$hires = array_column(adp_new_hires(), null, 'candidate_id');
$check('Somebody with W-4, I-9 and direct deposit accepted is ready for ADP', ! empty($hires[$ready]['ready']), $hires[$ready] ?? null);
$check('Somebody without them is listed with what is missing', isset($hires[$waiting]) && ! $hires[$waiting]['ready'] && count($hires[$waiting]['missing']) === 3, $hires[$waiting] ?? null);
$check('Somebody who has not started a form is counted, not listed', ! isset($hires[$idle]) && ($GLOBALS['adp_not_started'] ?? 0) >= 1);
$r = adp_hire_record($ready);
$check('The record comes from what was signed: name, SSN, birth date, hire date, rate, W-4 and deposit', $r && $r['last_name'] === 'Ready' && $r['ssn'] === '212551234' && $r['birth_date'] === '1995-01-15'
       && $r['hire_date'] === '2026-11-02' && $r['rate'] == 38.0 && $r['dependents_amount'] == 2000.0 && $r['extra_withholding'] == 25.0 && $r['routing_number'] === '021000021' && $r['work_state'] === 'IN', $r);
$check('Reading the deposit for ADP is in the bank access history', (int) val("SELECT COUNT(*) FROM worker_bank_access WHERE candidate_id = ? AND action = 'read for ADP'", [$ready]) >= 1);
$csv = adp_hire_csv([$r, ['last_name' => '=HYPERLINK("x")'] + $r]);
$check('The new-hire file has the ADP columns and the person', str_starts_with($csv, '"Last Name","First Name","Middle Initial","SSN"') && str_contains($csv, '"212551234"') && str_contains($csv, '"021000021"'), substr($csv, 0, 300));
$check('A cell that would be a spreadsheet formula is neutralised', str_contains($csv, "\"'=HYPERLINK"), $csv);

global $config;
$config['adp_api'] = [];
$check('Without credentials the API says what is missing', count(adp_api_missing()) === 4);
[$ok, $msg] = adp_api_send_hire($ready, $admin);
$check('...and does not send', ! $ok && (int) val("SELECT COUNT(*) FROM adp_sends WHERE candidate_id = ? AND status = 'failed'", [$ready]) === 1, $msg);

$dir = sys_get_temp_dir() . '/crewvia-adp-test';
@mkdir($dir);
file_put_contents($dir . '/cert.pem', 'test certificate');
file_put_contents($dir . '/key.pem', 'test key');
$config['adp_api'] = ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'cert' => $dir . '/cert.pem', 'key' => $dir . '/key.pem'];
$calls = [];
integration_transport(static function (array $req) use (&$calls): array {
    $calls[] = $req;
    if (str_contains($req['url'], '/oauth/')) { return ['status' => 200, 'headers' => [], 'body' => json_encode(['access_token' => 'test-token'])]; }
    if (str_contains($req['url'], 'worker.hire')) { return ['status' => 200, 'headers' => [], 'body' => json_encode(['events' => [['data' => ['output' => ['worker' => ['associateOID' => 'G3TEST01']]]]]])]; }
    return ['status' => 200, 'headers' => [], 'body' => '{}'];
});
$check('With credentials the API is ready', adp_api_missing() === []);
[$ok, $msg] = adp_api_send_hire($ready, $admin);
$check('One click: the token, the hire, then the direct deposit', $ok && count($calls) === 3 && str_contains($calls[1]['url'], '/events/hr/v1/worker.hire') && str_contains($calls[2]['url'], 'pay-distribution'), [$msg, array_column($calls, 'url')]);
$check('The client certificate goes with every call', $calls[0]['cert'] === $dir . '/cert.pem' && $calls[1]['key'] === $dir . '/key.pem');
$hire = json_decode((string) $calls[1]['body'], true);
$check('The hire sent carries the legal name, SSN, hire date and rate', ($hire['events'][0]['data']['transform']['worker']['person']['legalName']['familyName1'] ?? '') === 'Ready'
       && ($hire['events'][0]['data']['transform']['worker']['person']['governmentIDs'][0]['idValue'] ?? '') === '212551234'
       && ($hire['events'][0]['data']['transform']['worker']['workAssignment']['baseRemuneration']['hourlyRateAmount']['amountValue'] ?? 0) == 38, $hire);
$check('ADP\'s reference is kept', val("SELECT adp_reference FROM adp_sends WHERE candidate_id = ? AND kind = 'hire' AND status = 'sent'", [$ready]) === 'G3TEST01');
@unlink($dir . '/cert.pem'); @unlink($dir . '/key.pem'); @rmdir($dir);

// P5-M89: onboarding complete - W-4 and I-9 accepted - the person is congratulated once.
require_once __DIR__ . '/test-app/app/deployment.php';
$check('The congratulations go once the W-4 and the I-9 are accepted', deployment_welcome($ready) === true && val('SELECT welcome_sent_at FROM placements WHERE candidate_id = ?', [$ready]) !== null);
$check('...and only once', deployment_welcome($ready) === false);
$check('Somebody whose forms are not accepted is not congratulated', deployment_welcome($waiting) === false);

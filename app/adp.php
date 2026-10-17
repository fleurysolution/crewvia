<?php
/**
 * P5-M79: new hires to ADP - by file or by ADP's API, whichever RSS uses (the owner, 17 Oct 2026: "they have an
 * account, so prepare it for them: what we filled in, one click and it goes to ADP; be ready whatever the
 * choice").
 *
 * A person is ready for ADP once their W-4 and I-9 are accepted and their direct deposit is accepted (or they are
 * paid by cheque), on an assignment that is confirmed or under way, and ADP has not given them an employee ID yet.
 * Their record is built from what was signed in Crewvia: legal name, address, date of birth, Social Security
 * number, hire date, job, pay rate, the W-4 elections and the direct deposit.
 *
 *  - File: one click downloads the new-hire file to import in ADP. It holds Social Security and bank numbers, so
 *    it is made on demand for payroll, never kept, and each download is logged.
 *  - API: with the credentials ADP gives a client (client ID, secret and the certificate of ADP's mutual TLS) set
 *    in the server's configuration under 'adp_api' - never in the repository - one click sends the hire, then the
 *    direct deposit, to ADP. Each call and ADP's answer are logged. The addresses are configurable, as ADP's
 *    API Central gives them for RSS's product (Workforce Now, RUN).
 * Either way, payroll records the employee ID ADP gives, which closes the person off the list.
 */

declare(strict_types=1);

require_once __DIR__ . '/tax-forms.php';
require_once __DIR__ . '/self-service.php';
require_once __DIR__ . '/integrations.php';

/** The newest accepted W-4 and I-9 of a person, read: ['w4' => payload|null, 'i9' => payload|null]. */
function adp_forms(int $candidateId): array
{
    $out = ['w4' => null, 'i9' => null];
    foreach (rows("SELECT * FROM worker_tax_forms WHERE candidate_id = ? AND status = 'accepted' ORDER BY id DESC", [$candidateId]) as $f) {
        if ($out[$f['form']] === null && ($p = tf_payload($f))) {
            $out[$f['form']] = $p;
        }
    }

    return $out;
}

/**
 * The people to create in ADP, each with what is still missing.
 *
 * @return list<array{candidate_id:int,name:string,job:string,start:?string,missing:list<string>,ready:bool,sent:?array}>
 */
function adp_new_hires(): array
{
    $out = [];
    foreach (rows("SELECT c.id, c.full_name, MIN(p.start_date) AS start, MAX(j.title) AS job,
                          ep.adp_employee_id, ep.payment_method,
                          (SELECT COUNT(*) FROM worker_tax_forms f WHERE f.candidate_id = c.id AND f.form = 'w4' AND f.status = 'accepted') AS w4,
                          (SELECT COUNT(*) FROM worker_tax_forms f WHERE f.candidate_id = c.id AND f.form = 'i9' AND f.status = 'accepted') AS i9,
                          (SELECT COUNT(*) FROM worker_bank_details b WHERE b.candidate_id = c.id AND b.status = 'verified') AS bank
                   FROM placements p JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                   LEFT JOIN employee_profiles ep ON ep.candidate_id = c.id
                   WHERE p.status IN ('confirmed','travelling','on_site') AND (ep.adp_employee_id IS NULL OR ep.adp_employee_id = '')
                   GROUP BY c.id, c.full_name, ep.adp_employee_id, ep.payment_method ORDER BY MIN(p.start_date) IS NULL, MIN(p.start_date), c.full_name LIMIT 500") as $r) {
        $missing = [];
        if (! (int) $r['w4']) { $missing[] = t('W-4 accepted'); }
        if (! (int) $r['i9']) { $missing[] = t('I-9 accepted'); }
        if (! (int) $r['bank'] && ($r['payment_method'] ?? '') !== 'paper_check') { $missing[] = t('Direct deposit accepted'); }
        $sent = row("SELECT mode, status, adp_reference, created_at FROM adp_sends WHERE candidate_id = ? AND kind = 'hire' ORDER BY id DESC LIMIT 1", [(int) $r['id']]);
        $out[] = ['candidate_id' => (int) $r['id'], 'name' => (string) $r['full_name'], 'job' => (string) $r['job'], 'start' => $r['start'],
                  'missing' => $missing, 'ready' => ! $missing, 'sent' => $sent ?: null];
    }

    return $out;
}

/** A person's record for ADP, from what was signed. Null when they are not ready. */
function adp_hire_record(int $candidateId): ?array
{
    $f = adp_forms($candidateId);
    if (! $f['w4'] || ! $f['i9']) {
        return null;
    }
    $w4 = $f['w4'];
    $i9 = $f['i9'];
    $p = row("SELECT p.start_date, p.pay_rate, j.title AS job, j.site_state, COALESCE(NULLIF(d.trade, ''), ol.role_title) AS trade, ep.employment_type, ep.salary_per_period, ep.payment_method
              FROM placements p JOIN jobs j ON j.id = p.job_id LEFT JOIN assignment_details d ON d.placement_id = p.id LEFT JOIN job_order_lines ol ON ol.id = p.order_line_id
              LEFT JOIN employee_profiles ep ON ep.candidate_id = p.candidate_id
              WHERE p.candidate_id = ? AND p.status IN ('confirmed','travelling','on_site') ORDER BY p.start_date IS NULL, p.start_date LIMIT 1", [$candidateId]);
    $c = row('SELECT id, email, phone FROM candidates WHERE id = ?', [$candidateId]);
    $deposit = adp_bank($candidateId);

    return [
        'crewvia_id' => $candidateId,
        'first_name' => (string) ($w4['first_name'] ?? $i9['first_name'] ?? ''), 'middle_initial' => (string) ($w4['middle_initial'] ?? $i9['middle_initial'] ?? ''),
        'last_name' => (string) ($w4['last_name'] ?? $i9['last_name'] ?? ''),
        'ssn' => (string) (($w4['ssn'] ?? '') ?: ($i9['ssn'] ?? '')), 'birth_date' => (string) ($i9['birth_date'] ?? ''),
        'address' => (string) ($w4['address'] ?? $i9['address'] ?? ''), 'apartment' => (string) ($w4['apartment'] ?? $i9['apartment'] ?? ''),
        'city' => (string) ($w4['city'] ?? $i9['city'] ?? ''), 'state' => (string) ($w4['state'] ?? $i9['state'] ?? ''), 'zip' => (string) ($w4['zip'] ?? $i9['zip'] ?? ''),
        'email' => (string) ($i9['email'] ?? '') ?: (string) ($c['email'] ?? ''), 'phone' => (string) ($i9['phone'] ?? '') ?: (string) ($c['phone'] ?? ''),
        'hire_date' => (string) ($p['start_date'] ?? ''), 'job_title' => (string) (($p['trade'] ?? '') ?: ($p['job'] ?? '')), 'work_state' => (string) ($p['site_state'] ?? ''),
        'pay_type' => ($p['employment_type'] ?? '') === 'salaried' ? 'Salary' : 'Hourly',
        'rate' => ($p['employment_type'] ?? '') === 'salaried' ? (float) ($p['salary_per_period'] ?? 0) : (float) ($p['pay_rate'] ?? 0),
        'filing_status' => (string) ($w4['filing_status'] ?? ''), 'multiple_jobs' => ! empty($w4['two_jobs']), 'exempt' => ! empty($w4['exempt']),
        'dependents_amount' => (float) ($w4['step3_total'] ?? 0), 'other_income' => (float) ($w4['other_income'] ?? 0),
        'deductions' => (float) ($w4['deductions'] ?? 0), 'extra_withholding' => (float) ($w4['extra_withholding'] ?? 0),
        'payment' => $deposit ? 'Direct deposit' : 'Check',
        'routing_number' => (string) ($deposit['routing_number'] ?? ''), 'account_number' => (string) ($deposit['account_number'] ?? ''),
        'account_type' => 'Checking', 'bank_name' => (string) ($deposit['bank_name'] ?? ''),
    ];
}

/** The accepted direct deposit, read for ADP - each read is in the bank access history. */
function adp_bank(int $candidateId): ?array
{
    $b = row("SELECT encrypted_details FROM worker_bank_details WHERE candidate_id = ? AND status = 'verified'", [$candidateId]);
    if (! $b) {
        return null;
    }
    q('INSERT INTO worker_bank_access (candidate_id, user_id, action) VALUES (?,?,?)', [$candidateId, uid() ?: null, 'read for ADP']);
    try {
        return token_decrypt((string) $b['encrypted_details']) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** The columns of the new-hire file, in order: [heading, record key]. */
function adp_hire_columns(): array
{
    return [['Last Name', 'last_name'], ['First Name', 'first_name'], ['Middle Initial', 'middle_initial'], ['SSN', 'ssn'], ['Birth Date', 'birth_date'],
            ['Address Line 1', 'address'], ['Address Line 2', 'apartment'], ['City', 'city'], ['State', 'state'], ['Zip', 'zip'], ['Email', 'email'], ['Phone', 'phone'],
            ['Hire Date', 'hire_date'], ['Job Title', 'job_title'], ['Work State', 'work_state'], ['Pay Type', 'pay_type'], ['Rate', 'rate'],
            ['Federal Filing Status', 'filing_status'], ['Multiple Jobs', 'multiple_jobs'], ['Exempt', 'exempt'], ['Dependents Amount', 'dependents_amount'],
            ['Other Income', 'other_income'], ['Deductions', 'deductions'], ['Extra Withholding', 'extra_withholding'],
            ['Payment Method', 'payment'], ['Deposit Routing Number', 'routing_number'], ['Deposit Account Number', 'account_number'], ['Deposit Account Type', 'account_type'],
            ['Crewvia ID', 'crewvia_id']];
}

/** The new-hire file for these people (CSV). Formulas are neutralised; nothing is kept. */
function adp_hire_csv(array $records): string
{
    $cell = static function ($v): string {
        $v = is_bool($v) ? ($v ? 'Y' : 'N') : (is_float($v) ? number_format($v, 2, '.', '') : (string) $v);
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false && ! is_numeric($v)) {
            $v = "'" . $v;
        }
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $lines = [implode(',', array_map($cell, array_column(adp_hire_columns(), 0)))];
    foreach ($records as $r) {
        $lines[] = implode(',', array_map(static fn($c) => $cell($r[$c[1]] ?? ''), adp_hire_columns()));
    }

    return implode("\r\n", $lines) . "\r\n";
}

/** Logs a send or a download to ADP. */
function adp_log(string $kind, ?int $candidateId, string $mode, string $status, ?string $reference, ?int $http, string $message, int $by): void
{
    q('INSERT INTO adp_sends (kind, candidate_id, mode, status, adp_reference, http_status, message, created_by) VALUES (?,?,?,?,?,?,?,?)',
      [$kind, $candidateId, $mode, $status, $reference, $http, mb_substr($message, 0, 500), $by]);
}

// ── The API ─────────────────────────────────────────────────────────────

/** ADP's API settings from the server's configuration ('adp_api'), with ADP's usual addresses. */
function adp_api_config(): array
{
    global $config;
    $c = (array) ($config['adp_api'] ?? []);

    return ['client_id' => (string) ($c['client_id'] ?? ''), 'client_secret' => (string) ($c['client_secret'] ?? ''),
            'cert' => (string) ($c['cert'] ?? ''), 'key' => (string) ($c['key'] ?? ''), 'key_password' => (string) ($c['key_password'] ?? ''),
            'token_url' => (string) ($c['token_url'] ?? 'https://accounts.adp.com/auth/oauth/v2/token'),
            'api_base' => rtrim((string) ($c['api_base'] ?? 'https://api.adp.com'), '/'),
            'hire_path' => (string) ($c['hire_path'] ?? '/events/hr/v1/worker.hire'),
            'deposit_path' => (string) ($c['deposit_path'] ?? '/events/payroll/v1/worker.pay-distribution.change')];
}

/** What is missing before the API can be used, or [] when it is set up. */
function adp_api_missing(): array
{
    $c = adp_api_config();
    $missing = [];
    foreach (['client_id' => 'client ID', 'client_secret' => 'client secret', 'cert' => 'certificate', 'key' => 'certificate key'] as $k => $label) {
        if ($c[$k] === '' || (in_array($k, ['cert', 'key'], true) && ! is_readable($c[$k]))) {
            $missing[] = $label;
        }
    }

    return $missing;
}

function adp_api_call(string $method, string $url, array $headers, ?string $body): array
{
    $c = adp_api_config();

    return integration_transport()(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => 30,
                                    'cert' => $c['cert'], 'key' => $c['key'], 'key_password' => $c['key_password']]);
}

/** An access token from ADP, or the refusal. @return array{0:?string,1:?string} [token, why] */
function adp_api_token(): array
{
    if ($m = adp_api_missing()) {
        return [null, t('The ADP connection is not set up: :missing.', ['missing' => implode(', ', $m)])];
    }
    $c = adp_api_config();
    $r = adp_api_call('POST', $c['token_url'], ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                      http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]));
    $token = json_decode((string) ($r['body'] ?? ''), true)['access_token'] ?? null;

    return $token ? [(string) $token, null] : [null, t('ADP refused the connection (:status). Check the credentials and the certificate.', ['status' => (int) ($r['status'] ?? 0)])];
}

/** The hire event ADP expects, from the person's record. */
function adp_api_hire_payload(array $r): array
{
    return ['events' => [['data' => ['transform' => ['worker' => [
        'person' => [
            'legalName' => ['givenName' => $r['first_name'], 'middleName' => $r['middle_initial'], 'familyName1' => $r['last_name']],
            'birthDate' => $r['birth_date'],
            'governmentIDs' => [['idValue' => $r['ssn'], 'nameCode' => ['codeValue' => 'SSN']]],
            'legalAddress' => ['lineOne' => $r['address'], 'lineTwo' => $r['apartment'], 'cityName' => $r['city'],
                               'countrySubdivisionLevel1' => ['codeValue' => $r['state']], 'postalCode' => $r['zip'], 'countryCode' => 'US'],
            'communication' => ['emails' => [['emailUri' => $r['email']]], 'mobiles' => [['formattedNumber' => $r['phone']]]],
        ],
        'workerDates' => ['originalHireDate' => $r['hire_date']],
        'workAssignment' => [
            'hireDate' => $r['hire_date'], 'jobTitle' => $r['job_title'],
            'payCycleCode' => ['codeValue' => 'W'],
            'baseRemuneration' => $r['pay_type'] === 'Salary'
                ? ['payPeriodRateAmount' => ['amountValue' => $r['rate'], 'currencyCode' => 'USD']]
                : ['hourlyRateAmount' => ['amountValue' => $r['rate'], 'currencyCode' => 'USD']],
            'homeWorkLocation' => ['address' => ['countrySubdivisionLevel1' => ['codeValue' => $r['work_state']], 'countryCode' => 'US']],
        ],
        'customFieldGroup' => ['stringFields' => [['nameCode' => ['codeValue' => 'CrewviaID'], 'stringValue' => (string) $r['crewvia_id']]]],
    ]]]]]];
}

/** The direct deposit ADP expects, once the worker is in ADP. */
function adp_api_deposit_payload(array $r, string $associateOid): array
{
    return ['events' => [['data' => ['eventContext' => ['worker' => ['associateOID' => $associateOid]], 'transform' => ['payDistribution' => [
        'distributionInstructions' => [['depositAccount' => ['financialParty' => ['routingTransitID' => ['idValue' => $r['routing_number']]],
                                                             'financialAccount' => ['accountNumber' => $r['account_number'], 'typeCode' => ['codeValue' => 'C']]],
                                        'distributionPercentage' => 100, 'precedenceCode' => ['codeValue' => '1']]],
    ]]]]]];
}

/**
 * Sends one person to ADP: the hire, then the direct deposit. Records ADP's reference and answer.
 * @return array{0:bool,1:string} [sent, what happened]
 */
function adp_api_send_hire(int $candidateId, int $by): array
{
    $r = adp_hire_record($candidateId);
    if (! $r) {
        return [false, t('Not ready for ADP: the W-4 and the I-9 must be accepted.')];
    }
    [$token, $why] = adp_api_token();
    if (! $token) {
        adp_log('hire', $candidateId, 'api', 'failed', null, null, (string) $why, $by);
        return [false, (string) $why];
    }
    $c = adp_api_config();
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'];
    $res = adp_api_call('POST', $c['api_base'] . $c['hire_path'], $headers, json_encode(adp_api_hire_payload($r), JSON_UNESCAPED_SLASHES));
    $answer = json_decode((string) ($res['body'] ?? ''), true) ?: [];
    $oid = $answer['events'][0]['data']['output']['worker']['associateOID'] ?? ($answer['associateOID'] ?? null);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300 || ! $oid) {
        $msg = (string) ($answer['confirmMessage']['processMessages'][0]['userMessage']['messageTxt'] ?? $answer['message'] ?? ('HTTP ' . (int) $res['status']));
        adp_log('hire', $candidateId, 'api', 'failed', null, (int) $res['status'], $msg, $by);
        return [false, t('ADP did not accept the hire: :msg', ['msg' => $msg])];
    }
    adp_log('hire', $candidateId, 'api', 'sent', (string) $oid, (int) $res['status'], 'worker.hire accepted', $by);
    if ($r['routing_number'] !== '' && $r['account_number'] !== '') {
        $dep = adp_api_call('POST', $c['api_base'] . $c['deposit_path'], $headers, json_encode(adp_api_deposit_payload($r, (string) $oid), JSON_UNESCAPED_SLASHES));
        $ok = (int) $dep['status'] >= 200 && (int) $dep['status'] < 300;
        adp_log('deposit', $candidateId, 'api', $ok ? 'sent' : 'failed', (string) $oid, (int) $dep['status'], $ok ? 'pay distribution accepted' : 'pay distribution refused', $by);
        if (! $ok) {
            return [true, t('Sent to ADP (:ref), but ADP refused the direct deposit: enter it in ADP.', ['ref' => $oid])];
        }
    }
    integration_event('adp', 'worker hire', 'ok', 'candidate:' . $candidateId, 'Sent to ADP, reference ' . $oid);

    return [true, t('Sent to ADP (:ref).', ['ref' => $oid])];
}

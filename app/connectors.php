<?php
/**
 * External payroll and applicant-tracking connectors (P4-M05), sandbox
 * before live.
 *
 *   adp_run        ADP, the payroll provider RSS uses. Crewvia has no ADP
 *                  API access (that needs an ADP Marketplace partnership):
 *                  the connector is the ADP RUN import file. In sandbox the
 *                  file is marked TEST, for ADP's preview or a test
 *                  company; once payroll records that ADP accepted one,
 *                  an administrator may switch it live, recording who at
 *                  RSS authorized it. Every file is kept with its SHA-256,
 *                  and ADP's payroll register can be laid beside Crewvia's
 *                  frozen pay to find any difference.
 *   ats:<channel>  an applicant-tracking partner sending applications to
 *                  /recruiting-webhook. In sandbox an event is verified
 *                  and answered, and nothing is created; live creates the
 *                  application for a recruiter to review.
 *   screening      no background-screening provider is authorized, so none
 *                  is connected: cases are run by hand on /checks.
 *
 * Channels configured before this module were already live: they are
 * recorded live. A channel added later starts in sandbox. Every change of
 * mode, test and import is in the integration audit trail.
 */

declare(strict_types=1);

require_once __DIR__ . '/integrations.php';

function connector_modes_all(): array
{
    $out = [];
    foreach (rows('SELECT m.*, u.name AS authorized_name FROM connector_modes m LEFT JOIN users u ON u.id = m.authorized_by') as $r) {
        $out[$r['connector']] = $r;
    }

    return $out;
}

/** A connector's mode; one never recorded starts in sandbox. */
function connector_mode(string $connector): string
{
    return (string) (val('SELECT mode FROM connector_modes WHERE connector = ?', [$connector]) ?? 'sandbox');
}

function connector_provider(string $connector): string
{
    return str_starts_with($connector, 'ats:') ? 'ats' : ($connector === 'twilio' ? 'twilio' : 'adp');
}

/** The connectors this installation has: ADP, and each configured partner channel. */
function connector_list(): array
{
    global $config;
    $modes = connector_modes_all();
    $list = ['adp_run' => ['kind' => 'payroll', 'name' => 'ADP RUN import file', 'configured' => true]];
    foreach (($config['recruiting_webhooks'] ?? []) as $channel => $entry) {
        if (is_string($channel) && preg_match('/^[a-z0-9_-]{1,60}$/', $channel)) {
            $list['ats:' . $channel] = ['kind' => 'ats', 'name' => 'Applicant tracking partner: ' . $channel, 'configured' => ! empty($entry['enabled'])];
        }
    }
    // P5-M08: Twilio, configured once its credentials are entered on Integrations.
    try {
        require_once __DIR__ . '/twilio.php';
        $list['twilio'] = ['kind' => 'messaging', 'name' => 'Twilio: texts and calls from Crewvia', 'configured' => tw_configured()];
    } catch (Throwable $e) {
        // Before the P5-M08 upgrade there is no Twilio to list.
    }
    foreach ($list as $k => &$c) {
        $m = $modes[$k] ?? [];
        $c += ['mode' => $m['mode'] ?? 'sandbox', 'authorization_ref' => $m['authorization_ref'] ?? null, 'authorized_name' => $m['authorized_name'] ?? null,
               'authorized_at' => $m['authorized_at'] ?? null, 'sandbox_passed_at' => $m['sandbox_passed_at'] ?? null, 'sandbox_note' => $m['sandbox_note'] ?? null, 'note' => $m['note'] ?? null];
    }
    unset($c);

    return $list;
}

/** A sandbox trial passed: the evidence needed before going live. */
function connector_sandbox_passed(string $connector, string $note): void
{
    $kind = str_starts_with($connector, 'ats:') ? 'ats' : 'payroll';
    q("INSERT INTO connector_modes (connector, kind, mode, sandbox_passed_at, sandbox_note) VALUES (?, ?, 'sandbox', NOW(), ?)
       ON DUPLICATE KEY UPDATE sandbox_passed_at = COALESCE(sandbox_passed_at, NOW()), sandbox_note = COALESCE(sandbox_note, VALUES(sandbox_note))",
      [$connector, $kind, mb_substr($note, 0, 500)]);
    integration_event(connector_provider($connector), 'sandbox', 'ok', $connector, $note);
}

/**
 * Change a connector's mode. Live needs a sandbox trial that passed and
 * the reference of who at RSS authorized it (an agreement, a ticket, an
 * email). Returns the refusal, or null.
 */
function connector_set_mode(string $connector, string $mode, string $authorization, string $note): ?string
{
    $list = connector_list();
    if (! isset($list[$connector])) {
        return t('That connector does not exist.');
    }
    if (! in_array($mode, ['disabled', 'sandbox', 'live'], true)) {
        return t('Choose disabled, sandbox or live.');
    }
    $c = $list[$connector];
    $authorization = trim($authorization);
    if ($mode === 'live') {
        if (! $c['configured']) {
            return t('This connector is not configured on the server: it cannot go live.');
        }
        if ($c['sandbox_passed_at'] === null) {
            return t('A sandbox trial must pass before this connector goes live.');
        }
        if (mb_strlen($authorization) < 3 || mb_strlen($authorization) > 190) {
            return t('Record who at RSS authorized going live: an agreement, a ticket or an email reference.');
        }
    }
    q("INSERT INTO connector_modes (connector, kind, mode, authorization_ref, authorized_by, authorized_at, note, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE mode = VALUES(mode), authorization_ref = IF(VALUES(mode) = 'live', VALUES(authorization_ref), authorization_ref),
         authorized_by = IF(VALUES(mode) = 'live', VALUES(authorized_by), authorized_by), authorized_at = IF(VALUES(mode) = 'live', VALUES(authorized_at), authorized_at),
         note = VALUES(note), updated_by = VALUES(updated_by), updated_at = NOW()",
      [$connector, $c['kind'], $mode, $mode === 'live' ? $authorization : null, $mode === 'live' ? (uid() ?: null) : null, $mode === 'live' ? date('Y-m-d H:i:s') : null,
       trim($note) === '' ? null : mb_substr(trim($note), 0, 500), uid() ?: null]);
    integration_event(connector_provider($connector), 'mode', 'ok', $connector, ucfirst($mode) . ($mode === 'live' ? ', authorized by: ' . $authorization : '') . (trim($note) !== '' ? '. ' . trim($note) : ''));

    return null;
}

// ── ADP ──────────────────────────────────────────────────────────────────

/** Keep a payroll file produced. Returns its id. */
function adp_export_record(string $kind, string $week, ?int $jobId, string $csv, int $rows, int $employees): int
{
    q('INSERT INTO payroll_exports (kind, week_ending, job_id, csv, sha256, row_count, employee_count, created_by) VALUES (?,?,?,?,?,?,?,?)',
      [$kind, $week, $jobId, $csv, hash('sha256', $csv), $rows, $employees, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    if ($kind !== 'review') {
        integration_event('adp', $kind === 'adp_test' ? 'test file' : 'import file', 'ok', 'export:' . $id, 'Week ending ' . $week . ', ' . $employees . ' employee(s), ' . $rows . ' line(s), SHA-256 ' . substr(hash('sha256', $csv), 0, 16));
    }

    return $id;
}

/**
 * Record what ADP said when the file was imported, with ADP's own batch
 * reference. A test file ADP accepted is the sandbox trial passing.
 */
function adp_confirm_import(int $exportId, string $batchRef, string $result, string $note): ?string
{
    $e = row('SELECT * FROM payroll_exports WHERE id = ? FOR UPDATE', [$exportId]);
    if (! $e || $e['kind'] === 'review') {
        return t('That ADP file does not exist.');
    }
    if ($e['import_result'] !== null) {
        return t('That file\'s import is already recorded.');
    }
    $batchRef = trim($batchRef);
    if (mb_strlen($batchRef) < 3 || mb_strlen($batchRef) > 120 || preg_match('/^[=+@-]/', $batchRef)) {
        return t('Give the batch or confirmation reference ADP showed for the import.');
    }
    if (! in_array($result, ['accepted', 'rejected'], true)) {
        return t('Say whether ADP accepted the file.');
    }
    if ($result === 'rejected' && mb_strlen(trim($note)) < 3) {
        return t('Say what ADP rejected, so it can be corrected.');
    }
    q('UPDATE payroll_exports SET import_result = ?, adp_batch_ref = ?, import_note = ?, confirmed_by = ?, confirmed_at = NOW() WHERE id = ?',
      [$result, $batchRef, trim($note) === '' ? null : mb_substr(trim($note), 0, 500), uid() ?: null, $exportId]);
    integration_event('adp', 'import', $result === 'accepted' ? 'ok' : 'refused', 'export:' . $exportId, 'ADP ' . $result . ' the ' . ($e['kind'] === 'adp_test' ? 'test' : 'live') . ' file, batch ' . $batchRef . (trim($note) !== '' ? ': ' . trim($note) : ''));
    if ($e['kind'] === 'adp_test' && $result === 'accepted') {
        connector_sandbox_passed('adp_run', 'ADP accepted test file ' . $exportId . ' (batch ' . $batchRef . ') for the week ending ' . $e['week_ending']);
    }

    return null;
}

/** Crewvia's gross pay for a week by ADP employee id, from the frozen sheets and the period's adjustments. */
function adp_crewvia_gross(string $week): array
{
    require_once __DIR__ . '/pay-periods.php';
    $out = [];
    foreach (rows("SELECT e.adp_employee_id, c.full_name, s.result_json FROM timesheets t JOIN placements p ON p.id = t.placement_id JOIN candidates c ON c.id = p.candidate_id
                   JOIN pay_snapshots s ON s.timesheet_id = t.id LEFT JOIN employee_profiles e ON e.candidate_id = c.id
                   WHERE t.week_ending = ? AND t.status IN ('approved','paid')", [$week]) as $r) {
        $id = trim((string) $r['adp_employee_id']) !== '' ? (string) $r['adp_employee_id'] : '(no ADP id) ' . $r['full_name'];
        $out[$id] ??= ['name' => $r['full_name'], 'gross' => 0.0];
        $out[$id]['gross'] = round($out[$id]['gross'] + payroll_sheet_figures(json_decode((string) $r['result_json'], true) ?: [])['gross'], 2);
    }
    foreach (rows("SELECT e.adp_employee_id, c.full_name, SUM(a.amount) AS amount FROM payroll_adjustments a JOIN payroll_runs r ON r.id = a.run_id
                   JOIN candidates c ON c.id = a.candidate_id LEFT JOIN employee_profiles e ON e.candidate_id = c.id
                   WHERE r.week_ending = ? AND a.kind <> 'reimbursement' GROUP BY e.adp_employee_id, c.full_name", [$week]) as $r) {
        $id = trim((string) $r['adp_employee_id']) !== '' ? (string) $r['adp_employee_id'] : '(no ADP id) ' . $r['full_name'];
        $out[$id] ??= ['name' => $r['full_name'], 'gross' => 0.0];
        $out[$id]['gross'] = round($out[$id]['gross'] + (float) $r['amount'], 2);
    }

    return $out;
}

/**
 * Lay ADP's payroll register for a week (a CSV ADP exports) beside
 * Crewvia's gross pay, by ADP employee id. The columns are named by the
 * person reconciling, since registers differ by ADP product and setup.
 * Returns [reconciliation id, refusal].
 */
function adp_reconcile(string $week, string $csv, string $idColumn, string $grossColumn): array
{
    if (! valid_date($week) || date('w', strtotime($week)) !== '6') {
        return [0, t('Choose the Saturday the pay week ends on.')];
    }
    if ($csv === '' || strlen($csv) > 2 * 1024 * 1024) {
        return [0, t('Upload ADP\'s payroll register as a CSV file of at most 2 MB.')];
    }
    $lines = array_map(fn($l) => str_getcsv($l, ',', '"', ''), preg_split('/\r\n|\n|\r/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
    $head = array_map(fn($h) => mb_strtolower(trim((string) $h)), $lines[0] ?? []);
    $iCol = array_search(mb_strtolower(trim($idColumn)), $head, true);
    $gCol = array_search(mb_strtolower(trim($grossColumn)), $head, true);
    if ($iCol === false || $gCol === false) {
        return [0, t('The file has no column named ":id" or ":gross". Give the names exactly as in its first line.', ['id' => $idColumn, 'gross' => $grossColumn])];
    }
    if (count($lines) > 5001) {
        return [0, t('At most 5,000 employees in one register.')];
    }
    $adp = [];
    foreach (array_slice($lines, 1) as $n => $l) {
        $id = trim((string) ($l[$iCol] ?? ''));
        if ($id === '') {
            continue;
        }
        $raw = str_replace([',', '$', ' '], '', (string) ($l[$gCol] ?? ''));
        if (preg_match('/^\((.*)\)$/', $raw, $m)) {
            $raw = '-' . $m[1];
        }
        if (! is_numeric($raw)) {
            return [0, t('Line :n: the gross pay ":v" is not an amount.', ['n' => $n + 2, 'v' => mb_substr((string) ($l[$gCol] ?? ''), 0, 30)])];
        }
        $adp[$id] = round(($adp[$id] ?? 0) + (float) $raw, 2);
    }
    $ours = adp_crewvia_gross($week);
    $rows = [];
    foreach (array_unique(array_merge(array_keys($ours), array_keys($adp))) as $id) {
        $c = $ours[$id]['gross'] ?? null;
        $a = $adp[$id] ?? null;
        $state = $c === null ? 'only_in_adp' : ($a === null ? 'only_in_crewvia' : (abs($c - $a) < 0.005 ? 'matched' : 'different'));
        $rows[] = ['id' => (string) $id, 'name' => $ours[$id]['name'] ?? '', 'crewvia' => $c, 'adp' => $a, 'difference' => $c !== null && $a !== null ? round($a - $c, 2) : null, 'state' => $state];
    }
    usort($rows, fn($x, $y) => [$x['state'] === 'matched', $x['id']] <=> [$y['state'] === 'matched', $y['id']]);
    $matched = count(array_filter($rows, fn($r) => $r['state'] === 'matched'));
    $diff = count($rows) - $matched;
    q('INSERT INTO adp_reconciliations (week_ending, file_sha256, matched, differences, result_json, created_by) VALUES (?,?,?,?,?,?)',
      [$week, hash('sha256', $csv), $matched, $diff, json_encode($rows), uid() ?: null]);
    $id = (int) db()->lastInsertId();
    integration_event('adp', 'reconcile', $diff === 0 ? 'ok' : 'failed', 'reconciliation:' . $id, 'Week ending ' . $week . ': ' . $matched . ' matched, ' . $diff . ' to look into');

    return [$id, null];
}

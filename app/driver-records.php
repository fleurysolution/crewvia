<?php
/**
 * Driving-record checks (P5-M09), run in the providers' own portals and
 * recorded in Crewvia "for processing and records":
 *
 *   mvr            the motor vehicle record, from MVRNOW (it also returns
 *                  whether the licence is commercial, its class,
 *                  endorsements and the medical certificate)
 *   clearinghouse  the FMCSA Drug and Alcohol Clearinghouse: a full query
 *                  before a CDL driver drives (the driver consents in the
 *                  Clearinghouse), a limited query every year after (on a
 *                  consent the employer keeps)
 *   psp            the FMCSA Pre-Employment Screening Program: five years of
 *                  crashes and three of inspections, on the driver's signed
 *                  disclosure and authorization
 *
 * No provider is connected: there is no MVRNOW interface to call, and the
 * FMCSA systems are used through their portals. Crewvia keeps the consent,
 * opens the right site, and keeps the result, the decision and when the
 * check is due again (the MVR and the Clearinghouse yearly).
 *
 * Rules kept here, as records, not as legal advice:
 *   - nothing is ordered without the consent it needs, recorded with its
 *     date;
 *   - a licence that is not valid, a Clearinghouse "prohibited", or
 *     information found in a full query, can never be recorded as
 *     acceptable;
 *   - information found in a limited query opens the full query that must
 *     follow it;
 *   - a decision against the person on an MVR or a PSP (reports from a
 *     consumer reporting agency) needs the date the pre-adverse notice was
 *     sent;
 *   - a valid commercial licence, and a medical certificate, are written
 *     into the person's qualifications, verified, with their expiry, never
 *     shortening one already verified for longer.
 *
 * Recruiters (the HR desk) and administrators only. The licence number is
 * sealed; only its last four are ever shown.
 */

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';

const DR_LINKS = ['mvr' => 'https://www.mvrnow.com/', 'clearinghouse' => 'https://clearinghouse.fmcsa.dot.gov/', 'psp' => 'https://www.psp.fmcsa.dot.gov/'];
const DR_ENDORSEMENTS = ['H', 'N', 'P', 'S', 'T', 'X'];
const DR_STATES = ['AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY',
                   'NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
const DR_DUE_SOON_DAYS = 30;

/** @return array<string,string> */
function dr_kinds(): array
{
    return ['mvr' => t('Motor vehicle record (MVRNOW)'), 'clearinghouse' => t('FMCSA Drug and Alcohol Clearinghouse'), 'psp' => t('FMCSA Pre-Employment Screening (PSP)')];
}

/** @return array<string,string> */
function dr_provider_names(): array
{
    return ['mvr' => 'MVRNOW', 'clearinghouse' => t('the Clearinghouse'), 'psp' => 'PSP'];
}

/** @return array<string,string> */
function dr_consents(): array
{
    return ['signed_form' => t('Signed consent form kept by the agency'), 'clearinghouse_portal' => t('Consent given by the driver in the Clearinghouse'),
            'psp_form' => t('Signed PSP disclosure and authorization')];
}

/** @return array<string,string> */
function dr_license_statuses(): array
{
    return ['valid' => t('Valid'), 'suspended' => t('Suspended'), 'revoked' => t('Revoked'), 'expired' => t('Expired'), 'cancelled' => t('Cancelled'), 'not_found' => t('Not found')];
}

/** @return array<string,string> */
function dr_clearinghouse_results(): array
{
    return ['no_information' => t('No information found'), 'information_found' => t('Information found'), 'prohibited' => t('Prohibited from driving')];
}

/** @return array<string,string> */
function dr_decisions(): array
{
    return ['acceptable' => t('Acceptable'), 'review' => t('Needs review'), 'not_acceptable' => t('Not acceptable')];
}

/** Which consent each check takes. */
function dr_consent_allowed(string $kind, ?string $queryType): array
{
    return match ($kind) {
        'mvr'           => ['signed_form'],
        'psp'           => ['psp_form'],
        'clearinghouse' => $queryType === 'full' ? ['clearinghouse_portal'] : ['signed_form'],
        default         => [],
    };
}

/** Where each check is run: the provider's own site, an administrator may change it (HTTPS only). */
function dr_links(): array
{
    $out = DR_LINKS;
    foreach (array_keys(DR_LINKS) as $k) {
        $v = (string) val('SELECT setting_value FROM platform_settings WHERE setting_key = ?', ['driver_link_' . $k]);
        if ($v !== '') {
            $out[$k] = $v;
        }
    }

    return $out;
}

function dr_links_save(array $in): ?string
{
    foreach (array_keys(DR_LINKS) as $k) {
        $v = trim((string) ($in[$k] ?? ''));
        if ($v !== '' && (! filter_var($v, FILTER_VALIDATE_URL) || parse_url($v, PHP_URL_SCHEME) !== 'https')) {
            return t('Each link is an HTTPS address.');
        }
        q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['driver_link_' . $k, $v ?: DR_LINKS[$k]]);
    }
    log_activity('changed the driving-record links', 'platform_settings', 0, '');

    return null;
}

function dr_license(array $check): string
{
    return (string) crypto_open((string) base64_decode((string) $check['encrypted_license_number'], true));
}

// ── ordering ────────────────────────────────────────────────────────────

/** @return array{0:?string,1:int} */
function dr_request(array $in, int $by): array
{
    $cid = (int) ($in['candidate_id'] ?? 0);
    $kind = (string) ($in['kind'] ?? '');
    $state = strtoupper(trim((string) ($in['state'] ?? '')));
    $license = strtoupper(preg_replace('/[\s-]/', '', (string) ($in['license_number'] ?? '')));
    $consent = (string) ($in['consent_method'] ?? '');
    $consentOn = (string) ($in['consent_on'] ?? '');
    $reference = trim((string) ($in['consent_reference'] ?? ''));
    $queryType = $kind === 'clearinghouse' ? (string) ($in['query_type'] ?? '') : null;
    if (! row('SELECT id FROM candidates WHERE id = ?', [$cid])) { return [t('That person does not exist.'), 0]; }
    if (! isset(dr_kinds()[$kind])) { return [t('Choose which check.'), 0]; }
    if ($kind === 'clearinghouse' && ! in_array($queryType, ['full', 'limited'], true)) { return [t('Choose a full or a limited query.'), 0]; }
    if (! in_array($state, DR_STATES, true)) { return [t('Choose the state that issued the licence.'), 0]; }
    if (! preg_match('/^[A-Z0-9]{4,20}$/', $license)) { return [t('The licence number is 4 to 20 letters and digits.'), 0]; }
    if (! in_array($consent, dr_consent_allowed($kind, $queryType), true)) {
        return [t('This check needs: :consent.', ['consent' => implode(', ', array_map(fn($c) => dr_consents()[$c], dr_consent_allowed($kind, $queryType)))]), 0];
    }
    if (! valid_date($consentOn) || $consentOn > date('Y-m-d')) { return [t('Give the date the consent was given, today or earlier.'), 0]; }
    if (mb_strlen($reference) > 190) { return [t('A field is too long.'), 0]; }
    if (val("SELECT COUNT(*) FROM driver_checks WHERE candidate_id = ? AND kind = ? AND status = 'requested'", [$cid, $kind])) {
        return [t('That check is already waiting for its result.'), 0];
    }
    q('INSERT INTO driver_checks (candidate_id, kind, state, encrypted_license_number, license_last4, consent_method, consent_on, consent_reference, query_type, requested_by)
       VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$cid, $kind, $state, base64_encode(crypto_seal($license)), substr($license, -4), $consent, $consentOn, $reference ?: null, $queryType, $by]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)', [$cid, $by, 'driver_check', dr_kinds()[$kind] . ': ' . t('requested')]);
    log_activity('requested a driving-record check', 'driver_check', $id, $kind);

    return [null, $id];
}

function dr_cancel(int $id, string $reason, int $by): ?string
{
    $reason = trim($reason);
    $c = row('SELECT * FROM driver_checks WHERE id = ?', [$id]);
    if (! $c || $c['status'] !== 'requested') { return t('Only a check waiting for its result is cancelled.'); }
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) { return t('Say why, in 3 to 500 characters.'); }
    q("UPDATE driver_checks SET status = 'cancelled', notes = ?, recorded_by = ?, recorded_at = NOW() WHERE id = ?", [$reason, $by, $id]);

    return null;
}

// ── the result ──────────────────────────────────────────────────────────

/** Write a qualification as verified, never shortening one verified for longer. Returns its id, or 0. */
function dr_qualify(int $candidateId, string $typeName, ?string $expires, string $detail, string $evidence, int $by): int
{
    $type = (int) val('SELECT id FROM qualification_types WHERE name = ?', [$typeName]);
    if (! $type) {
        return 0;
    }
    $existing = row('SELECT id, status, expires_on FROM candidate_qualifications WHERE candidate_id = ? AND type_id = ?', [$candidateId, $type]);
    if ($existing && $existing['status'] === 'verified' && ($existing['expires_on'] === null || ($expires !== null && $existing['expires_on'] >= $expires))) {
        return 0;
    }
    q("INSERT INTO candidate_qualifications (candidate_id, type_id, detail, issued_on, expires_on, evidence_reference, status, reviewed_by, reviewed_at)
       VALUES (?,?,?,CURDATE(),?,?,'verified',?,NOW())
       ON DUPLICATE KEY UPDATE detail = VALUES(detail), expires_on = VALUES(expires_on), evidence_reference = VALUES(evidence_reference),
                               status = 'verified', reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()",
      [$candidateId, $type, mb_substr($detail, 0, 190), $expires, mb_substr($evidence, 0, 190), $by]);
    $qid = (int) val('SELECT id FROM candidate_qualifications WHERE candidate_id = ? AND type_id = ?', [$candidateId, $type]);
    q('INSERT INTO qualification_events (qualification_id, user_id, action, note) VALUES (?,?,?,?)',
      [$qid, $by, 'verified', json_encode(['by' => 'driving record', 'evidence' => $evidence, 'expires_on' => $expires], JSON_UNESCAPED_UNICODE)]);

    return $qid;
}

function dr_count(array $in, string $key, int $max): ?int
{
    $v = trim((string) ($in[$key] ?? ''));
    return ctype_digit($v) && (int) $v <= $max ? (int) $v : null;
}

/** Record what the provider returned, and what the agency decided. */
function dr_record(int $id, array $in, int $by): ?string
{
    $c = row('SELECT * FROM driver_checks WHERE id = ?', [$id]);
    if (! $c) { return t('That check does not exist.'); }
    if ($c['status'] !== 'requested') { return t('That check is already recorded or cancelled.'); }
    $on = (string) ($in['result_on'] ?? '');
    $decision = (string) ($in['decision'] ?? '');
    $notes = trim((string) ($in['notes'] ?? ''));
    $ref = trim((string) ($in['provider_reference'] ?? ''));
    $adverse = trim((string) ($in['adverse_notice_on'] ?? ''));
    if (! valid_date($on) || $on > date('Y-m-d') || $on < (string) $c['consent_on']) { return t('Give the date of the result: after the consent, and today or earlier.'); }
    if (! isset(dr_decisions()[$decision])) { return t('Record the decision.'); }
    if (mb_strlen($ref) > 80 || mb_strlen($notes) > 1000) { return t('A field is too long.'); }
    $set = ['result_on' => $on, 'provider_reference' => $ref ?: null, 'decision' => $decision, 'notes' => $notes ?: null];

    if ($c['kind'] === 'mvr') {
        $status = (string) ($in['license_status'] ?? '');
        if (! isset(dr_license_statuses()[$status])) { return t('Record the licence status.'); }
        $cdl = ! empty($in['cdl']);
        $class = $cdl ? (string) ($in['cdl_class'] ?? '') : null;
        $end = $cdl ? array_values(array_unique(array_filter(str_split(strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($in['endorsements'] ?? ''))))))) : [];
        $expires = trim((string) ($in['license_expires_on'] ?? ''));
        $medical = trim((string) ($in['medical_expires_on'] ?? ''));
        if ($cdl && ! in_array($class, ['A', 'B', 'C'], true)) { return t('Give the class of the commercial licence: A, B or C.'); }
        if (array_diff($end, DR_ENDORSEMENTS)) { return t('Endorsements are letters among H, N, P, S, T and X.'); }
        if ($expires !== '' && ! valid_date($expires)) { return t('Give the licence expiry as a date.'); }
        if ($cdl && $status === 'valid' && $expires === '') { return t('A valid commercial licence needs its expiry date.'); }
        if ($medical !== '' && ! valid_date($medical)) { return t('Give the medical certificate expiry as a date.'); }
        $violations = dr_count($in, 'violations_3y', 99);
        $accidents = dr_count($in, 'accidents_3y', 99);
        if ($violations === null || $accidents === null) { return t('Give the violations and accidents of the last 3 years, 0 to 99.'); }
        if ($status !== 'valid' && $decision === 'acceptable') { return t('A licence that is not valid cannot be acceptable.'); }
        $set += ['license_status' => $status, 'cdl' => $cdl ? 1 : 0, 'cdl_class' => $class, 'endorsements' => $end ? implode('', $end) : null,
                 'restrictions' => mb_substr(trim((string) ($in['restrictions'] ?? '')), 0, 190) ?: null, 'license_expires_on' => $expires ?: null,
                 'medical_expires_on' => $medical ?: null, 'violations_3y' => $violations, 'accidents_3y' => $accidents, 'major_violation' => ! empty($in['major_violation']) ? 1 : 0];
    } elseif ($c['kind'] === 'clearinghouse') {
        $result = (string) ($in['clearinghouse_result'] ?? '');
        if (! isset(dr_clearinghouse_results()[$result])) { return t('Record what the Clearinghouse returned.'); }
        if ($result === 'prohibited' && $decision !== 'not_acceptable') { return t('A driver prohibited by the Clearinghouse cannot drive: the decision is not acceptable.'); }
        if ($result === 'information_found' && $decision === 'acceptable') { return t('Information found in the Clearinghouse cannot be recorded as acceptable.'); }
        $set += ['clearinghouse_result' => $result];
    } else {
        $crashes = dr_count($in, 'crashes_5y', 255);
        $inspections = dr_count($in, 'inspections_3y', 9999);
        $oos = dr_count($in, 'oos_3y', 9999);
        if ($crashes === null || $inspections === null || $oos === null) { return t('Give the crashes (5 years), inspections and out-of-service violations (3 years) as numbers.'); }
        if ($oos > $inspections) { return t('Out-of-service violations come from inspections: there cannot be more of them.'); }
        $set += ['crashes_5y' => $crashes, 'inspections_3y' => $inspections, 'oos_3y' => $oos];
    }
    if ($decision === 'not_acceptable') {
        if (mb_strlen($notes) < 10) { return t('Say why it is not acceptable, in at least 10 characters.'); }
        if (in_array($c['kind'], ['mvr', 'psp'], true)) {
            if (! valid_date($adverse) || $adverse < $on || $adverse > date('Y-m-d')) {
                return t('A decision against the person on a consumer report needs the date the pre-adverse notice was sent, with a copy of the report.');
            }
            $set['adverse_notice_on'] = $adverse;
        }
    }
    $set['next_due_on'] = in_array($c['kind'], ['mvr', 'clearinghouse'], true) ? date('Y-m-d', strtotime($on . ' +12 months')) : null;

    db()->beginTransaction();
    try {
        $cols = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($set)));
        q("UPDATE driver_checks SET $cols, status = 'completed', recorded_by = ?, recorded_at = NOW() WHERE id = ?", [...array_values($set), $by, $id]);
        $cid = (int) $c['candidate_id'];
        if ($c['kind'] === 'mvr') {
            $evidence = 'MVR ' . $c['state'] . ' ' . $on . ($ref !== '' ? ' ' . $ref : '');
            if ($set['cdl'] && $set['license_status'] === 'valid') {
                dr_qualify($cid, "Commercial driver's licence (CDL)", $set['license_expires_on'], 'Class ' . $set['cdl_class'] . ($set['endorsements'] ? ' · ' . $set['endorsements'] : '') . ' · ' . $c['state'] . ' …' . $c['license_last4'], $evidence, $by);
            }
            if ($set['medical_expires_on']) {
                dr_qualify($cid, 'DOT medical certificate', $set['medical_expires_on'], 'From the MVR', $evidence, $by);
            }
        }
        // A limited query that finds something must be followed by a full query, on the driver's consent in the Clearinghouse.
        if ($c['kind'] === 'clearinghouse' && $c['query_type'] === 'limited' && $set['clearinghouse_result'] === 'information_found') {
            q("INSERT INTO driver_checks (candidate_id, kind, state, encrypted_license_number, license_last4, consent_method, consent_on, consent_reference, query_type, requested_by, follows_check_id)
               VALUES (?, 'clearinghouse', ?, ?, ?, 'clearinghouse_portal', ?, ?, 'full', ?, ?)",
              [$cid, $c['state'], $c['encrypted_license_number'], $c['license_last4'], $on, 'The driver must consent in the Clearinghouse before the full query', $by, $id]);
        }
        q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
          [$cid, $by, 'driver_check', dr_kinds()[$c['kind']] . ': ' . dr_decisions()[$decision]]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        throw $e;
    }
    log_activity('recorded a driving-record check', 'driver_check', $id, $c['kind'] . ' ' . $decision);

    return null;
}

// ── who is due ──────────────────────────────────────────────────────────

/** The latest recorded check of each kind, per person, with when it is due again. */
function dr_latest(): array
{
    return rows("SELECT d.*, c.full_name FROM driver_checks d JOIN candidates c ON c.id = d.candidate_id
                 WHERE d.status = 'completed' AND d.id = (SELECT MAX(x.id) FROM driver_checks x WHERE x.candidate_id = d.candidate_id AND x.kind = d.kind AND x.status = 'completed')
                 ORDER BY d.next_due_on IS NULL, d.next_due_on, c.full_name");
}

/** due, overdue or current, for a recorded check. */
function dr_due_state(array $d): string
{
    if ($d['next_due_on'] === null) {
        return 'current';
    }
    if ((string) $d['next_due_on'] < date('Y-m-d')) {
        return 'overdue';
    }

    return (string) $d['next_due_on'] <= date('Y-m-d', strtotime('+' . DR_DUE_SOON_DAYS . ' days')) ? 'due' : 'current';
}

/**
 * Tell recruiters, once, of each yearly check coming due within the month
 * or overdue, unless a new one is already waiting. Run by the scheduled
 * workflows.
 */
function dr_due_alerts(): int
{
    $n = 0;
    $recruiters = array_map('intval', array_column(rows("SELECT id FROM users WHERE is_active = 1 AND role IN ('admin','recruiter')"), 'id'));
    foreach (dr_latest() as $d) {
        $state = dr_due_state($d);
        if ($state === 'current' || val("SELECT COUNT(*) FROM driver_checks WHERE candidate_id = ? AND kind = ? AND status = 'requested'", [(int) $d['candidate_id'], $d['kind']])) {
            continue;
        }
        foreach ($recruiters as $u) {
            if (q('INSERT IGNORE INTO workflow_alerts (user_id, alert_key) VALUES (?,?)', [$u, hash('sha256', 'driver-check|' . $d['id'] . '|' . $state)])->rowCount()) {
                q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)',
                  [$u, mb_substr(t(':check for :name is due on :date', ['check' => dr_kinds()[$d['kind']], 'name' => $d['full_name'], 'date' => d((string) $d['next_due_on'])]), 0, 255),
                   '/driver-checks?candidate=' . (int) $d['candidate_id']]);
                $n++;
            }
        }
    }

    return $n;
}

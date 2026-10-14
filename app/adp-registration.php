<?php
/**
 * P5-M31: the worker's registration with ADP.
 *
 * ADP gives the agency a registration code. Each worker creates their own
 * account on ADP's site with it and proves who they are there (their
 * employee ID, or their Social Security number or ITIN and date of birth).
 * Crewvia never asks for, stores or passes on an SSN or ITIN: it shows the
 * worker the steps, the code and their employee ID once payroll records it,
 * and lets them say they are done, so payroll sees who is still missing
 * before the first pay run.
 */

declare(strict_types=1);

require_once __DIR__ . '/notices.php';

const ADP_DEFAULT_URL = 'https://my.adp.com';

/** The registration code, ADP's address and payroll's note; code '' when not set up. */
function adp_reg_settings(): array
{
    $s = array_column(rows("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key IN ('adp_registration_code','adp_registration_url','adp_registration_note')"), 'setting_value', 'setting_key');

    return ['code' => (string) ($s['adp_registration_code'] ?? ''), 'url' => (string) (($s['adp_registration_url'] ?? '') ?: ADP_DEFAULT_URL), 'note' => (string) ($s['adp_registration_note'] ?? '')];
}

/**
 * Payroll saves the code ADP gave. The address must be ADP's own, over
 * https: a link workers follow to enter their identity is never anywhere else.
 */
function adp_reg_save(array $in, int $by): ?string
{
    $code = trim((string) ($in['code'] ?? ''));
    $url = rtrim(trim((string) ($in['url'] ?? '')), '/') ?: ADP_DEFAULT_URL;
    $note = trim((string) ($in['note'] ?? ''));
    if ($code !== '' && ! preg_match('/^[A-Za-z0-9-]{4,40}$/D', $code)) {
        return t('The registration code is 4 to 40 letters, digits or dashes, exactly as ADP gave it.');
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'adp.com' || str_ends_with($host, '.adp.com'))) {
        return t('The address must be ADP\'s own site over https, for example https://my.adp.com.');
    }
    if (mb_strlen($note) > 500) {
        return t('Keep the note under 500 characters.');
    }
    foreach (['adp_registration_code' => $code, 'adp_registration_url' => $url, 'adp_registration_note' => $note] as $k => $v) {
        q('INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$k, $v]);
    }
    log_activity('set the ADP registration for workers', 'setting', 0, $code !== '' ? 'code set' : 'code cleared');

    return null;
}

/** The worker's ADP status: their employee ID and when they said they registered. */
function adp_reg_status(int $candidateId): array
{
    return row('SELECT adp_employee_id, adp_registered_at FROM employee_profiles WHERE candidate_id = ?', [$candidateId]) ?: ['adp_employee_id' => null, 'adp_registered_at' => null];
}

/** The worker says they registered. Payroll is told. */
function adp_reg_mark(int $candidateId): void
{
    q('INSERT INTO employee_profiles (candidate_id, adp_registered_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE adp_registered_at = COALESCE(adp_registered_at, NOW())', [$candidateId]);
    q('INSERT INTO candidate_events (candidate_id, event_type, detail) VALUES (?,?,?)', [$candidateId, 'ADP registration', 'The worker said they registered with ADP']);
    $who = (string) val('SELECT full_name FROM candidates WHERE id = ?', [$candidateId]);
    notify_desks(['payroll'], t(':name registered with ADP.', ['name' => $who]), '/payroll-export#adp-registration');
}

/** Payroll records the worker's ADP employee ID; the worker is told they can register with it. */
function adp_reg_set_id(int $candidateId, string $adpId): ?string
{
    $adpId = trim($adpId);
    if ($adpId !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $adpId)) {
        return t('An ADP employee ID is letters, digits, dashes or underscores, at most 40.');
    }
    if (! val('SELECT id FROM candidates WHERE id = ?', [$candidateId])) {
        return t('That person does not exist.');
    }
    q('INSERT INTO employee_profiles (candidate_id, adp_employee_id) VALUES (?,?) ON DUPLICATE KEY UPDATE adp_employee_id = VALUES(adp_employee_id)', [$candidateId, $adpId !== '' ? $adpId : null]);
    log_activity('recorded an ADP employee ID', 'candidate', $candidateId);
    if ($adpId !== '' && adp_reg_settings()['code'] !== '') {
        notify_candidate($candidateId, t('Your ADP employee ID is ready. Register with ADP from your portal to be paid.'), '/portal#adp');
    }

    return null;
}

/** Everyone working or about to work on the project, with where they are with ADP. */
function adp_reg_people(int $jobId): array
{
    return rows("SELECT c.id, c.full_name, e.adp_employee_id, e.adp_registered_at, p.status, p.id AS placement_id, p.per_diem_taxable, EXISTS (SELECT 1 FROM worker_accounts w WHERE w.candidate_id = c.id) AS has_portal
                 FROM placements p JOIN candidates c ON c.id = p.candidate_id LEFT JOIN employee_profiles e ON e.candidate_id = c.id
                 WHERE p.job_id = ? AND p.status IN ('confirmed','travelling','on_site')
                 ORDER BY e.adp_registered_at IS NOT NULL, e.adp_employee_id IS NOT NULL, c.full_name LIMIT 2000", [$jobId]);
}

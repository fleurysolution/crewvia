<?php
/**
 * Recognition, disciplinary cases, separation records and controlled HR
 * access (P2-M06).
 *
 * Who sees what:
 *   - recognition: recruiters and administrators; a supervisor for their
 *     crew; the worker their own.
 *   - disciplinary cases and separation records: administrators, and those
 *     an administrator grants HR access. A supervisor may report a case on
 *     their crew and sees only the cases they reported. The worker sees a
 *     case about them once its outcome is recorded, gives their account,
 *     and acknowledges it. Nobody else - not payroll, not logistics, not a
 *     recruiter without the grant.
 *   - every opening of a person's HR record, and every grant and
 *     revocation, is logged.
 *
 * A case is never edited. Its facts stand as opened; notes, the outcome,
 * the person's account and their acknowledgement are added. A termination
 * is decided by an administrator. A separation is recorded once per
 * assignment; a "not eligible" rehire puts the person on the register
 * (the existing do-not-rehire register), with the separation as reason.
 */

declare(strict_types=1);

function recognition_kinds(): array
{
    return ['award' => t('Award'), 'commendation' => t('Commendation'), 'safety' => t('Safety recognition'),
            'years_of_service' => t('Years of service'), 'other' => t('Other')];
}

function case_categories(): array
{
    return ['attendance' => t('Attendance'), 'conduct' => t('Conduct'), 'safety' => t('Safety'),
            'performance' => t('Performance'), 'policy' => t('Policy'), 'other' => t('Other')];
}

function case_outcomes(): array
{
    return ['no_action' => t('No action'), 'verbal_warning' => t('Verbal warning'), 'written_warning' => t('Written warning'),
            'final_warning' => t('Final warning'), 'suspension' => t('Suspension'), 'termination' => t('Termination')];
}

function separation_reasons(): array
{
    return ['assignment_completed' => t('Assignment completed'), 'resigned' => t('Resigned'), 'terminated' => t('Terminated'),
            'no_show' => t('No show'), 'laid_off' => t('Laid off'), 'other' => t('Other')];
}

/** Does the signed-in user hold HR access (cases and separations)? */
function hr_access(): bool
{
    if (can('admin')) {
        return true;
    }
    $u = user();

    return $u && in_array($u['role'] ?? '', ['recruiter', 'payroll', 'hotels'], true)
        && (bool) val('SELECT COUNT(*) FROM hr_access_grants WHERE user_id = ?', [uid()]);
}

function hr_log(string $action, ?int $candidateId, ?string $detail = null, ?int $subjectUser = null): void
{
    q('INSERT INTO hr_access_log (user_id, action, candidate_id, subject_user_id, detail) VALUES (?,?,?,?,?)',
      [uid() ?: null, $action, $candidateId, $subjectUser, $detail !== null ? mb_substr($detail, 0, 500) : null]);
}

function hr_self_candidate(): int
{
    return (user()['role'] ?? '') === 'worker' ? (int) val('SELECT candidate_id FROM worker_accounts WHERE user_id = ?', [uid()]) : 0;
}

/** Is the signed-in supervisor over one of this person's live assignments? */
function hr_supervises(int $candidateId): bool
{
    return (user()['role'] ?? '') === 'supervisor' && (bool) val("SELECT COUNT(*) FROM placements p JOIN assignment_details d ON d.placement_id = p.id
        WHERE p.candidate_id = ? AND d.supervisor_id = ? AND p.status NOT IN ('completed','cancelled')", [$candidateId, uid()]);
}

function hr_may_see_recognition(int $candidateId): bool
{
    return can('recruiter') || hr_access() || hr_supervises($candidateId) || hr_self_candidate() === $candidateId;
}

// ── recognition ─────────────────────────────────────────────────────────

function recognition_add(int $candidateId, array $in): array
{
    if (! can('recruiter') && ! hr_supervises($candidateId)) {
        return [0, t('Only a recruiter, or this person\'s supervisor, records recognition.')];
    }
    $kind = (string) ($in['kind'] ?? '');
    $title = trim((string) ($in['title'] ?? ''));
    $on = (string) ($in['awarded_on'] ?? '');
    if (! isset(recognition_kinds()[$kind]) || mb_strlen($title) < 3 || mb_strlen($title) > 190) {
        return [0, t('Say what it is, in 3 to 190 characters.')];
    }
    if (! valid_date($on) || $on > date('Y-m-d')) {
        return [0, t('Date it today or earlier.')];
    }
    q('INSERT INTO recognitions (candidate_id, kind, title, description, awarded_on, created_by) VALUES (?,?,?,?,?,?)',
      [$candidateId, $kind, $title, mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000) ?: null, $on, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)', [$candidateId, uid() ?: null, 'recognition', recognition_kinds()[$kind] . ': ' . $title]);
    $worker = val('SELECT user_id FROM worker_accounts WHERE candidate_id = ?', [$candidateId]);
    if ($worker) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $worker, 'You were recognised: ' . $title, '/hr-records']);
    }

    return [$id, null];
}

// ── disciplinary cases ──────────────────────────────────────────────────

function case_next_reference(): string
{
    $prefix = 'HR-' . date('Y') . '-';
    $n = (int) val('SELECT COUNT(*) FROM disciplinary_cases WHERE reference LIKE ?', [$prefix . '%']);
    do {
        $ref = $prefix . str_pad((string) ++$n, 4, '0', STR_PAD_LEFT);
    } while (val('SELECT COUNT(*) FROM disciplinary_cases WHERE reference = ?', [$ref]));

    return $ref;
}

/** May the signed-in user see this case? */
function case_visible(array $c): bool
{
    if (hr_access()) {
        return true;
    }
    if ((user()['role'] ?? '') === 'supervisor') {
        return (int) $c['opened_by'] === uid();
    }

    return hr_self_candidate() === (int) $c['candidate_id'] && $c['status'] !== 'open';
}

function case_note(int $caseId, string $kind, string $note): void
{
    q('INSERT INTO disciplinary_notes (case_id, kind, note, user_id) VALUES (?,?,?,?)', [$caseId, $kind, mb_substr($note, 0, 5000), uid() ?: null]);
}

function case_open(int $candidateId, array $in): array
{
    if (! hr_access() && ! hr_supervises($candidateId)) {
        return [0, t('Only HR, or this person\'s supervisor, opens a case.')];
    }
    $cat = (string) ($in['category'] ?? '');
    $on = (string) ($in['incident_on'] ?? '');
    $facts = trim((string) ($in['facts'] ?? ''));
    $incident = (int) ($in['safety_incident_id'] ?? 0);
    if (! isset(case_categories()[$cat])) {
        return [0, t('Choose what the case is about.')];
    }
    if (! valid_date($on) || $on > date('Y-m-d')) {
        return [0, t('Date what happened today or earlier.')];
    }
    if (mb_strlen($facts) < 20) {
        return [0, t('Set out the facts: what happened, when, who saw it. At least 20 characters.')];
    }
    if ($incident && ! val('SELECT COUNT(*) FROM safety_incidents WHERE id = ?', [$incident])) {
        return [0, t('That safety incident does not exist.')];
    }
    $ref = case_next_reference();
    q('INSERT INTO disciplinary_cases (reference, candidate_id, safety_incident_id, category, incident_on, facts, opened_by) VALUES (?,?,?,?,?,?,?)',
      [$ref, $candidateId, $incident ?: null, $cat, $on, mb_substr($facts, 0, 10000), uid() ?: null]);
    $id = (int) db()->lastInsertId();
    case_note($id, 'opened', $ref);
    hr_log('opened a case', $candidateId, $ref);

    return [$id, null];
}

function case_add_note(array $c, string $note): ?string
{
    if (! hr_access()) {
        return t('Only HR adds to a case.');
    }
    if ($c['status'] === 'closed') {
        return t('That case is closed.');
    }
    if (mb_strlen(trim($note)) < 3) {
        return t('Write the note.');
    }
    case_note((int) $c['id'], 'note', trim($note));

    return null;
}

function case_decide(array $c, string $outcome, string $note): ?string
{
    if (! hr_access()) {
        return t('Only HR records an outcome.');
    }
    if ($c['status'] !== 'open') {
        return t('That case already has its outcome.');
    }
    if (! isset(case_outcomes()[$outcome])) {
        return t('Choose the outcome.');
    }
    if ($outcome === 'termination' && ! can('admin')) {
        return t('Only an administrator decides a termination.');
    }
    if ((int) $c['opened_by'] === uid() && in_array($outcome, ['final_warning', 'suspension', 'termination'], true)) {
        return t('Whoever opened a case does not decide a final warning, a suspension or a termination on it.');
    }
    if (mb_strlen(trim($note)) < 10) {
        return t('Explain the outcome: at least 10 characters.');
    }
    q("UPDATE disciplinary_cases SET status = 'decided', outcome = ?, outcome_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?",
      [$outcome, mb_substr(trim($note), 0, 1000), uid() ?: null, (int) $c['id']]);
    case_note((int) $c['id'], 'decided', case_outcomes()[$outcome] . ' · ' . trim($note));
    hr_log('decided a case', (int) $c['candidate_id'], $c['reference'] . ' ' . $outcome);
    $worker = val('SELECT user_id FROM worker_accounts WHERE candidate_id = ?', [(int) $c['candidate_id']]);
    if ($worker) {
        q('INSERT INTO notifications (user_id, message, target) VALUES (?,?,?)', [(int) $worker, 'An HR case about you has an outcome. Read it and give your account.', '/hr-records']);
    }

    return null;
}

/** The person's own account, and their acknowledgement that they read it. */
function case_respond(array $c, string $response, bool $acknowledge): ?string
{
    if (hr_self_candidate() !== (int) $c['candidate_id'] || $c['status'] === 'open') {
        return t('Only the person, once the outcome is recorded, answers a case.');
    }
    if ($c['status'] === 'closed') {
        return t('That case is closed.');
    }
    if (trim($response) === '' && ! $acknowledge) {
        return t('Give your account, or acknowledge that you have read it.');
    }
    if (trim($response) !== '') {
        if ($c['response'] !== null) {
            return t('Your account is already recorded; it is not changed.');
        }
        q('UPDATE disciplinary_cases SET response = ?, responded_at = NOW() WHERE id = ?', [mb_substr(trim($response), 0, 5000), (int) $c['id']]);
        case_note((int) $c['id'], 'response', trim($response));
    }
    if ($acknowledge && $c['acknowledged_at'] === null) {
        q('UPDATE disciplinary_cases SET acknowledged_at = NOW() WHERE id = ?', [(int) $c['id']]);
        case_note((int) $c['id'], 'acknowledged', 'Read and acknowledged by the person');
    }

    return null;
}

function case_close(array $c, string $note): ?string
{
    if (! hr_access()) {
        return t('Only HR closes a case.');
    }
    if ($c['status'] !== 'decided') {
        return t('A case is closed once its outcome is recorded.');
    }
    if (mb_strlen(trim($note)) < 3) {
        return t('Say why it closes.');
    }
    q("UPDATE disciplinary_cases SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?", [uid() ?: null, (int) $c['id']]);
    case_note((int) $c['id'], 'closed', trim($note) . ($c['acknowledged_at'] === null ? ' · not acknowledged by the person' : ''));

    return null;
}

// ── separations ─────────────────────────────────────────────────────────

function separation_record(int $placementId, array $in): array
{
    if (! hr_access()) {
        return [0, t('Only HR records a separation.')];
    }
    $p = row('SELECT p.*, c.full_name FROM placements p JOIN candidates c ON c.id = p.candidate_id WHERE p.id = ?', [$placementId]);
    if (! $p) {
        return [0, t('That assignment does not exist.')];
    }
    if (! in_array($p['status'], ['completed', 'cancelled'], true)) {
        return [0, t('Close the assignment on Operations first; the separation records why it ended.')];
    }
    if (val('SELECT COUNT(*) FROM separations WHERE placement_id = ?', [$placementId])) {
        return [0, t('That assignment\'s separation is already recorded.')];
    }
    $reason = (string) ($in['reason'] ?? '');
    $on = (string) ($in['separated_on'] ?? '');
    $rehire = (string) ($in['rehire'] ?? '');
    $detail = trim((string) ($in['detail'] ?? ''));
    $case = (int) ($in['case_id'] ?? 0);
    if (! isset(separation_reasons()[$reason]) || ! in_array($rehire, ['eligible', 'review', 'ineligible'], true)) {
        return [0, t('Choose why it ended and whether to rehire.')];
    }
    if (! valid_date($on) || $on > date('Y-m-d')) {
        return [0, t('Date the separation today or earlier.')];
    }
    if ($reason === 'terminated' && ! $case) {
        return [0, t('A termination refers to the case that decided it.')];
    }
    if ($case && ! val("SELECT COUNT(*) FROM disciplinary_cases WHERE id = ? AND candidate_id = ? AND outcome = 'termination'", [$case, (int) $p['candidate_id']])) {
        return [0, t('That case did not end in this person\'s termination.')];
    }
    if (($rehire === 'ineligible' || $reason !== 'assignment_completed') && mb_strlen($detail) < 10) {
        return [0, t('Say what happened: at least 10 characters.')];
    }
    $voluntary = in_array($reason, ['resigned'], true) ? 1 : (($in['voluntary'] ?? '') === '1' && $reason === 'other' ? 1 : 0);
    q('INSERT INTO separations (placement_id, candidate_id, separated_on, reason, voluntary, detail, rehire, case_id, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)',
      [$placementId, (int) $p['candidate_id'], $on, $reason, $voluntary, mb_substr($detail, 0, 1000) ?: null, $rehire, $case ?: null, uid() ?: null]);
    $id = (int) db()->lastInsertId();
    hr_log('recorded a separation', (int) $p['candidate_id'], separation_reasons()[$reason]);

    // The register is where every recruiter sees it (the existing rehire decision).
    q('INSERT IGNORE INTO employee_profiles (candidate_id) VALUES (?)', [(int) $p['candidate_id']]);
    if ($rehire === 'ineligible') {
        q("UPDATE employee_profiles SET rehire_status = 'ineligible', exclusion_reason = ?, excluded_by = ?, excluded_at = NOW() WHERE candidate_id = ?",
          [mb_substr(separation_reasons()[$reason] . ' on ' . $on . ' - ' . $detail, 0, 1000), uid() ?: null, (int) $p['candidate_id']]);
    } elseif ($rehire === 'review') {
        q("UPDATE employee_profiles SET rehire_status = 'review' WHERE candidate_id = ? AND rehire_status NOT IN ('ineligible','do_not_use')", [(int) $p['candidate_id']]);
    }
    q('INSERT INTO candidate_events (candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
      [(int) $p['candidate_id'], uid() ?: null, 'separation', separation_reasons()[$reason] . ' · ' . t(['eligible' => 'Fine to call', 'review' => 'Check before calling', 'ineligible' => 'No rehire'][$rehire])]);

    return [$id, null];
}

// ── access ──────────────────────────────────────────────────────────────

function hr_grant(int $userId, string $reason): ?string
{
    if (! can('admin')) {
        return t('Only an administrator grants HR access.');
    }
    if (! val("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND role IN ('recruiter','payroll','hotels')", [$userId])) {
        return t('HR access is granted to an active recruiter, payroll or logistics user.');
    }
    if (val('SELECT COUNT(*) FROM hr_access_grants WHERE user_id = ?', [$userId])) {
        return t('That user already has HR access.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why they need it.');
    }
    q('INSERT INTO hr_access_grants (user_id, reason, granted_by) VALUES (?,?,?)', [$userId, mb_substr(trim($reason), 0, 500), uid() ?: null]);
    hr_log('granted HR access', null, trim($reason), $userId);

    return null;
}

function hr_revoke(int $userId, string $reason): ?string
{
    if (! can('admin')) {
        return t('Only an administrator grants HR access.');
    }
    if (! val('SELECT COUNT(*) FROM hr_access_grants WHERE user_id = ?', [$userId])) {
        return t('That user has no HR access.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why.');
    }
    q('DELETE FROM hr_access_grants WHERE user_id = ?', [$userId]);
    hr_log('revoked HR access', null, trim($reason), $userId);

    return null;
}

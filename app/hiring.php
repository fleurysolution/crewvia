<?php
/**
 * The Hiring workspace (P5-M10): one place for a recruiter to work a job,
 * modelled on how job boards' employer sites work and built on Crewvia's
 * own records - requisitions (vacancies), their applications and stages,
 * screening answers and checks, contact history, and the talent pool.
 *
 *   /hiring                       every requisition, with its counts
 *   /hiring?v=ID                  one requisition: its applicants by stage,
 *                                 filtered, each with yes / maybe / no
 *   /hiring?v=ID&app=ID           the split review of one applicant
 *   /hiring?v=ID&tab=matches      people in the talent pool who fit, to
 *                                 add to this job
 *
 * Nothing here is a second rule book. Moving an application follows the
 * screening screen's rules (settled applications do not move; an offer
 * needs passed checks, answered required questions, the contract steps
 * and its terms; acceptance is the person's own, in their portal). The
 * interest a recruiter shows (yes, maybe, no) is a sorting aid kept on
 * the application; it decides nothing.
 */

declare(strict_types=1);

/**
 * P5-M93: Hiring holds the people a hiring manager approved (or offered, or hired) - HR's work. Until then an
 * applicant is the recruiter's, on Candidates.
 */
function hw_approved_sql(string $alias = 'a'): string
{
    return str_replace('a.', $alias . '.', "(a.stage IN ('offered','accepted') OR EXISTS (SELECT 1 FROM application_reviews hr WHERE hr.application_id = a.id AND hr.status = 'validated'))");
}

/** @return array<string,string> */
function hw_tabs(): array
{
    return ['active' => t('To onboard'), 'accepted' => t('Hired'), 'closed' => t('Withdrawn'), 'all' => t('All')];
}

/** @return array<string,string> */
function hw_stages(): array
{
    return ['new' => t('New'), 'screening' => t('Screening'), 'interview' => t('Interview'), 'offered' => t('Offered'), 'accepted' => t('Hired'),
            'rejected' => t('Rejected'), 'withdrawn' => t('Withdrawn')];
}

/** @return array<string,string> */
function hw_interests(): array
{
    return ['yes' => t('Interested'), 'maybe' => t('Maybe'), 'no' => t('Not interested')];
}

function hw_tab_where(string $tab): string
{
    $approved = hw_approved_sql();

    return $approved . ' AND ' . match ($tab) {
        'active' => "a.stage NOT IN ('accepted','rejected','withdrawn')",
        'closed' => "a.stage IN ('rejected','withdrawn')",
        'accepted' => "a.stage = 'accepted'",
        default  => '1',
    };
}

/** Every requisition, with the counts a recruiter reads first. */
function hw_jobs(string $status, int $jobId): array
{
    $where = ['1'];
    $args = [];
    if ($status === 'open') { $where[] = 'v.is_open = 1'; }
    if ($status === 'closed') { $where[] = 'v.is_open = 0'; }
    if ($jobId) { $where[] = 'v.job_id = ?'; $args[] = $jobId; }

    return rows("SELECT v.id, v.title, v.is_open, v.openings, v.discipline, v.starts_on, j.title AS project, j.id AS job_id,
                        COUNT(a.id) AS applicants,
                        COALESCE(SUM(" . hw_approved_sql() . "), 0) AS total,
                        COALESCE(SUM(" . hw_approved_sql() . " AND a.stage NOT IN ('accepted','rejected','withdrawn')), 0) AS to_onboard,
                        COALESCE(SUM(a.stage = 'accepted'), 0) AS hired,
                        MAX(a.created_at) AS last_applied
                 FROM vacancies v JOIN jobs j ON j.id = v.job_id LEFT JOIN applications a ON a.vacancy_id = v.id
                 WHERE " . implode(' AND ', $where) . '
                 GROUP BY v.id, v.title, v.is_open, v.openings, v.discipline, v.starts_on, j.title, j.id
                 ORDER BY v.is_open DESC, MAX(a.created_at) IS NULL, MAX(a.created_at) DESC, v.id DESC LIMIT 300', $args);
}

function hw_vacancy(int $id): ?array
{
    return row('SELECT v.*, j.title AS project, j.status AS project_status, j.site_city, j.site_state, j.site_zip FROM vacancies v JOIN jobs j ON j.id = v.job_id WHERE v.id = ?', [$id]);
}

/** @return array<string,int> */
function hw_counts(int $vacancyId): array
{
    $out = array_fill_keys(array_keys(hw_tabs()), 0);
    foreach (rows('SELECT a.stage, COUNT(*) AS n FROM applications a WHERE a.vacancy_id = ? AND ' . hw_approved_sql() . ' GROUP BY a.stage', [$vacancyId]) as $r) {
        $n = (int) $r['n'];
        $out['all'] += $n;
        if (! in_array($r['stage'], ['accepted', 'rejected', 'withdrawn'], true)) { $out['active'] += $n; }
        if ($r['stage'] === 'accepted') { $out['accepted'] += $n; }
        if (in_array($r['stage'], ['rejected', 'withdrawn'], true)) { $out['closed'] += $n; }
    }

    return $out;
}

/**
 * The applicants of one requisition for a tab, with what a recruiter
 * scans: where they are, their background, how screening stands, when
 * they applied and were last contacted, and the interest shown.
 */
function hw_applicants(array $v, string $tab, array $f, ?array $site = null): array
{
    require_once __DIR__ . '/rehire.php';
    $where = ['a.vacancy_id = ?', hw_tab_where($tab)];
    $args = [(int) $v['id']];
    if ($site && ($miles = (int) ($f['miles'] ?? 0))) {
        [$within, $withinArgs] = geo_within_sql($site, $miles);
        $where[] = $within;
        array_push($args, ...$withinArgs);
    }
    [$distanceSql, $distanceArgs] = $site ? geo_distance_sql($site) : ['NULL', []];
    if (($f['q'] ?? '') !== '') {
        $where[] = '(c.full_name LIKE ? OR c.city LIKE ? OR c.state LIKE ? OR c.degree LIKE ?)';
        array_push($args, ...array_fill(0, 4, '%' . $f['q'] . '%'));
    }
    if (in_array($f['interest'] ?? '', ['yes', 'maybe', 'no'], true)) { $where[] = 'a.interest = ?'; $args[] = $f['interest']; }
    if (($f['interest'] ?? '') === 'unset') { $where[] = 'a.interest IS NULL'; }
    $order = match ($f['sort'] ?? '') {
        'oldest' => 'a.created_at ASC, a.id ASC',
        'score'  => 'a.screening_score IS NULL, a.screening_score DESC, a.id DESC',
        'name'   => 'c.full_name ASC',
        'near'   => 'miles_away IS NULL, miles_away ASC, a.id DESC',
        default  => 'a.created_at DESC, a.id DESC',
    };
    $list = rows('SELECT a.id, a.stage, a.source, a.created_at, a.screening_score, a.screened_out, a.screened_out_why, a.interest,
                         (SELECT r.status FROM application_reviews r WHERE r.application_id = a.id AND r.status <> \'withdrawn\' ORDER BY r.id DESC LIMIT 1) AS review_status,
                         ' . rehire_block_sql('c') . ' AS blocked_status,
                         EXISTS(SELECT 1 FROM offer_acceptances o WHERE o.application_id = a.id AND o.countersigned_at IS NULL AND a.stage = \'offered\') AS to_countersign,
                         c.id AS candidate_id, c.full_name, c.city, c.state, c.degree, c.years_exp, c.discipline, c.last_contact_at, c.phone, c.email, c.availability, c.available_from,
                         ' . $distanceSql . ' AS miles_away
                  FROM applications a JOIN candidates c ON c.id = a.candidate_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 300', array_merge($distanceArgs, $args));
    if (! $list) {
        return [];
    }
    $ids = implode(',', array_map(static fn($a) => (int) $a['id'], $list));
    $checks = [];
    foreach (rows("SELECT application_id, status, COUNT(*) AS n FROM screening_checks WHERE application_id IN ($ids) GROUP BY application_id, status") as $r) {
        $checks[(int) $r['application_id']][$r['status']] = (int) $r['n'];
    }
    // Only the questions this role asks: the project's, plus its own (walkthrough finding 12).
    $required = (int) val('SELECT COUNT(*) FROM screening_questions WHERE job_id = ? AND required = 1 AND (vacancy_id IS NULL OR vacancy_id = ?)', [(int) $v['job_id'], (int) $v['id']]);
    $answered = [];
    foreach (rows("SELECT s.application_id, COUNT(*) AS n FROM screening_answers s JOIN screening_questions q ON q.id = s.question_id
                   WHERE s.application_id IN ($ids) AND q.required = 1 AND TRIM(s.answer) <> '' GROUP BY s.application_id") as $r) {
        $answered[(int) $r['application_id']] = (int) $r['n'];
    }
    $cids = implode(',', array_map(static fn($a) => (int) $a['candidate_id'], $list));
    $skills = [];
    foreach (rows("SELECT candidate_id, skill_slug FROM candidate_skills WHERE candidate_id IN ($cids) ORDER BY confirmed DESC, years DESC") as $r) {
        $skills[(int) $r['candidate_id']][] = $r['skill_slug'];
    }
    foreach ($list as &$a) {
        $a['checks'] = $checks[(int) $a['id']] ?? [];
        $a['required'] = $required;
        $a['answered'] = $answered[(int) $a['id']] ?? 0;
        $a['skills'] = array_slice($skills[(int) $a['candidate_id']] ?? [], 0, 3);
    }

    return $list;
}

/** Initials for an avatar. */
function hw_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];

    return strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice($parts, 0, 2)))) ?: '?';
}

/** "3 hours ago", "2 days ago", or the date. */
function hw_ago(?string $when): string
{
    if (! $when) {
        return '';
    }
    $s = time() - strtotime($when);
    return match (true) {
        $s < 3600   => tn(max(1, intdiv($s, 60)), ':n minute ago', ':n minutes ago'),
        $s < 86400  => tn(intdiv($s, 3600), ':n hour ago', ':n hours ago'),
        $s < 864000 => tn(intdiv($s, 86400), ':n day ago', ':n days ago'),
        default     => d(substr($when, 0, 10)),
    };
}

// ── acting on an application ────────────────────────────────────────────

function hw_application(int $id): ?array
{
    return row('SELECT a.*, v.job_id, v.title AS vacancy_title, c.full_name FROM applications a JOIN vacancies v ON v.id = a.vacancy_id JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$id]);
}

/** Yes, maybe, no, or clear it. A sorting aid; it moves nothing. */
function hw_interest(int $id, string $value, int $by): ?string
{
    $a = hw_application($id);
    if (! $a) { return t('Application unavailable in this project.'); }
    if ($value !== '' && ! isset(hw_interests()[$value])) { return t('Unknown action.'); }
    $value = $a['interest'] === $value ? '' : $value;
    q('UPDATE applications SET interest = ?, interest_by = ?, interest_at = ? WHERE id = ?', [$value ?: null, $value ? $by : null, $value ? date('Y-m-d H:i:s') : null, $id]);

    return null;
}

/** A note on the application, kept with its stage. */
function hw_note(int $id, string $note, int $by): ?string
{
    $note = trim($note);
    $a = hw_application($id);
    if (! $a) { return t('Application unavailable in this project.'); }
    if (mb_strlen($note) < 2 || mb_strlen($note) > 2000) { return t('Write the note in 2 to 2000 characters.'); }
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$id, $by, $a['stage'], $note]);
    log_activity('noted an application', 'application', $id, $note);

    return null;
}

/**
 * Move an application, by the screening screen's rules. Returns the
 * refusal, or null.
 */
function hw_move(int $id, string $stage, string $note, int $by, bool $validated = false): ?string
{
    require_once __DIR__ . '/contracts.php';
    $a = hw_application($id);
    $note = trim($note);
    if (! $a) { return t('Application unavailable in this project.'); }
    if (in_array($a['stage'], ['accepted', 'rejected', 'withdrawn'], true)) { return t('That application is settled. Raise a new one for the same person if they come back.'); }
    if (! in_array($stage, ['new', 'screening', 'interview', 'offered', 'rejected', 'withdrawn', 'accepted'], true) || $stage === $a['stage']) { return t('Choose another stage.'); }
    if ($stage === 'accepted') { return t('The candidate must accept their offer in their private portal.'); }
    // P5-M51: the do-not-rehire register holds the person, under any record, by email or phone.
    if (in_array($stage, ['screening', 'interview', 'offered'], true)) {
        require_once __DIR__ . '/rehire.php';
        if (($held = rehire_refusal((int) $a['candidate_id'])) !== null) { return $held; }
    }
    $jobId = (int) $a['job_id'];
    if ($stage === 'offered') {
        if ((int) val("SELECT COUNT(*) FROM screening_checks WHERE application_id = ? AND status <> 'passed'", [$id])) { return t('Complete screening checks before offering this job.'); }
        if ((int) val("SELECT COUNT(*) FROM screening_questions q LEFT JOIN screening_answers s ON s.question_id = q.id AND s.application_id = ?
                       WHERE q.job_id = ? AND q.required = 1 AND (s.answer IS NULL OR TRIM(s.answer) = '')
                         AND (q.vacancy_id IS NULL OR q.vacancy_id = (SELECT vacancy_id FROM applications WHERE id = ?))", [$id, $jobId, $id])) {
            return t('Answer every required screening question before offering this job.');
        }
        if (contract_document_gaps((int) $a['candidate_id'], $jobId) || contract_latest($id) || val('SELECT required_before_deployment FROM contract_policies WHERE job_id = ?', [$jobId])) {
            return t('Use the contract workflow after required document verification.');
        }
        if ($note === '') { return t('Enter the complete offer terms before issuing an offer.'); }
        // P5-M28: where the agency validates offers (an "Offer before it reaches the candidate"
        // approval chain), the offer waits for that validation; the candidate hears nothing yet.
        // The chain was configurable before, but nothing ever started it.
        if (! $validated) {
            require_once __DIR__ . '/approvals.php';
            if (approvals_available() && approval_chain_for('offer')) {
                $state = approval_state('offer', $id);
                if ($state === 'pending') { return t('This offer is already waiting for validation.'); }
                if ($state !== 'approved') {
                    // Turned down before: a new submission starts the chain again.
                    q("UPDATE approval_requests SET status = 'cancelled' WHERE subject_type = 'offer' AND subject_id = ? AND status = 'rejected'", [$id]);
                    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$id, $by, 'validation', $note]);
                    $who = (string) val('SELECT c.full_name FROM applications a JOIN candidates c ON c.id = a.candidate_id WHERE a.id = ?', [$id]);
                    $title = (string) val('SELECT v.title FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$id]);
                    $started = approval_start('offer', $id, $who . ' · ' . $title, $jobId);
                    if (! $started['ok']) { return t($started['message']); }
                    log_activity('sent an offer for validation', 'application', $id, $who);
                    flash(t(':name is sent for validation. The candidate gets the offer once it is approved; you will be told either way.', ['name' => $who]));
                    return null;
                }
            }
        }
    }
    q('UPDATE applications SET stage = ? WHERE id = ?', [$stage, $id]);
    candidate_follow_application($id);
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$id, $by, $stage, $note]);
    log_activity('moved an application', 'application', $id, $stage);
    // P5-M54: settled, it closes the manager's review and the interviews still open on it.
    if (in_array($stage, ['rejected', 'withdrawn'], true)) {
        require_once __DIR__ . '/reviews.php';
        review_close_for_application($id, $by, $stage === 'rejected' ? t('The application was rejected.') : t('The application was withdrawn.'));
    }
    // P5-M52: the candidate hears of their offer, in their portal and by email (an offer validated first included).
    if ($stage === 'offered') {
        require_once __DIR__ . '/notices.php';
        $job = (string) val('SELECT v.title FROM applications a JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$id]);
        candidate_tell((int) $a['candidate_id'], t('your offer for :job', ['job' => $job]),
                       t('You have been selected for :job. Your offer is in your portal: read it and accept it there.', ['job' => $job]), '/portal');
    }
    if (in_array($stage, ['screening', 'offered'], true)) {
        require_once __DIR__ . '/invitations.php';
        try {
            workforce_invite_candidate((int) $a['candidate_id']);
        } catch (Throwable $error) {
            flash(t('Stage recorded. Invitation needs attention: :message', ['message' => t($error->getMessage())]), 'err');
        }
    }

    return null;
}

// ── the talent pool ─────────────────────────────────────────────────────

/** People who fit the requisition's discipline, not yet on it and not barred from rehire. */
function hw_matches(array $v, string $q = '', ?array $site = null, int $miles = 0): array
{
    $args = [(int) $v['id']];
    $where = ["NOT EXISTS (SELECT 1 FROM applications x WHERE x.candidate_id = c.id AND x.vacancy_id = ?)",
              "NOT EXISTS (SELECT 1 FROM employee_profiles p WHERE p.candidate_id = c.id AND p.rehire_status IN ('ineligible','do_not_use'))",
              "NOT EXISTS (SELECT 1 FROM worker_accounts w JOIN users u ON u.id = w.user_id WHERE w.candidate_id = c.id AND u.is_active = 0)"];
    if (in_array((string) ($v['discipline'] ?? ''), ['mechanical', 'chemical'], true)) {
        $where[] = 'c.discipline = ?';
        $args[] = $v['discipline'];
    }
    if ($q !== '') {
        $where[] = '(c.full_name LIKE ? OR c.city LIKE ? OR c.state LIKE ? OR c.degree LIKE ?)';
        array_push($args, ...array_fill(0, 4, '%' . $q . '%'));
    }
    if ($site && $miles) {
        [$within, $withinArgs] = geo_within_sql($site, $miles);
        $where[] = $within;
        array_push($args, ...$withinArgs);
    }
    [$distanceSql, $distanceArgs] = $site ? geo_distance_sql($site) : ['NULL', []];

    return rows('SELECT c.id, c.full_name, c.city, c.state, c.degree, c.years_exp, c.discipline, c.last_contact_at, c.phone,
                        (SELECT COUNT(*) FROM placements pl WHERE pl.candidate_id = c.id AND pl.status = \'completed\') AS past_jobs,
                        ' . $distanceSql . ' AS miles_away
                 FROM candidates c WHERE ' . implode(' AND ', $where) . '
                 ORDER BY past_jobs DESC, ' . ($site ? 'miles_away IS NULL, miles_away ASC, ' : '') . 'c.years_exp IS NULL, c.years_exp DESC, c.last_contact_at IS NULL, c.last_contact_at DESC LIMIT 60', array_merge($distanceArgs, $args));
}

/** Put someone from the pool on this requisition as a new applicant. */
function hw_add(int $vacancyId, int $candidateId, int $by): array
{
    $v = hw_vacancy($vacancyId);
    if (! $v || ! (int) $v['is_open']) { return [t('That requisition is closed.'), 0]; }
    if (! row('SELECT id FROM candidates WHERE id = ?', [$candidateId])) { return [t('That person does not exist.'), 0]; }
    require_once __DIR__ . '/rehire.php';
    if (($held = rehire_refusal($candidateId)) !== null) { return [$held, 0]; }
    if (val('SELECT COUNT(*) FROM applications WHERE candidate_id = ? AND vacancy_id = ?', [$candidateId, $vacancyId])) { return [t('This person is already on this requisition.'), 0]; }
    q("INSERT INTO applications (candidate_id, vacancy_id, source) VALUES (?,?,'Talent pool')", [$candidateId, $vacancyId]);
    $id = (int) db()->lastInsertId();
    q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$id, $by, 'new', t('Added from the talent pool')]);
    log_activity('added a person from the talent pool', 'application', $id, (string) $v['title']);

    return [null, $id];
}

/** P5-M42: how somebody the office adds by hand applied. */
function hw_channels(): array
{
    return ['email' => t('By email'), 'phone' => t('By phone'), 'walk_in' => t('In person'), 'referral' => t('Referred by someone'), 'job_board' => t('On a job board'), 'other' => t('Another way')];
}

/**
 * P5-M42: somebody who applied outside Crewvia (an email, a call, a referral)
 * added by the office as an applicant to a requisition, with what they sent.
 * Every document is checked and encrypted before anything is written, so a
 * bad file leaves nothing half done. A new record every time, as for a public
 * application: Hiring shows when the email or phone is already on file.
 *
 * @return array{0:?string,1:int} an error, or null and the application id
 */
function hw_add_applicant(int $vacancyId, array $in, array $files, int $by): array
{
    $v = hw_vacancy($vacancyId);
    if (! $v || ! (int) $v['is_open']) { return [t('That requisition is closed.'), 0]; }
    $first = trim((string) ($in['first_name'] ?? ''));
    $last = trim((string) ($in['last_name'] ?? ''));
    $email = trim((string) ($in['email'] ?? ''));
    $phone = trim((string) ($in['phone'] ?? ''));
    if ($first === '' || $last === '' || mb_strlen($first . ' ' . $last) > 190) { return [t('Enter their first and last name.'), 0]; }
    if ($email === '' && $phone === '') { return [t('Enter an email or a phone number, so they can be reached.'), 0]; }
    if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) { return [t('That email address is not valid.'), 0]; }
    if (strlen($phone) > 40) { return [t('That phone number is too long.'), 0]; }
    $channel = isset(hw_channels()[$in['channel'] ?? '']) ? (string) $in['channel'] : 'other';
    $received = (string) ($in['received_on'] ?? '');
    $received = preg_match('/^\d{4}-\d{2}-\d{2}$/', $received) && strtotime($received) && $received <= date('Y-m-d') ? $received : date('Y-m-d');
    $years = trim((string) ($in['years_exp'] ?? ''));
    $years = $years === '' ? null : max(0, min(60, (int) $years));
    $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 8000);
    $how = ['email' => 'by email', 'phone' => 'by phone', 'walk_in' => 'in person', 'referral' => 'by referral', 'job_board' => 'on a job board', 'other' => 'another way'][$channel];

    require_once __DIR__ . '/intake.php';
    require_once __DIR__ . '/geo.php';
    $prepared = [];
    $names = is_array($files['name'] ?? null) ? $files['name'] : [];
    $kinds = is_array($in['document_kind'] ?? null) ? $in['document_kind'] : [];
    try {
        foreach ($names as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
            if (count($prepared) === 6) { throw new RuntimeException(t('Up to 6 documents at a time.')); }
            $_FILES['resume'] = ['name' => (string) $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i], 'size' => $files['size'][$i] ?? 0];
            try { $file = intake_resume_prepare(); } catch (RuntimeException $e) { throw new RuntimeException((string) $name . ': ' . $e->getMessage()); }
            if ($file) { $prepared[] = [(string) ($kinds[$i] ?? 'other'), $file]; }
        }
    } catch (RuntimeException $e) {
        foreach ($prepared as [, $file]) { if (is_file($file['path'])) { unlink($file['path']); } }
        return [$e->getMessage(), 0];
    }

    db()->beginTransaction();
    try {
        q('INSERT INTO candidates(full_name,email,phone,city,state,zip,source,discipline,degree,years_exp) VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $first . ' ' . $last, $email ?: null, $phone ?: null,
            mb_substr(trim((string) ($in['city'] ?? '')), 0, 120) ?: null, mb_substr(trim((string) ($in['state'] ?? '')), 0, 40) ?: null, geo_zip((string) ($in['zip'] ?? '')),
            'Applied ' . $how, (string) ($v['discipline'] ?? '') ?: 'other', mb_substr(trim((string) ($in['degree'] ?? '')), 0, 190) ?: null, $years]);
        $cid = (int) db()->lastInsertId();
        geo_refresh_candidate($cid);
        q('INSERT INTO candidate_events(candidate_id,user_id,event_type,detail) VALUES (?,?,?,?)', [$cid, $by, 'application received', 'Added by the office: applied ' . $how . ' on ' . $received . ', requisition ' . $vacancyId]);
        q('INSERT INTO applications (candidate_id, vacancy_id, source, created_at) VALUES (?,?,?,?)', [$cid, $vacancyId, 'Applied ' . $how, $received . ' ' . date('H:i:s')]);
        $id = (int) db()->lastInsertId();
        q('INSERT INTO application_events (application_id, user_id, stage, note) VALUES (?,?,?,?)', [$id, $by, 'new',
            'Added by the office. Applied ' . $how . ', ' . $received . '.' . ($message !== '' ? "\n\nTheir message:\n" . $message : '')]);
        foreach ($prepared as [$kind, $file]) { candidate_document_file($cid, $kind, $file, $by); }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        foreach ($prepared as [, $file]) { if (is_file($file['path'])) { unlink($file['path']); } }
        throw $e;
    }
    log_activity('added an applicant by hand', 'application', $id, (string) $v['title'] . ' · ' . count($prepared) . ' document(s)');

    return [null, $id];
}

/** What happened on an application, and every contact with the person, newest first. */
function hw_timeline(array $a): array
{
    $out = [];
    foreach (rows('SELECT e.stage, e.note, e.created_at, u.name FROM application_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.application_id = ? ORDER BY e.id DESC LIMIT 100', [(int) $a['id']]) as $e) {
        $out[] = ['when' => $e['created_at'], 'who' => $e['name'], 'what' => hw_stages()[$e['stage']] ?? $e['stage'], 'note' => $e['note'], 'kind' => 'stage'];
    }
    $outcomes = contact_outcomes();
    foreach (rows('SELECT k.outcome, k.note, k.called_at, u.name FROM candidate_calls k LEFT JOIN users u ON u.id = k.user_id WHERE k.candidate_id = ? ORDER BY k.id DESC LIMIT 100', [(int) $a['candidate_id']]) as $k) {
        $out[] = ['when' => $k['called_at'], 'who' => $k['name'], 'what' => t($outcomes[$k['outcome']] ?? $k['outcome']), 'note' => $k['note'], 'kind' => 'contact'];
    }
    usort($out, static fn($x, $y) => strcmp((string) $y['when'], (string) $x['when']));

    return $out;
}

/**
 * P5-M28: an offer's validation, decided. Approved, the offer goes out with the
 * note the recruiter wrote (and the invitation, as a move to Offered sends it);
 * the recruiter and the candidate are told. Turned down, the recruiter is told why.
 */
function hiring_offer_settled(int $applicationId, string $state, array $request): void
{
    require_once __DIR__ . '/notices.php';
    $a = row('SELECT a.candidate_id, a.vacancy_id, c.full_name, v.title FROM applications a JOIN candidates c ON c.id = a.candidate_id JOIN vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$applicationId]);
    if (! $a) {
        return;
    }
    $recruiter = (int) ($request['requested_by'] ?? 0);
    $back = '/hiring?v=' . (int) $a['vacancy_id'] . '&app=' . $applicationId;
    if ($state === 'approved') {
        $note = (string) val("SELECT note FROM application_events WHERE application_id = ? AND stage = 'validation' ORDER BY id DESC LIMIT 1", [$applicationId]);
        $why = hw_move($applicationId, 'offered', $note !== '' ? $note : t('Offer validated.'), $recruiter ?: uid(), true);
        if ($why !== null) {
            notify_user($recruiter, t('Validated, but the offer could not go out for :name: :why', ['name' => $a['full_name'], 'why' => $why]), $back);
            return;
        }
        notify_user($recruiter, t(':name was validated; the offer for :job is with the candidate.', ['name' => $a['full_name'], 'job' => $a['title']]), $back);
        // The candidate is told by hw_move (P5-M52), in their portal and by email.
    } else {
        $comments = trim((string) val("SELECT comments FROM approval_requests WHERE subject_type = 'offer' AND subject_id = ? AND status = 'rejected' ORDER BY decided_at DESC LIMIT 1", [$applicationId]));
        notify_user($recruiter, t('The offer for :name was not validated:', ['name' => $a['full_name']]) . ' ' . ($comments !== '' ? $comments : t('no reason given.')), $back);
    }
}

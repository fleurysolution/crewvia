<?php
/**
 * One person, everything about them, and the next thing to do.
 *
 * This page held a contact card, a call log and a button. Everything else
 * about the same human being lived on a different screen: their application
 * on the screening board, their checks under Background, their documents
 * under Proofs, their contract under Contracts, their bed under Hotels. To
 * answer "where is Daniel up to" you opened six screens and held the answer
 * in your head.
 *
 * It is one page now. The same records, the same handlers, gathered around
 * the person they belong to, with the next action in reach of the fact that
 * calls for it.
 */

require_role('recruiter');
require_once __DIR__ . '/../placement-rates.php';

$id = (int) ($_GET['id'] ?? 0);
$c  = row('SELECT c.*, u.name AS owner_name FROM candidates c
           LEFT JOIN users u ON u.id = c.owner_id WHERE c.id = ?', [$id]);

if (! $c) {
    http_response_code(404);
    render('404', ['path' => 'that candidate']);
    exit;
}

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        q('UPDATE candidates SET phone=?, email=?, city=?, state=?, discipline=?, degree=?,
           years_exp=?, notes=? WHERE id=?', [
            trim((string) ($_POST['phone'] ?? '')) ?: null,
            trim((string) ($_POST['email'] ?? '')) ?: null,
            trim((string) ($_POST['city'] ?? '')) ?: null,
            trim((string) ($_POST['state'] ?? '')) ?: null,
            // The one list, so a trade a requisition can ask for is a trade
            // somebody can be filed under.
            array_key_exists($_POST['discipline'] ?? '', disciplines())
                ? $_POST['discipline'] : $c['discipline'],
            trim((string) ($_POST['degree'] ?? '')) ?: null,
            ($_POST['years_exp'] ?? '') !== '' ? (int) $_POST['years_exp'] : null,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
            $id,
        ]);

        log_activity('edited a candidate', 'candidate', $id, $c['full_name']);
        flash(t('Saved.'));
        redirect('/candidates/' . $id);
    }

    // ── the do-not-use register ─────────────────────────────────────
    if ($do === 'rehire') {
        require_role('recruiter');

        $status = (string) ($_POST['rehire_status'] ?? '');

        if (! array_key_exists($status, rehire_states())) {
            refuse(422, t('Unknown rehire decision.'));
        }

        $reason = trim((string) ($_POST['exclusion_reason'] ?? ''));

        // A block with no reason cannot be argued with later, and the
        // person it is about never gets to have it corrected.
        if (in_array($status, rehire_blocked(), true) && $reason === '') {
            flash(t('Say why. A block with no reason cannot be checked by anybody later.'), 'err');
            redirect('/candidates/' . $id . '#rehire');
        }

        if (mb_strlen($reason) > 1000) {
            flash(t('That reason is too long.'), 'err');
            redirect('/candidates/' . $id . '#rehire');
        }

        q('INSERT IGNORE INTO employee_profiles(candidate_id) VALUES (?)', [$id]);

        $blocking = in_array($status, rehire_blocked(), true);

        q('UPDATE employee_profiles
              SET rehire_status = ?, exclusion_reason = ?, excluded_by = ?, excluded_at = ?
            WHERE candidate_id = ?',
          [$status, $reason !== '' ? $reason : null,
           $blocking ? uid() : null,
           $blocking ? date('Y-m-d H:i:s') : null,
           $id]);

        // On the person's own record, so it travels with them to every
        // project rather than living on whichever one they were fired from.
        q('INSERT INTO candidate_events(candidate_id, user_id, event_type, detail) VALUES (?,?,?,?)',
          [$id, uid(), 'rehire decision',
           t(rehire_states()[$status]) . ($reason !== '' ? ' - ' . $reason : '')]);

        log_activity('set a rehire decision', 'candidate', $id,
                     $c['full_name'] . ': ' . rehire_states()[$status]);

        flash($blocking
            ? t(':name is on the register. Every recruiter sees it beside their name from now on.',
                ['name' => $c['full_name']])
            : t('Updated: :name is :state.',
                ['name' => $c['full_name'], 'state' => t(rehire_states()[$status])]));

        redirect('/candidates/' . $id . '#rehire');
    }

    // ── what they can actually do ───────────────────────────────────
    if ($do === 'add_skill') {
        require_role('recruiter');

        $slug = (string) ($_POST['skill'] ?? '');

        if (! array_key_exists($slug, skills_list())) {
            flash(t('That is not a skill on the list.'), 'err');
            redirect('/candidates/' . $id . '#skills');
        }

        $years = trim((string) ($_POST['years'] ?? ''));

        if ($years !== '' && (! ctype_digit($years) || (int) $years > 60)) {
            flash(t('Years of experience must be a whole number up to 60.'), 'err');
            redirect('/candidates/' . $id . '#skills');
        }

        q('INSERT INTO candidate_skills(candidate_id, skill_slug, years, confirmed, added_by)
           VALUES (?,?,?,1,?)
           ON DUPLICATE KEY UPDATE years = VALUES(years), confirmed = 1,
                                   added_by = VALUES(added_by)',
          [$id, $slug, $years !== '' ? (int) $years : null, uid()]);

        log_activity('confirmed a skill', 'candidate', $id,
                     $c['full_name'] . ': ' . skills_list()[$slug]);

        flash(t(':skill recorded for :name.',
                ['skill' => t(skills_list()[$slug]), 'name' => $c['full_name']]));

        redirect('/candidates/' . $id . '#skills');
    }

    if ($do === 'remove_skill') {
        require_role('recruiter');

        q('DELETE FROM candidate_skills WHERE candidate_id = ? AND skill_slug = ?',
          [$id, (string) ($_POST['skill'] ?? '')]);

        flash(t('Skill removed.'));
        redirect('/candidates/' . $id . '#skills');
    }

    if ($do === 'call') {
        // Validated against the one list the screens offer, so a new
        // outcome can never be offered and then silently blanked by the
        // column it is written to.
        if (! array_key_exists((string) ($_POST['outcome'] ?? 'reached'), contact_outcomes())) {
            refuse(422, t('Invalid contact outcome.'));
        }

        q('INSERT INTO candidate_calls (candidate_id, user_id, outcome, note) VALUES (?,?,?,?)',
          [$id, uid(), (string) ($_POST['outcome'] ?? 'reached'),
           trim((string) ($_POST['note'] ?? '')) ?: null]);

        q('UPDATE candidates SET last_contact_at = NOW(), owner_id = COALESCE(owner_id, ?)'
          . ($c['stage'] === 'new' ? ", stage='contacted'" : '') . ' WHERE id = ?', [uid(), $id]);

        flash(t('Call logged.'));
        redirect('/candidates/' . $id);
    }

    // Moving an application along, from the person rather than from the board.
    // The same rules as the screening screen, because they are the rules.
    if ($do === 'stage') {
        $application = row('SELECT a.*, v.job_id FROM applications a
                            JOIN vacancies v ON v.id = a.vacancy_id
                            WHERE a.id = ? AND a.candidate_id = ?',
                           [(int) ($_POST['application_id'] ?? 0), $id]);

        if (! $application) {
            refuse(404, t('That application is not this person\'s.'));
        }

        $stage = (string) ($_POST['stage'] ?? '');

        if (! in_array($stage, ['new','screening','interview','offered','rejected','withdrawn'], true)) {
            refuse(422, t('That is not a stage an application can be in.'));
        }

        if ($stage === 'offered') {
            if ((int) val("SELECT COUNT(*) FROM screening_checks
                           WHERE application_id = ? AND status <> 'passed'", [$application['id']])) {
                flash(t('Complete screening checks before offering this job.'), 'err');
                redirect('/candidates/' . $id);
            }

            if (trim((string) ($_POST['note'] ?? '')) === '') {
                flash(t('Enter the complete offer terms before issuing an offer.'), 'err');
                redirect('/candidates/' . $id);
            }
        }

        q('UPDATE applications SET stage = ? WHERE id = ?', [$stage, $application['id']]);
        q('INSERT INTO application_events(application_id, user_id, stage, note) VALUES (?,?,?,?)',
          [$application['id'], uid(), $stage, trim((string) ($_POST['note'] ?? ''))]);

        if (in_array($stage, ['screening','offered'], true)) {
            require_once __DIR__ . '/../invitations.php';

            try {
                workforce_invite_candidate($id);
            } catch (Throwable $error) {
                flash(t('Stage recorded. Invitation needs attention: :message',
                        ['message' => t($error->getMessage())]), 'err');
                redirect('/candidates/' . $id);
            }
        }

        log_activity('moved an application', 'application', (int) $application['id'], $stage);
        flash(t('Moved to :stage.', ['stage' => t(ucfirst($stage))]));
        redirect('/candidates/' . $id);
    }

    if ($do === 'add_check') {
        $application = row('SELECT a.id FROM applications a WHERE a.id = ? AND a.candidate_id = ?',
                           [(int) ($_POST['application_id'] ?? 0), $id]);
        $title       = trim((string) ($_POST['title'] ?? ''));

        if ($application && $title !== '' && mb_strlen($title) <= 190) {
            q('INSERT INTO screening_checks(application_id, title) VALUES (?,?)',
              [$application['id'], $title]);
            flash(t('Check added.'));
        }

        redirect('/candidates/' . $id);
    }

    if ($do === 'check') {
        $check = row('SELECT k.* FROM screening_checks k
                      JOIN applications a ON a.id = k.application_id
                      WHERE k.id = ? AND a.candidate_id = ?',
                     [(int) ($_POST['check_id'] ?? 0), $id]);

        $status = (string) ($_POST['status'] ?? '');

        if ($check && in_array($status, ['pending','passed','failed'], true)) {
            q('UPDATE screening_checks SET status = ? WHERE id = ?', [$status, $check['id']]);
            log_activity('reviewed a screening check', 'candidate', $id, $check['title'] . ' ' . $status);
            flash(t('Check updated.'));
        }

        redirect('/candidates/' . $id);
    }

    // What this one person is paid, which is allowed to differ from the role.
    if ($do === 'rates') {
        require_role('admin');

        $placement = row('SELECT * FROM placements WHERE id = ? AND candidate_id = ?',
                         [(int) ($_POST['placement_id'] ?? 0), $id]);

        if (! $placement) {
            refuse(404, t('That assignment is not this person\'s.'));
        }

        $values = [];

        foreach (['pay_rate' => 100000.0, 'bill_rate' => 100000.0,
                  'per_diem_rate' => 1000.0, 'guarantee_hours' => 168.0] as $field => $ceiling) {
            $given = trim((string) ($_POST[$field] ?? ''));

            if ($given === '') {
                $values[$field] = null;
                continue;
            }

            if (! is_numeric($given) || (float) $given < 0 || (float) $given > $ceiling) {
                flash(t('Check the rates: a negative or impossible number was entered.'), 'err');
                redirect('/candidates/' . $id);
            }

            $values[$field] = $field === 'guarantee_hours' ? (int) $given : (float) $given;
        }

        q('UPDATE placements SET pay_rate = ?, bill_rate = ?, per_diem_rate = ?, guarantee_hours = ?
           WHERE id = ?',
          [$values['pay_rate'], $values['bill_rate'],
           $values['per_diem_rate'], $values['guarantee_hours'], $placement['id']]);

        log_activity('changed what somebody is paid', 'placement', (int) $placement['id'],
                     $c['full_name'] . ' ' . (string) $values['pay_rate']);

        flash(t('Rates saved. Weeks already approved keep what they were calculated on.'));
        redirect('/candidates/' . $id);
    }

    if ($do === 'place') {
        if (! $job || $job['status'] === 'closed') {
            flash(t('There is no active job to place them on.'), 'err');
            redirect('/candidates/' . $id);
        }

        db()->beginTransaction();
        q('SELECT id FROM candidates WHERE id=? FOR UPDATE', [$id]);

        $already = row("SELECT id FROM placements WHERE candidate_id = ? AND job_id = ?
                        AND status NOT IN ('completed','cancelled')", [$id, $jobId]);

        if ($already) {
            db()->rollBack();
            flash(t(':name is already on this job.', ['name' => $c['full_name']]), 'err');
            redirect('/candidates/' . $id);
        }

        // The money comes from the requisition this person applied through,
        // and from its line of the scope of work where the requisition did
        // not set its own. Nothing falls back to a project-wide rate.
        $vacancy = (int) val('SELECT a.vacancy_id FROM applications a
                              JOIN vacancies v ON v.id = a.vacancy_id
                              WHERE a.candidate_id = ? AND v.job_id = ?
                              ORDER BY a.id DESC LIMIT 1', [$id, $jobId]);

        $rates = placement_rates($jobId, $vacancy ?: null);

        q('INSERT INTO placements (candidate_id, job_id, status, start_date, created_by,
                                   pay_rate, bill_rate, per_diem_rate, guarantee_hours)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$id, $jobId, 'offered', ($_POST['start_date'] ?? '') ?: null, uid(),
           $rates['pay_rate'], $rates['bill_rate'],
           $rates['per_diem_rate'], $rates['guarantee_hours']]);

        $pid = (int) db()->lastInsertId();

        q("UPDATE candidates SET stage='accepted' WHERE id=?", [$id]);
        db()->commit();

        log_activity('placed a candidate', 'placement', $pid, $c['full_name']);
        flash(t(':name added to the roster. They now need a bed and a flight.',
                ['name' => $c['full_name']]));
        redirect('/candidates/' . $id);
    }
}

// ── everything about this person ─────────────────────────────────────────
require_once __DIR__ . '/../hr.php';

// Their record across every project: how many jobs, how many finished,
// and what the supervisors said. The question a recruiter actually asks
// before picking up the telephone.
$workHistory = person_work_history($id);
$owedToUs    = advance_owed($id);

// Where this person stands on the register, and who decided it.
$standing = row('SELECT p.*, u.name AS decided_by
                 FROM employee_profiles p
                 LEFT JOIN users u ON u.id = p.excluded_by
                 WHERE p.candidate_id = ?', [$id]) ?: [];

// What they can do. Confirmed by somebody here, or only claimed on an
// application - a recruiter needs to know which before relying on it.
$theirSkills = rows('SELECT skill_slug, years, confirmed
                     FROM candidate_skills
                     WHERE candidate_id = ?
                     ORDER BY confirmed DESC, skill_slug', [$id]);

$calls = rows('SELECT k.*, u.name AS who FROM candidate_calls k
               LEFT JOIN users u ON u.id = k.user_id
               WHERE k.candidate_id = ? ORDER BY k.called_at DESC LIMIT 40', [$id]);

$applications = rows('SELECT a.*, v.title AS role, v.discipline, j.title AS project, j.id AS job_id
                      FROM applications a
                      JOIN vacancies v ON v.id = a.vacancy_id
                      JOIN jobs j ON j.id = v.job_id
                      WHERE a.candidate_id = ? ORDER BY a.id DESC', [$id]);

foreach ($applications as &$application) {
    $application['checks'] = rows('SELECT * FROM screening_checks
                                   WHERE application_id = ? ORDER BY id', [$application['id']]);

    $application['answers'] = rows('SELECT q.question, s.answer
                                    FROM screening_questions q
                                    LEFT JOIN screening_answers s
                                         ON s.question_id = q.id AND s.application_id = ?
                                    WHERE q.job_id = ? ORDER BY q.id',
                                   [$application['id'], $application['job_id']]);
}

unset($application);

// The terms behind each placement are the ones on the line of the scope the
// person applied against, not a single rate on the project.
$placements = rows('SELECT p.*, j.title AS project, j.strike_live,
                           sl.role_title AS line_role,
                           sl.pay_rate AS line_pay, sl.per_diem_rate AS line_diem,
                           sl.guarantee_hours AS line_guarantee,
                           sl.strike_guarantee_hours AS line_strike_guarantee,
                           h.name AS hotel, l.room_number, l.private_room, l.check_in,
                           ti.arrive_time, ti.carrier, ti.reference,
                           u.name AS supervisor, d.trade, d.shift_label
                    FROM placements p
                    JOIN jobs j ON j.id = p.job_id
                    LEFT JOIN job_order_lines sl ON sl.id = (
                        SELECT v.order_line_id FROM applications a
                        JOIN vacancies v ON v.id = a.vacancy_id AND v.job_id = p.job_id
                        WHERE a.candidate_id = p.candidate_id
                        ORDER BY a.id DESC LIMIT 1)
                    LEFT JOIN lodging l ON l.placement_id = p.id AND l.status <> \'cancelled\'
                    LEFT JOIN hotels h ON h.id = l.hotel_id
                    LEFT JOIN travel ti ON ti.placement_id = p.id
                         AND ti.direction = \'inbound\' AND ti.status <> \'cancelled\'
                    LEFT JOIN assignment_details d ON d.placement_id = p.id
                    LEFT JOIN users u ON u.id = d.supervisor_id
                    WHERE p.candidate_id = ? ORDER BY p.id DESC', [$id]);

$documents = rows('SELECT id, document_type, status, created_at FROM worker_documents
                   WHERE candidate_id = ? ORDER BY id DESC LIMIT 30', [$id]);

$qualifications = [];

try {
    $qualifications = rows('SELECT q.*, t.name FROM candidate_qualifications q
                            LEFT JOIN qualification_types t ON t.id = q.type_id
                            WHERE q.candidate_id = ? ORDER BY q.id DESC LIMIT 30', [$id]);
} catch (Throwable $e) {
    $qualifications = [];
}

$contracts = rows('SELECT ct.* FROM employment_contracts ct
                   JOIN applications a ON a.id = ct.application_id
                   WHERE a.candidate_id = ? ORDER BY ct.id DESC', [$id]);

$clearance = row('SELECT * FROM employment_clearance WHERE candidate_id = ?', [$id]);

$account = row('SELECT u.id, u.email, u.is_active, u.last_login_at
                FROM worker_accounts w JOIN users u ON u.id = w.user_id
                WHERE w.candidate_id = ?', [$id]);

$invitation = row('SELECT expires_at, used_at FROM invitations
                   WHERE candidate_id = ? ORDER BY id DESC LIMIT 1', [$id]);

$pageTitle = $c['full_name'] . ' · ' . $config['app_name'];

render('candidate', compact('c', 'calls', 'applications', 'placements', 'documents',
                            'qualifications', 'contracts', 'clearance', 'account',
                            'invitation', 'job', 'jobId', 'standing', 'theirSkills',
                            'workHistory', 'owedToUs'));

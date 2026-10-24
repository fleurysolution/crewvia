<?php
/**
 * Requisitions: what the client asked for, in terms a recruiter can screen
 * against.
 *
 * This used to take a title and a paragraph. Everything that decides whether
 * somebody fits - the discipline, how many are needed, the shift, the degree,
 * when they start - lived inside that paragraph or nowhere at all, so the
 * candidate list had nothing structured to match on.
 *
 * It also answered a bad submission with a bare 422 and no page. A recruiter
 * who left a field blank lost what they had typed; now the form comes back
 * with the reason.
 */

// Recruiters work the requisitions that exist; raising, closing or
// removing one is an administrator's decision, because it commits the
// agency to a client order.
require_role('recruiter');
require_once __DIR__ . '/../scope.php';

// P5-M103: a link to one requisition (from the email) opens its project.
if (isset($_GET['req']) && ($reqJob = (int) val('SELECT job_id FROM vacancies WHERE id = ?', [(int) $_GET['req']]))) {
    $_SESSION['job_id'] = $reqJob;
}
$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

/** Being on the job, as the roster and the client both mean it. */
const DEPLOYED_STATUSES = "'confirmed','travelling','on_site'";

// The order line arrives with the scope-of-work upgrade. Until it has run,
// this page works as it did rather than failing on a column that is not
// there yet.
$hasScope = (int) val("SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema = DATABASE() AND table_name = 'vacancies'
                         AND column_name = 'order_line_id'") > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? 'create');
    // P5-M85: the hiring managers of a requisition are changed from its line.
    if ($do === 'managers') {
        require_once __DIR__ . '/../reviews.php';
        $vid = (int) val('SELECT id FROM vacancies WHERE id = ?', [(int) ($_POST['vacancy_id'] ?? 0)]);
        if ($vid) { vacancy_managers_save($vid, (array) ($_POST['managers'] ?? [])); require_once __DIR__ . '/../checklists.php'; cl_vacancy_save($vid, (array) ($_POST['checklists'] ?? [])); log_activity('set the hiring managers and checklists', 'vacancy', $vid); flash(t('Saved: the hiring managers and checklists.')); }
        redirect('/requisitions');
    }

    if (in_array($do, ['close', 'reopen', 'remove'], true)) {
        require_role('admin');

        $vacancy = row('SELECT v.*, (SELECT COUNT(*) FROM applications a
                                      WHERE a.vacancy_id = v.id) applications
                        FROM vacancies v WHERE v.id = ? AND v.job_id = ?',
                       [(int) ($_POST['vacancy_id'] ?? 0), $jobId]);

        if (! $vacancy) {
            refuse(404, t('That requisition is not on this project.'));
        }

        if ($do === 'remove') {
            // An order that received applications holds the record of
            // everybody who answered it. That record outlives the order.
            if ((int) $vacancy['applications'] > 0) {
                flash(t('This requisition has applications against it, so it can be closed but not removed.'), 'err');
                redirect('/requisitions');
            }

            q('DELETE FROM requisition_publication WHERE vacancy_id = ?', [$vacancy['id']]);
            q('DELETE FROM vacancies WHERE id = ?', [$vacancy['id']]);
            log_activity('removed requisition', 'vacancy', (int) $vacancy['id'], $vacancy['title']);
            flash(t('Requisition removed. Nothing had been received against it.'));
            redirect('/requisitions');
        }

        $open = $do === 'reopen' ? 1 : 0;
        q('UPDATE vacancies SET is_open = ? WHERE id = ?', [$open, $vacancy['id']]);
        log_activity($open ? 'reopened requisition' : 'closed requisition',
                     'vacancy', (int) $vacancy['id'], $vacancy['title']);

        flash($open
            ? t(':title is open again and its link accepts applications.', ['title' => $vacancy['title']])
            : t(':title is closed. Its link no longer accepts applications; everybody who already applied is kept.', ['title' => $vacancy['title']]));

        redirect('/requisitions');
    }

    // P5-M105: the recruiter completes a requisition - the detail candidates need - before or after publishing it.
    // The agreed rates are not here: they stay on the scope line (and the administrator's override).
    if ($do === 'edit') {
        $vacancy = row('SELECT * FROM vacancies WHERE id = ? AND job_id = ?', [(int) ($_POST['vacancy_id'] ?? 0), $jobId]);
        if (! $vacancy) {
            refuse(404, t('That requisition is not on this project.'), '/requisitions');
        }
        $back = '/requisitions?req=' . (int) $vacancy['id'] . '#req-' . (int) $vacancy['id'];
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $openings = (int) ($_POST['openings'] ?? 0);
        $years = trim((string) ($_POST['years_experience'] ?? ''));
        $startsOn = trim((string) ($_POST['starts_on'] ?? ''));
        if ($title === '' || $description === '' || mb_strlen($title) > 190 || mb_strlen($description) > 10000) {
            refuse(422, t('Enter the role and its description.'), $back);
        }
        if ($openings < 1 || $openings > 9999) {
            refuse(422, t('Enter how many people are needed, between 1 and 9999.'), $back);
        }
        if ($years !== '' && (! ctype_digit($years) || (int) $years > 60)) {
            refuse(422, t('Years of experience: a number from 0 to 60.'), $back);
        }
        if ($startsOn !== '' && ! valid_date($startsOn)) {
            refuse(422, t('Choose a valid start date.'), $back);
        }
        // More people than the agreement's line still allows is refused, as when it was raised.
        if ($hasScope && ! empty($vacancy['order_line_id'])) {
            $line = scope_line((int) $vacancy['order_line_id'], $jobId);
            $others = (int) val('SELECT COALESCE(SUM(openings), 0) FROM vacancies WHERE order_line_id = ? AND id <> ?', [(int) $vacancy['order_line_id'], (int) $vacancy['id']]);
            if ($line && $openings > (int) $line['quantity'] - $others) {
                refuse(422, t('The agreement covers :asked :role; only :left can be on this requisition.', ['asked' => (int) $line['quantity'], 'role' => $line['role_title'], 'left' => max(0, (int) $line['quantity'] - $others)]), $back);
            }
        }
        $discipline = array_key_exists((string) ($_POST['discipline'] ?? ''), disciplines()) ? (string) $_POST['discipline'] : (string) ($vacancy['discipline'] ?? 'other');
        q('UPDATE vacancies SET title = ?, description = ?, discipline = ?, openings = ?, shift = ?, requirements = ?, degree = ?, years_experience = ?, starts_on = ? WHERE id = ?',
          [$title, $description, $discipline, $openings,
           mb_substr(trim((string) ($_POST['shift'] ?? '')), 0, 60) ?: null,
           mb_substr(trim((string) ($_POST['requirements'] ?? '')), 0, 4000) ?: null,
           mb_substr(trim((string) ($_POST['degree'] ?? '')), 0, 190) ?: null,
           $years === '' ? null : (int) $years, $startsOn ?: null, (int) $vacancy['id']]);
        log_activity('edited requisition', 'vacancy', (int) $vacancy['id'], $title);
        flash(t(':title is updated.', ['title' => $title]));
        redirect($back);
    }

    // P5-M103: a recruiter takes a requested requisition in hand and publishes it; whoever requested it is told.
    if ($do === 'publish') {
        $vacancy = row("SELECT * FROM vacancies WHERE id = ? AND job_id = ? AND posting_status = 'requested'", [(int) ($_POST['vacancy_id'] ?? 0), $jobId]);
        if (! $vacancy) {
            refuse(404, t('That requisition is not waiting to be published on this project.'), '/requisitions');
        }
        q("UPDATE vacancies SET posting_status = 'published', published_by = ?, published_at = NOW() WHERE id = ?", [uid(), (int) $vacancy['id']]);
        if (! val('SELECT vacancy_id FROM requisition_publication WHERE vacancy_id = ?', [(int) $vacancy['id']])) {
            q('INSERT INTO requisition_publication (vacancy_id, published_on, expires_on) VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))', [(int) $vacancy['id']]);
        }
        log_activity('published requisition', 'vacancy', (int) $vacancy['id'], (string) $vacancy['title']);
        require_once __DIR__ . '/../notices.php';
        if (! empty($vacancy['requested_by']) && (int) $vacancy['requested_by'] !== uid()) {
            notify_by_email((int) $vacancy['requested_by'], t('Published: :title', ['title' => $vacancy['title']]),
                t(':who published your requisition :title. It is on the careers page and accepts applications.', ['who' => (string) (user()['name'] ?? ''), 'title' => $vacancy['title']]),
                '/requisitions?req=' . (int) $vacancy['id'] . '#req-' . (int) $vacancy['id'], 'published:' . (int) $vacancy['id']);
        }
        flash(t(':title is published: on the careers page, and its link accepts applications.', ['title' => $vacancy['title']]));
        redirect('/requisitions?req=' . (int) $vacancy['id'] . '#req-' . (int) $vacancy['id']);
    }

    // Walkthrough finding: the recruiter was shown this form but refused on
    // saving it. Raising a requisition is the recruiter's work; it is still
    // capped by the agreement's line, and the agreed rates stay the
    // administrator's to override (below).
    require_role('recruiter');
    $title       = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (! $jobId) {
        flash(t('Choose a project first.'), 'err');
        redirect('/requisitions');
    }

    if ($title === '' || $description === ''
        || mb_strlen($title) > 190 || mb_strlen($description) > 10000) {
        flash(t('Select a project and enter valid requisition details.'), 'err');
        redirect('/requisitions');
    }

    $discipline = (string) ($_POST['discipline'] ?? 'other');

    if (! array_key_exists($discipline, disciplines())) {
        $discipline = 'other';
    }

    $openings = (int) ($_POST['openings'] ?? 1);

    if ($openings < 1 || $openings > 9999) {
        flash(t('Enter how many people are needed, between 1 and 9999.'), 'err');
        redirect('/requisitions');
    }

    // A requisition is raised against a line of the scope of work. That line
    // is where the rates live, and it is also the limit: the agency cannot
    // order twenty-five engineers against a line the client agreed twenty of.
    $scope  = $hasScope ? scope_lines($jobId) : [];
    $lineId = (int) ($_POST['order_line_id'] ?? 0);
    $line   = $hasScope && $lineId ? scope_line($lineId, $jobId) : null;

    if ($scope && ! $line) {
        flash(t('Pick the line of the scope of work this requisition is raised against. Its rates are the agreed ones.'), 'err');
        redirect('/requisitions');
    }

    if ($line) {
        $alreadyOrdered = (int) val('SELECT COALESCE(SUM(openings), 0) FROM vacancies
                                     WHERE order_line_id = ?', [(int) $line['id']]);

        $remaining = max(0, (int) $line['quantity'] - $alreadyOrdered);

        if ($openings > $remaining) {
            flash(t('The agreement covers :asked :role and :ordered are already on requisitions, so only :left can still be raised.', [
                'asked'   => (int) $line['quantity'],
                'role'    => $line['role_title'],
                'ordered' => $alreadyOrdered,
                'left'    => $remaining,
            ]), 'err');
            redirect('/requisitions');
        }
    }

    $years = trim((string) ($_POST['years_experience'] ?? ''));
    $years = $years === '' ? null : max(0, min(60, (int) $years));

    $startsOn = (string) ($_POST['starts_on'] ?? '');

    if ($startsOn !== '' && ! valid_date($startsOn)) {
        $startsOn = '';
    }

    // Money belongs to the line of the scope. Blank here means the line's
    // agreed terms apply, which is what most requisitions will do: the rate
    // was settled with the client when the order was written, and a
    // recruiter retyping it is how the two drift apart.
    $money = [];

    foreach (['pay_rate' => 100000.0, 'bill_rate' => 100000.0,
              'per_diem_rate' => 1000.0, 'guarantee_hours' => 168.0] as $field => $ceiling) {
        $given = trim((string) ($_POST[$field] ?? ''));

        // Only an administrator overrides what the client agreed.
        if ($given !== '' && ! can('admin')) {
            refuse(403, t('Only an administrator can override the rates agreed on the scope of work.'), '/requisitions');
        }

        if ($given === '') {
            $money[$field] = null;
            continue;
        }

        if (! is_numeric($given) || (float) $given < 0 || (float) $given > $ceiling) {
            flash(t('Check the rates: a negative or impossible number was entered.'), 'err');
            redirect('/requisitions');
        }

        $money[$field] = $field === 'guarantee_hours' ? (int) $given : (float) $given;
    }

    // The order line is written only where the column exists, so an
    // installation that has not run the scope-of-work upgrade still raises
    // requisitions instead of failing on an unknown column.
    $columns = ['job_id', 'title', 'description', 'discipline', 'openings',
                'shift', 'requirements', 'degree', 'years_experience', 'starts_on',
                'pay_rate', 'bill_rate', 'per_diem_rate', 'guarantee_hours'];

    $values  = [$jobId, $title, $description, $discipline, $openings,
                trim((string) ($_POST['shift'] ?? '')) ?: ($line['shift'] ?? null),
                trim((string) ($_POST['requirements'] ?? '')) ?: null,
                trim((string) ($_POST['degree'] ?? '')) ?: null,
                $years,
                $startsOn ?: null,
                $money['pay_rate'], $money['bill_rate'],
                $money['per_diem_rate'], $money['guarantee_hours']];

    if ($hasScope) {
        array_splice($columns, 1, 0, 'order_line_id');
        array_splice($values, 1, 0, [$line ? (int) $line['id'] : null]);
    }

    q('INSERT INTO vacancies (' . implode(', ', $columns) . ')
       VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', $values);

    $vacancyId = (int) db()->lastInsertId();
    // P5-M85: who decides on this requisition's applicants.
    require_once __DIR__ . '/../reviews.php';
    vacancy_managers_save($vacancyId, (array) ($_POST['managers'] ?? []));
    // P5-M86: the checklists the recruiter asks for this requisition.
    require_once __DIR__ . '/../checklists.php';
    cl_vacancy_save($vacancyId, (array) ($_POST['checklists'] ?? []));

    // P5-M103: "Send to recruiting" creates the requisition as REQUESTED - not public, its link closed - until a
    // recruiter takes it in hand and publishes it. "Create and publish" publishes it at once, as before.
    $requested = ($_POST['send'] ?? 'publish') === 'request';
    if ($requested) {
        q("UPDATE vacancies SET posting_status = 'requested', requested_by = ?, requested_at = NOW() WHERE id = ?", [uid(), $vacancyId]);
    } else {
        q("UPDATE vacancies SET published_by = ?, published_at = NOW() WHERE id = ?", [uid(), $vacancyId]);
        q('INSERT INTO requisition_publication (vacancy_id, published_on, expires_on)
           VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))', [$vacancyId]);
    }

    log_activity($requested ? 'sent a requisition to recruiting' : 'created requisition', 'vacancy', $vacancyId, $title . ' x' . $openings);
    // P5-M28: it is every recruiter's to fill; the plant's project manager is told too.
    // P5-M103: by email as well, so the request is seen outside Crewvia and the screen can show when it left.
    require_once __DIR__ . '/../notices.php';
    $start = $startsOn ? ' ' . t('starting :date', ['date' => d($startsOn)]) : '';
    $notice = $requested
        ? t(':who sent a requisition to recruiting on :project: :n x :title:start. Complete it and publish it.', ['who' => (string) (user()['name'] ?? ''), 'project' => (string) ($job['title'] ?? ''), 'n' => $openings, 'title' => $title, 'start' => $start])
        : t('New requisition on :project: :n x :title:start. Find the candidates.', ['project' => (string) ($job['title'] ?? ''), 'n' => $openings, 'title' => $title, 'start' => $start]);
    $subject = $requested ? t('Requisition to publish: :title', ['title' => $title]) : t('New requisition: :title', ['title' => $title]);
    $target = $requested ? '/requisitions?req=' . $vacancyId . '#req-' . $vacancyId : '/candidates?vacancy=' . $vacancyId;
    $mailed = 0;
    foreach (rows("SELECT id FROM users WHERE is_active = 1 AND role IN ('recruiter','admin') AND id <> ?", [uid()]) as $r) {
        $mailed += notify_by_email((int) $r['id'], $subject, $notice, $target, 'requisition:' . $vacancyId) ? 1 : 0;
    }
    if (! empty($job['project_manager_id']) && (int) $job['project_manager_id'] !== uid()) { notify_user((int) $job['project_manager_id'], $notice, '/my-team'); }
    flash($requested
        ? tn($mailed, 'Sent to recruiting: :n recruiter was emailed. It is published when a recruiter publishes it.', 'Sent to recruiting: :n recruiters were emailed. It is published when a recruiter publishes it.')
        : t('Requisition created. Share its QR code or public link to start receiving applications.'));
    redirect('/requisitions?req=' . $vacancyId . '#req-' . $vacancyId);
}

// The extra columns arrive with a later upgrade. An installation that has not
// run it yet must still render this page rather than fail on an unknown
// column, so the select is built to match what is actually there.
$hasDetail = (int) val("SELECT COUNT(*) FROM information_schema.columns
                        WHERE table_schema = DATABASE() AND table_name = 'vacancies'
                          AND column_name = 'discipline'") > 0;

$select = $hasDetail
    ? 'v.*,'
    : "v.id, v.job_id, v.title, v.description, v.is_open,
       'other' AS discipline, 1 AS openings, NULL AS shift, NULL AS requirements,
       NULL AS degree, NULL AS years_experience, NULL AS starts_on,
       NULL AS pay_rate, NULL AS bill_rate, NULL AS per_diem_rate,
       NULL AS guarantee_hours, NULL AS order_line_id,";

$lineColumns = $hasScope
    ? "l.role_title line_role, l.quantity line_quantity,
       l.pay_rate line_pay, l.bill_rate line_bill,
       l.per_diem_rate line_diem, l.guarantee_hours line_guarantee,"
    : "NULL line_role, NULL line_quantity, NULL line_pay, NULL line_bill,
       NULL line_diem, NULL line_guarantee,";

$lineJoin = $hasScope ? 'LEFT JOIN job_order_lines l ON l.id = v.order_line_id' : '';

$requisitions = rows(
    "SELECT " . $select . " j.site_city, j.site_state, " . $lineColumns . "
            (SELECT COUNT(*) FROM applications a WHERE a.vacancy_id = v.id) applications,
            (SELECT COUNT(*) FROM applications a2
               JOIN placements p ON p.candidate_id = a2.candidate_id AND p.job_id = v.job_id
              WHERE a2.vacancy_id = v.id
                AND p.status IN (" . DEPLOYED_STATUSES . ")) placed,
            -- P5-M103: the trail Jerry asked to see - who requested it, when the emails left, who published it.
            (SELECT name FROM users WHERE id = v.requested_by) requested_name,
            (SELECT name FROM users WHERE id = v.published_by) published_name,
            (SELECT COUNT(*) FROM mail_outbox m WHERE m.purpose = CONCAT('requisition:', v.id)) mailed,
            (SELECT COUNT(*) FROM mail_outbox m WHERE m.purpose = CONCAT('requisition:', v.id) AND m.status = 'sent') mailed_sent,
            (SELECT MAX(m.sent_at) FROM mail_outbox m WHERE m.purpose = CONCAT('requisition:', v.id)) mailed_at
     FROM vacancies v
     JOIN jobs j ON j.id = v.job_id " . $lineJoin . "
     WHERE v.job_id = ? ORDER BY v.id DESC", [$jobId]);

// The lines of the scope, with how much of each is still unordered, so a
// recruiter raising a requisition can see what the agreement has left.
$scope = $jobId && $hasScope ? scope_lines($jobId) : [];

foreach ($scope as $i => $line) {
    $ordered = (int) val('SELECT COALESCE(SUM(openings), 0) FROM vacancies WHERE order_line_id = ?',
                         [(int) $line['id']]);

    $scope[$i]['ordered']   = $ordered;
    $scope[$i]['remaining'] = max(0, (int) $line['quantity'] - $ordered);
}

require_once __DIR__ . '/../reviews.php';
$managerChoices = hiring_managers(); require_once __DIR__ . '/../checklists.php'; $checklistChoices = cl_templates(); $vacancyChecklists = [];
$vacancyManagers = [];
foreach ($requisitions as $rq) { $vacancyManagers[(int) $rq['id']] = vacancy_managers((int) $rq['id']); $vacancyChecklists[(int) $rq['id']] = cl_vacancy_templates((int) $rq['id']); }
render('requisitions', compact('managerChoices', 'vacancyManagers', 'checklistChoices', 'vacancyChecklists', 'requisitions', 'job', 'scope'));

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

    require_role('admin');
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

    q('INSERT INTO requisition_publication (vacancy_id, published_on, expires_on)
       VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))', [$vacancyId]);

    log_activity('created requisition', 'vacancy', $vacancyId, $title . ' x' . $openings);
    flash(t('Requisition created. Share its QR code or public link to start receiving applications.'));
    redirect('/requisitions');
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
                AND p.status IN (" . DEPLOYED_STATUSES . ")) placed
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

render('requisitions', compact('requisitions', 'job', 'scope'));

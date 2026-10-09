<?php
/**
 * The project: the agreement, and the scope of work it is delivered under.
 *
 * An agreement says what the work is - "provide labour support to the plant
 * in accordance with the scope of work" - and what it covers beyond the
 * hourly rate. The scope itself is a schedule of lines: twenty engineers,
 * forty mechanical, a hundred labourers, two thousand machinists, each with
 * its own pay, bill, per diem and guaranteed week.
 *
 * There is no single project rate any more. One number cannot be both an
 * engineer's hour and a labourer's, and pretending otherwise made every
 * figure downstream - the contract, the invoice, the margin - wrong for
 * everybody except the one trade the number happened to fit.
 */

require_role('admin');
require_once __DIR__ . '/../lifecycle.php';
require_once __DIR__ . '/../projects.php';
require_once __DIR__ . '/../scope.php';

$job = current_job();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $job) {
    $jobId = (int) $job['id'];

    /**
     * The money on a line of the scope, or a refusal naming what was wrong.
     *
     * Every one of these is optional: a line can be agreed in principle on
     * Monday and priced on Wednesday, and the screens say which lines are
     * still unpriced rather than quietly treating them as free.
     *
     * @return array<string,int|float|null>
     */
    $lineTerms = static function (): array {
        $ceilings = ['pay_rate' => 100000.0, 'bill_rate' => 100000.0,
                     'per_diem_rate' => 1000.0, 'guarantee_hours' => 168.0,
                     'strike_guarantee_hours' => 168.0, 'overtime_after' => 168.0,
                     'overtime_multiplier' => 10.0];

        $terms = [];

        foreach ($ceilings as $field => $ceiling) {
            $given = trim((string) ($_POST[$field] ?? ''));

            if ($given === '') {
                $terms[$field] = null;
                continue;
            }

            if (! is_numeric($given) || ! is_finite((float) $given)
                || (float) $given < 0 || (float) $given > $ceiling) {
                return ['error' => $field];
            }

            $terms[$field] = str_contains($field, 'hours') || $field === 'overtime_after'
                ? (int) $given
                : (float) $given;
        }

        return $terms;
    };

    /** The headcount asked for is the sum of the order, never typed twice. */
    $retotal = static function (int $jobId): void {
        q('UPDATE jobs SET headcount_target =
             (SELECT COALESCE(SUM(quantity), 0) FROM job_order_lines WHERE job_id = ?)
           WHERE id = ?', [$jobId, $jobId]);
    };

    // ── a line of the scope of work ─────────────────────────────────────
    if (in_array($_POST['do'] ?? '', ['add_line', 'save_line'], true)) {
        $editing = ($_POST['do'] ?? '') === 'save_line'
            ? scope_line((int) ($_POST['line_id'] ?? 0), $jobId)
            : null;

        if (($_POST['do'] ?? '') === 'save_line' && ! $editing) {
            flash(t('That line is not on this agreement.'), 'err');
            redirect('/job#scope');
        }

        $role = trim((string) ($_POST['role_title'] ?? ''));

        if ($role === '' || mb_strlen($role) > 190) {
            flash(t('A line of the scope needs a trade - what the client is asking for.'), 'err');
            redirect('/job#scope');
        }

        $quantity = (int) ($_POST['quantity'] ?? 0);

        if ($quantity < 1 || $quantity > 65535) {
            flash(t('How many of this trade does the agreement ask for?'), 'err');
            redirect('/job#scope');
        }

        $terms = $lineTerms();

        if (isset($terms['error'])) {
            flash(t('Check the :field on that line: the number entered is negative or impossible.',
                    ['field' => t(scope_term_label((string) $terms['error']))]), 'err');
            redirect('/job#scope');
        }

        $discipline = (string) ($_POST['discipline'] ?? 'other');

        if (! array_key_exists($discipline, disciplines())) {
            $discipline = 'other';
        }

        $shift = trim((string) ($_POST['shift'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;

        if ($editing) {
            // A line already being recruited against cannot be cut below the
            // people placed on it: the agreement would then say fewer were
            // agreed than are standing on the site.
            $filled = scope_line_filled((int) $editing['id']);

            if ($quantity < $filled) {
                flash(t(':n are already placed on that line, so it cannot be set below :n.',
                        ['n' => $filled]), 'err');
                redirect('/job#scope');
            }

            q('UPDATE job_order_lines
                  SET role_title = ?, discipline = ?, quantity = ?, shift = ?,
                      pay_rate = ?, bill_rate = ?, per_diem_rate = ?,
                      guarantee_hours = ?, strike_guarantee_hours = ?,
                      overtime_after = ?, overtime_multiplier = ?, notes = ?
                WHERE id = ? AND job_id = ?',
              [$role, $discipline, $quantity, $shift,
               $terms['pay_rate'], $terms['bill_rate'], $terms['per_diem_rate'],
               $terms['guarantee_hours'], $terms['strike_guarantee_hours'],
               $terms['overtime_after'], $terms['overtime_multiplier'], $notes,
               (int) $editing['id'], $jobId]);

            $retotal($jobId);

            log_activity('changed a line of the scope of work', 'job', $jobId,
                         $quantity . ' x ' . $role);

            flash(t(':role updated on the scope.', ['role' => $role]));
            redirect('/job#scope');
        }

        $next = (int) val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM job_order_lines
                           WHERE job_id = ?', [$jobId]);

        q('INSERT INTO job_order_lines
             (job_id, role_title, discipline, quantity, shift, pay_rate, bill_rate,
              per_diem_rate, guarantee_hours, strike_guarantee_hours,
              overtime_after, overtime_multiplier, notes, sort_order)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$jobId, $role, $discipline, $quantity, $shift,
           $terms['pay_rate'], $terms['bill_rate'], $terms['per_diem_rate'],
           $terms['guarantee_hours'], $terms['strike_guarantee_hours'],
           $terms['overtime_after'], $terms['overtime_multiplier'], $notes, $next]);

        $retotal($jobId);

        log_activity('added a line to the scope of work', 'job', $jobId,
                     $quantity . ' x ' . $role);

        flash(t(':n x :role added to the scope of work.', ['n' => $quantity, 'role' => $role]));
        redirect('/job#scope');
    }

    if (($_POST['do'] ?? '') === 'remove_line') {
        $line = scope_line((int) ($_POST['line_id'] ?? 0), $jobId);

        if ($line) {
            // Once orders have been raised against a line, deleting it would
            // leave those requisitions pointing at terms nobody can read.
            $raised = (int) val('SELECT COUNT(*) FROM vacancies WHERE order_line_id = ?',
                                [(int) $line['id']]);

            if ($raised) {
                flash(t(':n requisitions were raised against that line, so it stays on the agreement. Change its quantity to what was really agreed instead.',
                        ['n' => $raised]), 'err');
                redirect('/job#scope');
            }

            q('DELETE FROM job_order_lines WHERE id = ? AND job_id = ?',
              [(int) $line['id'], $jobId]);

            $retotal($jobId);

            log_activity('removed a line from the scope of work', 'job', $jobId,
                         (string) $line['role_title']);

            flash(t(':role removed from the scope.', ['role' => $line['role_title']]));
        }

        redirect('/job#scope');
    }

    // ── the agreement itself ────────────────────────────────────────────
    $start = (string) ($_POST['starts_on'] ?? '');
    $end   = (string) ($_POST['ends_on'] ?? '');

    if (($start && ! valid_date($start)) || ($end && ! valid_date($end))
        || ($start && $end && $end < $start)) {
        refuse(422, t('Invalid project dates.'));
    }

    $description = trim((string) ($_POST['description'] ?? ''));

    if (mb_strlen($description) > 8000) {
        refuse(422, t('The description of the work is too long.'));
    }

    $strike = isset($_POST['strike_live']) ? 1 : 0;
    $was    = (int) $job['strike_live'];

    db()->beginTransaction();
    q('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$jobId]);

    q('UPDATE jobs SET title = ?, description = ?, order_reference = ?,
         site_name = ?, site_city = ?, site_state = ?,
         lodging_provided = ?, travel_provided = ?, transport_provided = ?,
         strike_live = ?, starts_on = ?, ends_on = ?, status = ?, notes = ?
       WHERE id = ?', [
        trim((string) ($_POST['title'] ?? $job['title'])) ?: $job['title'],
        $description !== '' ? $description : null,
        trim((string) ($_POST['order_reference'] ?? '')) ?: null,
        trim((string) ($_POST['site_name'] ?? '')) ?: null,
        trim((string) ($_POST['site_city'] ?? '')) ?: null,
        strtoupper(trim((string) ($_POST['site_state'] ?? ''))) ?: null,
        isset($_POST['lodging_provided'])   ? 1 : 0,
        isset($_POST['travel_provided'])    ? 1 : 0,
        isset($_POST['transport_provided']) ? 1 : 0,
        $strike,
        $start ?: null,
        $end ?: null,
        in_array($_POST['status'] ?? '', ['planning','active','closed'], true)
            ? $_POST['status'] : $job['status'],
        trim((string) ($_POST['notes'] ?? '')) ?: null,
        $jobId,
    ]);

    db()->commit();

    if ($strike !== $was) {
        // The strike switch changes what every line guarantees, so the figure
        // reported is the one the crew will actually be paid against.
        $totals = scope_totals($jobId, (bool) $strike);

        log_activity($strike ? 'declared the strike live' : 'stood the strike down',
                     'job', $jobId,
                     'weekly commitment now ' . money($totals['weekly_pay']));

        flash(t($strike
            ? 'Strike is live. Every line now guarantees its strike hours - a full week of the scope is :amount.'
            : 'Strike stood down. Every line is back to its normal guarantee - a full week of the scope is :amount.',
            ['amount' => money($totals['weekly_pay'])]));
    } else {
        flash(t('Agreement saved.'));
    }

    redirect('/job');
}

// ── where the project stands ─────────────────────────────────────────────
$stage   = $job ? lifecycle_stage($job) : 'planning';
$signals = $job ? lifecycle_signals($job) : [];
$next    = lifecycle_next($stage);

$figures = ['target' => 0, 'placed' => 0, 'onsite' => 0, 'offered' => 0,
            'beds' => 0, 'sharing' => 0, 'travel' => 0, 'blocked' => 0,
            'unapproved' => 0, 'hours' => 0.0, 'weekly_pay' => 0.0,
            'weekly_bill' => 0.0, 'priced' => 0];

$scope  = [];
$totals = ['people' => 0, 'placed' => 0, 'weekly_pay' => 0.0, 'weekly_bill' => 0.0,
           'weekly_margin' => 0.0, 'lines' => 0, 'priced' => 0];

$supervisors = [];

if ($job) {
    $figures = project_figures($job);

    // Two different questions, so two different numbers: what the agreement
    // commits to at full strength, and what the people actually on the job
    // are being paid this week.
    $scope  = scope_lines((int) $job['id']);
    $totals = scope_totals((int) $job['id'], (bool) $job['strike_live']);

    foreach ($scope as $i => $line) {
        $scope[$i]['filled'] = scope_line_filled((int) $line['id']);
        $scope[$i]['raised'] = (int) val('SELECT COUNT(*) FROM vacancies WHERE order_line_id = ?',
                                         [(int) $line['id']]);
    }

    $supervisors = rows('SELECT u.id, u.name, COUNT(*) crew
                         FROM assignment_details d
                         JOIN placements p ON p.id = d.placement_id
                         JOIN users u ON u.id = d.supervisor_id
                         WHERE p.job_id = ? AND p.status NOT IN (\'completed\',\'cancelled\')
                         GROUP BY u.id, u.name ORDER BY crew DESC, u.name', [(int) $job['id']]);
}

// A line can be opened for editing in place, so the form that writes the
// scope is the same form that corrects it.
$editLine = null;

if ($job && isset($_GET['line'])) {
    $editLine = scope_line((int) $_GET['line'], (int) $job['id']);
}

$pageTitle = ($job['title'] ?? t('Project setup')) . ' · ' . $config['app_name'];

render('job', compact('job', 'stage', 'signals', 'next', 'figures',
                      'supervisors', 'scope', 'totals', 'editLine'));

<?php
/**
 * Pay periods: open, submit, approve, lock, reopen; adjustments; the
 * reconciliation of a week across every project; the audit trail.
 *
 * Payroll opens, submits, locks and adjusts. An administrator approves -
 * never the one who submitted - and is the only one who may reopen.
 */

require_once __DIR__ . '/../pay-periods.php';

require_role('payroll');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'open') {
        $week = week_ending((string) ($_POST['week_ending'] ?? ''));

        if (! valid_date((string) ($_POST['week_ending'] ?? ''))) {
            refuse(422, t('Choose the week ending of the period.'));
        }

        if (payroll_run_for($week)) {
            refuse(422, t('A period for the week ending :date already exists.', ['date' => d($week)]));
        }

        q('INSERT INTO payroll_runs (week_ending, opened_by) VALUES (?,?)', [$week, uid()]);
        $id = (int) db()->lastInsertId();
        payroll_run_event($id, 'open', null, uid());
        log_activity('opened a pay period', 'payroll_run', $id, $week);
        redirect('/payroll-runs?id=' . $id);
    }

    $run = payroll_run((int) ($_POST['run_id'] ?? 0));

    if (! $run) {
        refuse(404, t('That pay period does not exist.'));
    }

    if (in_array($do, ['submit', 'approve', 'lock', 'reopen'], true)) {
        if (in_array($do, ['approve', 'reopen'], true)) {
            require_role('admin');
        }

        db()->beginTransaction();
        $locked = row('SELECT * FROM payroll_runs WHERE id = ? FOR UPDATE', [(int) $run['id']]);
        $refusal = payroll_run_move($locked, $do, uid(), (string) ($_POST['reason'] ?? ''));

        if ($refusal !== null) {
            db()->rollBack();
            refuse(422, $refusal);
        }

        db()->commit();
        log_activity('pay period ' . $do, 'payroll_run', (int) $run['id'], (string) $run['week_ending']);
        flash(match ($do) {
            'submit'  => t('Submitted. The week is frozen until an administrator approves or reopens it.'),
            'approve' => t('Approved. Lock it once it has been paid.'),
            'lock'    => t('Locked. It is final; corrections go into an open period as adjustments.'),
            default   => t('Reopened. The week can be changed again.'),
        });
        redirect('/payroll-runs?id=' . (int) $run['id']);
    }

    if ($do === 'adjust') {
        db()->beginTransaction();
        $locked = row('SELECT * FROM payroll_runs WHERE id = ? FOR UPDATE', [(int) $run['id']]);
        $refusal = payroll_adjustment_add($locked, (int) ($_POST['candidate_id'] ?? 0), ((int) ($_POST['timesheet_id'] ?? 0)) ?: null,
                                          (string) ($_POST['kind'] ?? ''), trim((string) ($_POST['amount'] ?? '')),
                                          trim((string) ($_POST['hours'] ?? '')), (string) ($_POST['reason'] ?? ''), uid());

        if ($refusal !== null) {
            db()->rollBack();
            refuse(422, $refusal);
        }

        db()->commit();
        log_activity('recorded a payroll adjustment', 'payroll_run', (int) $run['id'], (string) ($_POST['kind'] ?? ''));
        flash(t('Adjustment recorded in this period.'));
        redirect('/payroll-runs?id=' . (int) $run['id']);
    }

    refuse(422, t('Unknown action.'));
}

$runs = rows('SELECT r.*, (SELECT COUNT(*) FROM payroll_adjustments a WHERE a.run_id = r.id) AS adjustments
              FROM payroll_runs r ORDER BY r.week_ending DESC LIMIT 26');
$run = payroll_run((int) ($_GET['id'] ?? 0)) ?? ($runs[0] ?? null);
$summary = $blockers = $adjustments = $events = $differences = $people = [];

if ($run) {
    $summary = payroll_run_summary($run);
    $blockers = $run['status'] === 'open' ? payroll_run_blockers((string) $run['week_ending']) : [];
    $adjustments = rows('SELECT a.*, c.full_name, t.week_ending AS corrects, u.name AS by_name FROM payroll_adjustments a
                         JOIN candidates c ON c.id = a.candidate_id LEFT JOIN timesheets t ON t.id = a.timesheet_id
                         LEFT JOIN users u ON u.id = a.created_by WHERE a.run_id = ? ORDER BY a.id', [(int) $run['id']]);
    $events = rows('SELECT e.*, u.name FROM payroll_run_events e LEFT JOIN users u ON u.id = e.user_id
                    WHERE e.run_id = ? ORDER BY e.id DESC', [(int) $run['id']]);

    if ($run['status'] === 'open') {
        $differences = array_values(array_filter(payroll_open_differences(),
            fn($d) => (int) $d['adjusted'] === 0 && $d['week_ending'] < $run['week_ending']));
        $people = rows("SELECT DISTINCT c.id, c.full_name FROM candidates c JOIN placements p ON p.candidate_id = c.id
                        JOIN timesheets t ON t.placement_id = p.id
                        WHERE t.status IN ('approved','paid') AND t.week_ending >= DATE_SUB(?, INTERVAL 120 DAY)
                        ORDER BY c.full_name", [(string) $run['week_ending']]);
    }
}

$statuses = payroll_run_statuses();
$kinds = payroll_adjustment_kinds();

$pageTitle = t('Pay periods') . ' · ' . $config['app_name'];
render('payroll-runs', compact('runs', 'run', 'summary', 'blockers', 'adjustments', 'events', 'differences', 'people', 'statuses', 'kinds'));

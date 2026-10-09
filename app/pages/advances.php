<?php
/**
 * Advances against wages.
 *
 * Carried over from the HR system's loan and salary-advance modules.
 * Real for this workforce: somebody flies into Gary on Monday and needs
 * money before the first cheque clears on Friday week.
 *
 * Two rules the HR system did not have, and should have:
 *
 *   - What somebody already owes is shown before another advance is
 *     agreed. Three small advances nobody added up is how a person ends
 *     up owing more than a week's wages.
 *   - A repayment cannot exceed the balance. The HR system stored the
 *     amount as text and happily recorded an overpayment, leaving a
 *     negative debt nobody could explain.
 */

require_role('payroll', 'recruiter');
require_once __DIR__ . '/../hr.php';

$job   = current_job();
$jobId = (int) ($job['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');

    // ── asking for one ──────────────────────────────────────────────
    if ($do === 'request') {
        $candidateId = (int) ($_POST['candidate_id'] ?? 0);
        $person = row('SELECT id, full_name FROM candidates WHERE id = ?', [$candidateId]);

        if (! $person) {
            flash(t('Choose who the advance is for.'), 'err');
            redirect('/advances');
        }

        $amount = trim((string) ($_POST['amount'] ?? ''));
        $weekly = trim((string) ($_POST['weekly_repayment'] ?? ''));

        foreach (['amount' => $amount, 'weekly_repayment' => $weekly] as $what => $given) {
            if ($given === '' || ! is_numeric($given) || ! is_finite((float) $given)
                || (float) $given <= 0 || (float) $given > 100000) {
                flash(t('An advance needs an amount and a weekly repayment, both above zero.'), 'err');
                redirect('/advances');
            }
        }

        if ((float) $weekly > (float) $amount) {
            flash(t('The weekly repayment cannot be more than the advance itself.'), 'err');
            redirect('/advances');
        }

        q('INSERT INTO wage_advances (candidate_id, job_id, amount, weekly_repayment,
                                      reason, status, requested_by)
           VALUES (?,?,?,?,?,?,?)',
          [$candidateId, $jobId ?: null, (float) $amount, (float) $weekly,
           trim((string) ($_POST['reason'] ?? '')) ?: null, 'requested', uid()]);

        log_activity('requested a wage advance', 'candidate', $candidateId,
                     $person['full_name'] . ': ' . money((float) $amount));

        flash(t('Advance of :amount recorded for :name, waiting on approval.',
                ['amount' => money((float) $amount), 'name' => $person['full_name']]));

        redirect('/advances');
    }

    $advance = row('SELECT a.*, c.full_name FROM wage_advances a
                    JOIN candidates c ON c.id = a.candidate_id
                    WHERE a.id = ?', [(int) ($_POST['advance_id'] ?? 0)]);

    if (! $advance) {
        flash(t('That advance no longer exists.'), 'err');
        redirect('/advances');
    }

    // ── agreeing to it ──────────────────────────────────────────────
    if ($do === 'approve' || $do === 'cancel') {
        require_role('payroll');

        if (! in_array($advance['status'], ['requested', 'approved'], true)) {
            flash(t('That advance has already been paid out or cleared.'), 'err');
            redirect('/advances');
        }

        $approve = $do === 'approve';

        q('UPDATE wage_advances SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?',
          [$approve ? 'approved' : 'cancelled',
           $approve ? uid() : null,
           $approve ? date('Y-m-d H:i:s') : null,
           (int) $advance['id']]);

        log_activity($approve ? 'approved a wage advance' : 'cancelled a wage advance',
                     'candidate', (int) $advance['candidate_id'],
                     $advance['full_name'] . ': ' . money((float) $advance['amount']));

        flash($approve
            ? t('Approved. Mark it paid out once the money is with them.')
            : t('Advance cancelled.'));

        redirect('/advances');
    }

    // ── handing the money over ──────────────────────────────────────
    if ($do === 'pay_out') {
        require_role('payroll');

        if ($advance['status'] !== 'approved') {
            flash(t('Only an approved advance can be paid out.'), 'err');
            redirect('/advances');
        }

        q("UPDATE wage_advances SET status = 'paid_out', paid_out_on = CURDATE() WHERE id = ?",
          [(int) $advance['id']]);

        log_activity('paid out a wage advance', 'candidate', (int) $advance['candidate_id'],
                     $advance['full_name'] . ': ' . money((float) $advance['amount']));

        flash(t(':name has been paid :amount. Repayment starts from the next week recorded.',
                ['name' => $advance['full_name'], 'amount' => money((float) $advance['amount'])]));

        redirect('/advances');
    }

    // ── taking some back ────────────────────────────────────────────
    if ($do === 'repay') {
        require_role('payroll');

        if ($advance['status'] !== 'paid_out') {
            flash(t('Repayments can only be recorded against an advance that was paid out.'), 'err');
            redirect('/advances');
        }

        $amount = trim((string) ($_POST['amount'] ?? ''));

        if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            flash(t('Enter how much was taken back.'), 'err');
            redirect('/advances');
        }

        $owed = advance_balance($advance);

        // The HR system stored this as text and accepted anything, which
        // left people owing negative money.
        if ((float) $amount > $owed + 0.001) {
            flash(t('That is more than the :owed still owed on this advance.',
                    ['owed' => money($owed)]), 'err');
            redirect('/advances');
        }

        $paidOn = (string) ($_POST['paid_on'] ?? '');

        if ($paidOn !== '' && ! valid_date($paidOn)) {
            flash(t('Check the date of the repayment.'), 'err');
            redirect('/advances');
        }

        db()->beginTransaction();

        q('INSERT INTO wage_advance_payments (advance_id, amount, paid_on, note, recorded_by)
           VALUES (?,?,?,?,?)',
          [(int) $advance['id'], (float) $amount, $paidOn ?: date('Y-m-d'),
           trim((string) ($_POST['note'] ?? '')) ?: null, uid()]);

        // Cleared is derived from the payments, not typed by anybody.
        $remaining = advance_balance($advance) - (float) $amount;

        if ($remaining <= 0.001) {
            q("UPDATE wage_advances SET status = 'cleared' WHERE id = ?", [(int) $advance['id']]);
        }

        db()->commit();

        log_activity('recorded a repayment', 'candidate', (int) $advance['candidate_id'],
                     $advance['full_name'] . ': ' . money((float) $amount));

        flash($remaining <= 0.001
            ? t(':name has repaid this advance in full.', ['name' => $advance['full_name']])
            : t(':amount recorded. :owed still owed.',
                ['amount' => money((float) $amount), 'owed' => money($remaining)]));

        redirect('/advances');
    }

    redirect('/advances');
}

// ── what is outstanding ─────────────────────────────────────────────────
$advances = rows('SELECT a.*, c.full_name, c.id AS candidate_id,
                         j.title AS project,
                         req.name AS requested_by_name,
                         app.name AS approved_by_name
                  FROM wage_advances a
                  JOIN candidates c ON c.id = a.candidate_id
                  LEFT JOIN jobs j ON j.id = a.job_id
                  LEFT JOIN users req ON req.id = a.requested_by
                  LEFT JOIN users app ON app.id = a.approved_by
                  ORDER BY FIELD(a.status,\'requested\',\'approved\',\'paid_out\',\'cleared\',\'cancelled\'),
                           a.id DESC
                  LIMIT 300');

foreach ($advances as $i => $advance) {
    $advances[$i]['balance'] = advance_balance($advance);
    $advances[$i]['payments'] = rows('SELECT p.*, u.name AS who
                                      FROM wage_advance_payments p
                                      LEFT JOIN users u ON u.id = p.recorded_by
                                      WHERE p.advance_id = ? ORDER BY p.paid_on, p.id',
                                     [(int) $advance['id']]);
}

$outstanding = 0.0;

foreach ($advances as $advance) {
    if ($advance['status'] === 'paid_out') {
        $outstanding += (float) $advance['balance'];
    }
}

// Everybody who could be given one, with what they already owe beside
// their name: the number that should be read before agreeing another.
$people = rows("SELECT DISTINCT c.id, c.full_name
                FROM candidates c
                JOIN placements p ON p.candidate_id = c.id
                WHERE p.status IN ('offered','confirmed','travelling','on_site')
                ORDER BY c.full_name");

foreach ($people as $i => $person) {
    $people[$i]['owed'] = advance_owed((int) $person['id']);
}

$pageTitle = t('Advances') . ' · ' . $config['app_name'];

render('advances', compact('advances', 'people', 'outstanding', 'job'));

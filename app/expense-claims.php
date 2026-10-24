<?php
/**
 * P5-M110: what a worker spent for the job, and how it is paid back.
 *
 * The worker files a claim with its receipt; somebody else in payroll approves it; it is paid ONE way:
 *   - on the worker's next pay: a payroll adjustment of kind "reimbursement" in an open pay period, tied to the claim
 *     (payroll_adjustments.expense_claim_id, unique), on their pay statement, outside taxable wages;
 *   - or separately: direct deposit, check or cash, with its reference.
 * The database refuses a paid claim with neither, or both (triggers expense_claims_paid_one_way,
 * payroll_adjustments_claim_approved), so a claim is never paid twice and never left "paid" without a trace.
 */

declare(strict_types=1);

require_once __DIR__ . '/pay-periods.php';

function expense_categories(): array
{
    return ['flight' => t('Flight'), 'baggage' => t('Baggage'), 'hotel' => t('Hotel'), 'transport' => t('Ground transport'),
            'screening' => t('Screening'), 'other' => t('Other')];
}

function expense_statuses(): array
{
    return ['submitted' => t('Waiting for review'), 'approved' => t('Approved, to pay'), 'rejected' => t('Not approved'), 'paid' => t('Paid')];
}

function expense_methods(): array
{
    return ['payroll' => t('On the pay'), 'direct_deposit' => t('Direct deposit'), 'check' => t('Check'), 'cash' => t('Cash')];
}

/** Open pay periods a claim can be paid in, earliest first. */
function expense_open_runs(): array
{
    return rows("SELECT id, week_ending FROM payroll_runs WHERE status = 'open' ORDER BY week_ending");
}

/** Approved claims not paid yet, on every project, for the pay period page. */
function expense_claims_to_pay(): array
{
    return rows("SELECT e.id, e.category, e.amount, e.reviewed_at, p.candidate_id, c.full_name, j.title AS project
                 FROM expense_claims e JOIN placements p ON p.id = e.placement_id JOIN candidates c ON c.id = p.candidate_id
                 JOIN jobs j ON j.id = p.job_id WHERE e.status = 'approved' ORDER BY e.reviewed_at, e.id");
}

/**
 * Pay an approved claim on the worker's next pay: one reimbursement adjustment in the open period, and the claim is
 * paid, in one transaction. Returns the refusal, or null.
 */
function expense_pay_in_run(int $runId, int $claimId, int $by): ?string
{
    require_once __DIR__ . '/periods.php';
    db()->beginTransaction();
    $run = row('SELECT * FROM payroll_runs WHERE id = ? FOR UPDATE', [$runId]);
    $claim = row('SELECT e.*, p.candidate_id FROM expense_claims e JOIN placements p ON p.id = e.placement_id WHERE e.id = ? FOR UPDATE', [$claimId]);
    $why = null;
    if (! $run) { $why = t('That pay period does not exist.'); }
    elseif ($run['status'] !== 'open') { $why = t('Adjustments go into an open period. This one is :status.', ['status' => payroll_run_statuses()[$run['status']] ?? $run['status']]); }
    elseif (! $claim || $claim['status'] !== 'approved') { $why = t('Only an approved reimbursement is paid, and only once.'); }
    elseif ($guard = period_guard((string) $run['week_ending'])) { $why = $guard; }
    if ($why !== null) { db()->rollBack(); return $why; }

    $label = expense_categories()[$claim['category']] ?? $claim['category'];
    $reason = mb_substr(t('Reimbursement: :what (claim #:id)', ['what' => $label, 'id' => $claimId]), 0, 500);
    q("INSERT INTO payroll_adjustments (run_id, candidate_id, timesheet_id, kind, amount, hours, reason, created_by, expense_claim_id)
       VALUES (?,?,NULL,'reimbursement',?,NULL,?,?,?)", [$runId, (int) $claim['candidate_id'], round((float) $claim['amount'], 2), $reason, $by, $claimId]);
    $adjustment = (int) db()->lastInsertId();
    payroll_run_event($runId, 'adjustment', 'reimbursement ' . $claim['amount'] . ' for #' . $claim['candidate_id'] . ': ' . $reason, $by);
    q("UPDATE expense_claims SET status = 'paid', payment_method = 'payroll', payment_reference = ?, paid_at = NOW() WHERE id = ? AND status = 'approved'",
      ['adjustment #' . $adjustment . ', week ending ' . $run['week_ending'], $claimId]);
    db()->commit();

    require_once __DIR__ . '/notices.php';
    notify_user((int) $claim['user_id'], t('Your reimbursement of :amount (:what) is paid with the week ending :date.',
        ['amount' => money($claim['amount']), 'what' => $label, 'date' => d((string) $run['week_ending'])]), '/expenses#claim-' . $claimId);
    log_activity('paid a reimbursement on the pay', 'payroll_run', $runId, 'claim #' . $claimId);

    return null;
}

/** Pay an approved claim separately (direct deposit, check, cash), with its reference. Returns the refusal, or null. */
function expense_pay_separately(int $claimId, string $method, string $reference): ?string
{
    require_once __DIR__ . '/periods.php';
    if ($why = period_guard(date('Y-m-d'))) { return $why; }
    if (! in_array($method, ['direct_deposit', 'check', 'cash'], true)) { return t('Choose how it was paid.'); }
    $reference = trim($reference);
    if (mb_strlen($reference) < 2 || mb_strlen($reference) > 190) { return t('Give the payment reference: the deposit, check or receipt number.'); }
    $done = q("UPDATE expense_claims SET status = 'paid', payment_method = ?, payment_reference = ?, paid_at = NOW()
               WHERE id = ? AND status = 'approved'", [$method, $reference, $claimId])->rowCount();

    return $done === 1 ? null : t('Only an approved reimbursement is paid, and only once.');
}

/** Paid claims that break the one-way rule (from before the database guarded it): for System health. */
function expense_claims_inconsistent(): int
{
    try {
        return (int) val("SELECT COUNT(*) FROM expense_claims e LEFT JOIN payroll_adjustments a ON a.expense_claim_id = e.id
                          WHERE (e.status = 'paid' AND (e.payment_method IS NULL
                                 OR (e.payment_method = 'payroll' AND a.id IS NULL)
                                 OR (e.payment_method <> 'payroll' AND (a.id IS NOT NULL OR COALESCE(TRIM(e.payment_reference), '') = ''))))
                             OR (e.status <> 'paid' AND a.id IS NOT NULL)");
    } catch (Throwable $e) {
        return 0;
    }
}

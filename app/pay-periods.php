<?php
/**
 * Pay periods: one per week ending, across every project.
 *
 *   open       weeks are approved project by project, as before
 *   submitted  payroll says the week is complete; nothing in it changes
 *   approved   an administrator who did not submit it agrees
 *   locked     paid and final
 *
 * A week with no period behaves as it always did. From submission on, the
 * week's hours, imports, approvals, attendance corrections and staff-
 * entered days are refused. What is found wrong afterwards is an
 * adjustment, paid in a period still open, with the week it corrects and
 * the reason. Every step is written to payroll_run_events.
 */

declare(strict_types=1);

require_once __DIR__ . '/gross-to-net.php';

function payroll_run_statuses(): array
{
    return ['open' => t('Open'), 'submitted' => t('Submitted for approval'), 'approved' => t('Approved'), 'locked' => t('Locked')];
}

function payroll_adjustment_kinds(): array
{
    return ['correction' => t('Correction of a paid week'), 'back_pay' => t('Back pay'),
            'reimbursement' => t('Reimbursement'), 'recovery' => t('Recovery of an overpayment')];
}

function payroll_run(int $id): ?array
{
    return $id ? (row('SELECT * FROM payroll_runs WHERE id = ?', [$id]) ?: null) : null;
}

function payroll_run_for(string $weekEnding): ?array
{
    return row('SELECT * FROM payroll_runs WHERE week_ending = ?', [$weekEnding]) ?: null;
}

/** The week is past "open": nothing in it may change any more. */
function payroll_week_locked(string $weekEnding): bool
{
    try {
        return (bool) val("SELECT COUNT(*) FROM payroll_runs WHERE week_ending = ? AND status <> 'open'", [$weekEnding]);
    } catch (Throwable $e) {
        return false;
    }
}

function payroll_run_event(int $runId, string $event, ?string $detail, ?int $userId): void
{
    q('INSERT INTO payroll_run_events (run_id, event, detail, user_id) VALUES (?,?,?,?)',
      [$runId, $event, $detail !== null ? mb_substr($detail, 0, 1000) : null, $userId]);
}

/** Sheets in the week, on any project, that still need a decision. */
function payroll_run_blockers(string $weekEnding): array
{
    return rows("SELECT t.id, t.placement_id, c.full_name, j.title AS project, t.hours_worked, t.paid_leave_hours
                 FROM timesheets t JOIN placements p ON p.id = t.placement_id
                 JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                 WHERE t.week_ending = ? AND t.status IN ('draft','submitted')
                   AND (t.hours_worked > 0 OR t.paid_leave_hours > 0)
                 ORDER BY j.title, c.full_name", [$weekEnding]);
}

/**
 * Move a period. Returns the refusal, or null. The caller checks the role:
 * payroll opens, submits and locks; an administrator approves and reopens.
 */
function payroll_run_move(array $run, string $action, int $userId, string $reason = ''): ?string
{
    $status = (string) $run['status'];
    $id = (int) $run['id'];

    switch ($action) {
        case 'submit':
            if ($status !== 'open') {
                return t('Only an open period is submitted.');
            }
            if ($blocked = payroll_run_blockers((string) $run['week_ending'])) {
                return t(':n weekly sheet(s) in this week are not approved yet. Approve them on Hours, or set their hours to zero, first.', ['n' => count($blocked)]);
            }
            q("UPDATE payroll_runs SET status='submitted', submitted_by=?, submitted_at=NOW() WHERE id=? AND status='open'", [$userId, $id]);
            break;

        case 'approve':
            if ($status !== 'submitted') {
                return t('Only a submitted period is approved.');
            }
            if ((int) $run['submitted_by'] === $userId) {
                return t('The person who submitted a period does not approve it. Another administrator does.');
            }
            q("UPDATE payroll_runs SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='submitted'", [$userId, $id]);
            break;

        case 'lock':
            if ($status !== 'approved') {
                return t('Only an approved period is locked.');
            }
            q("UPDATE payroll_runs SET status='locked', locked_by=?, locked_at=NOW() WHERE id=? AND status='approved'", [$userId, $id]);
            break;

        case 'reopen':
            if (! in_array($status, ['submitted', 'approved'], true)) {
                return $status === 'locked' ? t('A locked period is final. Correct it with an adjustment in an open period.') : t('That period is already open.');
            }
            if (mb_strlen(trim($reason)) < 3) {
                return t('Say why the period is reopened.');
            }
            q("UPDATE payroll_runs SET status='open', submitted_by=NULL, submitted_at=NULL, approved_by=NULL, approved_at=NULL WHERE id=?", [$id]);
            break;

        default:
            return t('Unknown action.');
    }

    payroll_run_event($id, $action, trim($reason) !== '' ? trim($reason) : null, $userId);

    return null;
}

/**
 * An adjustment in an open period. Returns the refusal, or null.
 * The amount is entered positive; a recovery is taken back, so it is
 * stored negative.
 */
function payroll_adjustment_add(array $run, int $candidateId, ?int $timesheetId, string $kind, string $amount,
                                string $hours, string $reason, int $userId): ?string
{
    if ($run['status'] !== 'open') {
        return t('Adjustments go into an open period. This one is :status.', ['status' => payroll_run_statuses()[$run['status']] ?? $run['status']]);
    }

    if (! array_key_exists($kind, payroll_adjustment_kinds())) {
        return t('Choose what kind of adjustment this is.');
    }

    if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 100000) {
        return t('An adjustment is an amount above 0 and at most 100,000; a recovery is entered positive and taken back.');
    }

    if ($hours !== '' && (! is_numeric($hours) || abs((float) $hours) > 168)) {
        return t('Hours, if given, are a number up to 168.');
    }

    $reason = trim($reason);

    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        return t('Say why the adjustment is made, in up to 500 characters.');
    }

    if (! val('SELECT COUNT(*) FROM candidates WHERE id = ?', [$candidateId])) {
        return t('Choose who the adjustment is for.');
    }

    if ($timesheetId) {
        $sheet = row("SELECT t.* FROM timesheets t JOIN placements p ON p.id = t.placement_id
                      WHERE t.id = ? AND p.candidate_id = ?", [$timesheetId, $candidateId]);

        if (! $sheet || ! in_array($sheet['status'], ['approved', 'paid'], true)) {
            return t('An adjustment corrects a week already approved for this person.');
        }

        if ($sheet['week_ending'] >= $run['week_ending']) {
            return t('An adjustment is paid in a later period than the week it corrects.');
        }
    }

    q('INSERT INTO payroll_adjustments (run_id, candidate_id, timesheet_id, kind, amount, hours, reason, created_by)
       VALUES (?,?,?,?,?,?,?,?)',
      [(int) $run['id'], $candidateId, $timesheetId ?: null, $kind,
       round((float) $amount, 2) * ($kind === 'recovery' ? -1 : 1), $hours !== '' ? round((float) $hours, 2) : null, $reason, $userId]);

    payroll_run_event((int) $run['id'], 'adjustment', $kind . ' ' . $amount . ' for #' . $candidateId . ': ' . $reason, $userId);

    return null;
}

/** One frozen sheet's figures, whatever module froze it. */
function payroll_sheet_figures(array $snapshot): array
{
    $g = $snapshot['gross_to_net'] ?? null;
    $gross = $g['gross_wages'] ?? round((float) ($snapshot['labour_cost'] ?? 0) + (float) ($snapshot['leave_pay'] ?? 0), 2);

    return [
        'gross'      => (float) $gross,
        'deductions' => (float) ($g['total_deductions'] ?? 0),
        'employer'   => (float) ($g['total_employer'] ?? 0),
        'reimbursed' => (float) ($g['reimbursements'] ?? ((float) ($snapshot['per_diem'] ?? 0) + (float) ($snapshot['expenses'] ?? 0))),
        'net'        => (float) ($g['net_before_tax'] ?? ($gross + (float) ($snapshot['per_diem'] ?? 0) + (float) ($snapshot['expenses'] ?? 0))),
        'recorded'   => $g !== null,
    ];
}

/**
 * The period, person by person: every approved sheet in the week, its
 * frozen figures, the adjustments paid in it, and whether it was paid.
 */
function payroll_run_summary(array $run): array
{
    $people = [];

    foreach (rows("SELECT t.id, t.status, t.placement_id, p.candidate_id, c.full_name, j.title AS project, s.result_json,
                          (SELECT COUNT(*) FROM payroll_payments pp WHERE pp.timesheet_id = t.id) AS paid
                   FROM timesheets t JOIN placements p ON p.id = t.placement_id
                   JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                   JOIN pay_snapshots s ON s.timesheet_id = t.id
                   WHERE t.week_ending = ? AND t.status IN ('approved','paid')
                   ORDER BY c.full_name, j.title", [(string) $run['week_ending']]) as $r) {
        $cid = (int) $r['candidate_id'];
        $people[$cid] ??= ['candidate_id' => $cid, 'full_name' => $r['full_name'], 'projects' => [], 'gross' => 0.0,
                           'deductions' => 0.0, 'employer' => 0.0, 'reimbursed' => 0.0, 'net' => 0.0,
                           'adjustments' => 0.0, 'sheets' => 0, 'paid_sheets' => 0];
        $f = payroll_sheet_figures(json_decode((string) $r['result_json'], true) ?: []);
        $p = &$people[$cid];
        $p['projects'][] = $r['project'];
        foreach (['gross', 'deductions', 'employer', 'reimbursed', 'net'] as $k) { $p[$k] = round($p[$k] + $f[$k], 2); }
        $p['sheets']++;
        $p['paid_sheets'] += (int) $r['paid'] > 0 || $r['status'] === 'paid' ? 1 : 0;
        unset($p);
    }

    foreach (rows('SELECT a.candidate_id, c.full_name, SUM(a.amount) AS total FROM payroll_adjustments a
                   JOIN candidates c ON c.id = a.candidate_id WHERE a.run_id = ? GROUP BY a.candidate_id, c.full_name',
                  [(int) $run['id']]) as $a) {
        $cid = (int) $a['candidate_id'];
        $people[$cid] ??= ['candidate_id' => $cid, 'full_name' => $a['full_name'], 'projects' => [], 'gross' => 0.0,
                           'deductions' => 0.0, 'employer' => 0.0, 'reimbursed' => 0.0, 'net' => 0.0,
                           'adjustments' => 0.0, 'sheets' => 0, 'paid_sheets' => 0];
        $people[$cid]['adjustments'] = round((float) $a['total'], 2);
    }

    foreach ($people as &$p) {
        $p['payable'] = round($p['net'] + $p['adjustments'], 2);
    }
    unset($p);

    $totals = ['gross' => 0.0, 'deductions' => 0.0, 'employer' => 0.0, 'reimbursed' => 0.0, 'net' => 0.0, 'adjustments' => 0.0, 'payable' => 0.0];
    foreach ($people as $p) { foreach ($totals as $k => $_) { $totals[$k] = round($totals[$k] + $p[$k], 2); } }

    return ['people' => array_values($people), 'totals' => $totals];
}

/**
 * Frozen weeks whose approved days no longer match the sheet - found by
 * the week check (P1-M02), settled here with an adjustment.
 */
function payroll_open_differences(): array
{
    return rows("SELECT t.id AS timesheet_id, t.week_ending, t.hours_worked, p.candidate_id, c.full_name, j.title AS project,
                        COALESCE(p.pay_rate, j.pay_rate) AS pay_rate,
                        (SELECT COALESCE(SUM(a.hours),0) FROM attendance_records a WHERE a.placement_id = t.placement_id
                          AND a.status = 'approved' AND a.work_date BETWEEN DATE_SUB(t.week_ending, INTERVAL 6 DAY) AND t.week_ending) AS approved_hours,
                        (SELECT COUNT(*) FROM payroll_adjustments x WHERE x.timesheet_id = t.id) AS adjusted
                 FROM timesheets t JOIN placements p ON p.id = t.placement_id
                 JOIN candidates c ON c.id = p.candidate_id JOIN jobs j ON j.id = p.job_id
                 JOIN attendance_payroll_sources src ON src.timesheet_id = t.id
                 WHERE t.status IN ('approved','paid')
                 HAVING ABS(approved_hours - t.hours_worked) >= 0.005
                 ORDER BY t.week_ending DESC, c.full_name LIMIT 100");
}

/** What a payslip shows for one person in one period. */
function payslip_data(array $run, int $candidateId): ?array
{
    $person = row('SELECT c.id, c.full_name, c.email, e.employee_number, e.adp_employee_id, e.employment_type
                   FROM candidates c LEFT JOIN employee_profiles e ON e.candidate_id = c.id WHERE c.id = ?', [$candidateId]);

    if (! $person) {
        return null;
    }

    $lines = [];
    foreach (rows("SELECT t.id, j.title AS project, s.result_json FROM timesheets t
                   JOIN placements p ON p.id = t.placement_id JOIN jobs j ON j.id = p.job_id
                   JOIN pay_snapshots s ON s.timesheet_id = t.id
                   WHERE p.candidate_id = ? AND t.week_ending = ? AND t.status IN ('approved','paid') ORDER BY j.title",
                  [$candidateId, (string) $run['week_ending']]) as $r) {
        $snap = json_decode((string) $r['result_json'], true) ?: [];
        $lines[] = ['project' => $r['project'], 'snap' => $snap, 'figures' => payroll_sheet_figures($snap)];
    }

    $adjustments = rows('SELECT a.*, t.week_ending AS corrects FROM payroll_adjustments a
                         LEFT JOIN timesheets t ON t.id = a.timesheet_id
                         WHERE a.run_id = ? AND a.candidate_id = ? ORDER BY a.id', [(int) $run['id'], $candidateId]);

    if (! $lines && ! $adjustments) {
        return null;
    }

    $sum = static fn(string $k) => round(array_sum(array_map(fn($l) => $l['figures'][$k], $lines)), 2);
    $adjusted = round(array_sum(array_column($adjustments, 'amount')), 2);

    return ['person' => $person, 'lines' => $lines, 'adjustments' => $adjustments,
            'gross' => $sum('gross'), 'deductions' => $sum('deductions'), 'reimbursed' => $sum('reimbursed'),
            'net' => $sum('net'), 'adjusted' => $adjusted, 'payable' => round($sum('net') + $adjusted, 2)];
}

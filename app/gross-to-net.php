<?php
/**
 * Gross to net for a weekly sheet: which deductions and contributions
 * apply to the person that week, and - when the week is approved -
 * recording the advance repayments it took.
 *
 * One person, one set of deductions a week. Somebody on two assignments
 * in the same week has two weekly sheets; their deductions are taken on
 * the first one approved, and the other says so rather than taking them
 * again.
 */

declare(strict_types=1);

require_once __DIR__ . '/gross-to-net-calculation.php';
require_once __DIR__ . '/hr.php';

function pay_item_sides(): array
{
    return ['deduction' => t('Deduction from pay'), 'employer_contribution' => t('Employer contribution')];
}

function pay_item_methods(): array
{
    return ['fixed' => t('A fixed amount a week'), 'percent_of_gross' => t('A percentage of gross wages'),
            'advance_repayment' => t('Open advances, at their agreed weekly repayment')];
}

function pay_items(bool $activeOnly = true): array
{
    return rows('SELECT * FROM pay_items' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY side, sort_order, label');
}

/** A person's pay items, with the item's description. */
function employee_pay_items(int $candidateId): array
{
    return rows('SELECT e.*, i.code, i.label, i.side, i.method, i.pre_tax, i.provider_code, i.is_active AS item_active
                 FROM employee_pay_items e JOIN pay_items i ON i.id = e.pay_item_id
                 WHERE e.candidate_id = ? ORDER BY e.ends_on IS NOT NULL, e.starts_on DESC, e.id DESC', [$candidateId]);
}

/**
 * Gross to net for one sheet, not yet frozen. $money is week_money()'s
 * result for the sheet.
 */
function gross_to_net_for_sheet(array $sheet, array $money): array
{
    $placementId = (int) ($sheet['placement_id'] ?? 0);
    $weekEnding = (string) ($sheet['week_ending'] ?? '');
    $candidateId = (int) ($sheet['candidate_id'] ?? val('SELECT candidate_id FROM placements WHERE id = ?', [$placementId]));
    $start = date('Y-m-d', strtotime($weekEnding . ' -6 days'));
    $gross = round((float) ($money['labour_cost'] ?? 0) + (float) ($money['leave_pay'] ?? 0), 2);
    $perDiem = (float) ($money['per_diem'] ?? 0);
    $expenses = (float) ($money['expenses'] ?? 0);

    // Already taken on another of this person's sheets this week?
    $takenElsewhere = false;

    foreach (rows("SELECT s.result_json FROM timesheets t JOIN placements p ON p.id = t.placement_id
                   JOIN pay_snapshots s ON s.timesheet_id = t.id
                   WHERE p.candidate_id = ? AND t.week_ending = ? AND t.placement_id <> ?",
                  [$candidateId, $weekEnding, $placementId]) as $other) {
        $g = json_decode((string) $other['result_json'], true)['gross_to_net'] ?? null;

        if ($g && ! empty($g['carries_deductions'])) {
            $takenElsewhere = true;
        }
    }

    $items = [];
    $advances = [];
    $advanceItem = null;

    if (! $takenElsewhere) {
        foreach (rows("SELECT e.amount, i.code, i.label, i.side, i.method, i.pre_tax, i.provider_code
                       FROM employee_pay_items e JOIN pay_items i ON i.id = e.pay_item_id
                       WHERE e.candidate_id = ? AND i.is_active = 1 AND i.method <> 'advance_repayment'
                         AND e.starts_on <= ? AND (e.ends_on IS NULL OR e.ends_on >= ?)
                       ORDER BY i.sort_order, i.id, e.id", [$candidateId, $weekEnding, $start]) as $i) {
            $items[] = $i + ['amount' => (float) $i['amount']];
        }

        $advanceItem = row("SELECT code, label, provider_code FROM pay_items
                            WHERE method = 'advance_repayment' AND is_active = 1 ORDER BY id LIMIT 1") ?: null;

        if ($advanceItem) {
            foreach (rows("SELECT * FROM wage_advances WHERE candidate_id = ? AND status = 'paid_out'
                           AND (paid_out_on IS NULL OR paid_out_on <= ?) ORDER BY id", [$candidateId, $weekEnding]) as $a) {
                $balance = advance_balance($a);

                if ($balance > 0) {
                    $advances[] = ['id' => (int) $a['id'], 'balance' => $balance, 'weekly' => (float) $a['weekly_repayment']];
                }
            }
        }
    }

    $result = gross_to_net($gross, $perDiem, $expenses, $items, $advances, $advanceItem);
    $result['taken_on_other_sheet'] = $takenElsewhere;
    $result['carries_deductions'] = ! $takenElsewhere;

    return $result;
}

/**
 * At approval: record the advance repayments the week took, so the
 * balance owed falls with the week rather than when somebody remembers.
 * The caller owns the transaction.
 */
function gross_to_net_record_advances(int $timesheetId, string $weekEnding, array $grossToNet, int $userId): void
{
    foreach ($grossToNet['deductions'] ?? [] as $d) {
        if (empty($d['advance_id'])) {
            continue;
        }

        q('INSERT INTO wage_advance_payments (advance_id, timesheet_id, amount, paid_on, note, recorded_by)
           VALUES (?,?,?,?,?,?)',
          [(int) $d['advance_id'], $timesheetId, (float) $d['amount'], $weekEnding,
           'Deducted from the week ending ' . $weekEnding, $userId]);

        $advance = row('SELECT * FROM wage_advances WHERE id = ?', [(int) $d['advance_id']]);

        if ($advance && advance_balance($advance) <= 0) {
            q("UPDATE wage_advances SET status = 'cleared' WHERE id = ? AND status = 'paid_out'", [(int) $d['advance_id']]);
        }
    }
}

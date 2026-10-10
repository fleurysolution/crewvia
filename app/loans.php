<?php
/**
 * Loans and wage advances (P2-M05), on top of the advances payroll already
 * repays week by week (P1-M05: gross to net takes the agreed weekly amount,
 * never more than the balance, and records it at approval).
 *
 *   - an advance is against the next weeks' wages; a loan is larger and
 *     longer. Both repay the same way: so much a week from a first week.
 *   - the schedule: from the first week, the weekly amount until the
 *     amount is covered, skipping paused weeks. What payroll actually took
 *     is set beside it, and an advance that has taken less than the
 *     schedule expected by now is behind.
 *   - approval: never by whoever asked; above the limit, or when it takes
 *     what the person owes above it, only by an administrator.
 *   - a pause stops repayment for a window of weeks, with a reason: payroll
 *     takes nothing and the schedule expects nothing.
 *   - a write-off clears what is left, with a reason, administrators only:
 *     somebody left owing, and it will not be recovered.
 */

declare(strict_types=1);

require_once __DIR__ . '/hr.php';

function loan_kinds(): array
{
    return ['advance' => t('Wage advance'), 'loan' => t('Loan')];
}

function advance_admin_above(): float
{
    $v = val("SELECT setting_value FROM platform_settings WHERE setting_key = 'advance_admin_above'");

    return is_numeric($v) ? (float) $v : 1000.0;
}

function advance_event(int $advanceId, string $event, ?string $detail = null): void
{
    q('INSERT INTO advance_events (advance_id, event, detail, user_id) VALUES (?,?,?,?)', [$advanceId, $event, $detail !== null ? mb_substr($detail, 0, 500) : null, uid() ?: null]);
}

/** The first week repayment is taken: the one set, or the week it was paid out. */
function advance_first_week(array $a): ?string
{
    if (! empty($a['first_week'])) {
        return (string) $a['first_week'];
    }

    return ! empty($a['paid_out_on']) ? week_ending((string) $a['paid_out_on']) : null;
}

function advance_pauses(int $advanceId): array
{
    return rows('SELECT p.*, u.name AS by_name FROM advance_pauses p LEFT JOIN users u ON u.id = p.user_id WHERE p.advance_id = ? ORDER BY p.from_week', [$advanceId]);
}

function advance_paused_on(array $pauses, string $week): ?array
{
    foreach ($pauses as $p) {
        if ($week >= $p['from_week'] && $week <= $p['until_week']) {
            return $p;
        }
    }

    return null;
}

/**
 * The installment schedule beside what was taken: one row a week from the
 * first week until the amount is covered (plus any week something was
 * taken), each with what was planned, what was taken, and the balance.
 */
function advance_schedule(array $a): array
{
    $first = advance_first_week($a);
    if (! $first) {
        return ['rows' => [], 'expected_to_date' => 0.0, 'repaid' => 0.0, 'behind' => 0.0, 'weeks_left' => null];
    }
    $pauses = advance_pauses((int) $a['id']);
    $taken = [];
    foreach (rows('SELECT paid_on, amount FROM wage_advance_payments WHERE advance_id = ?', [(int) $a['id']]) as $p) {
        $w = week_ending((string) $p['paid_on']);
        $taken[$w] = round(($taken[$w] ?? 0) + (float) $p['amount'], 2);
    }
    $amount = (float) $a['amount'];
    $weekly = (float) $a['weekly_repayment'];
    $planned = 0.0;
    $repaid = 0.0;
    $expectedToDate = 0.0;
    $thisWeek = week_ending(date('Y-m-d'));
    $rows = [];
    $week = min($first, $taken ? min(array_keys($taken)) : $first);
    for ($i = 0; $i < 520; $i++) {
        $pause = advance_paused_on($pauses, $week);
        $plan = $week >= $first && ! $pause && $planned < $amount - 0.004 ? round(min($weekly, $amount - $planned), 2) : 0.0;
        $planned = round($planned + $plan, 2);
        $got = $taken[$week] ?? 0.0;
        $repaid = round($repaid + $got, 2);
        if ($week < $thisWeek) {
            $expectedToDate = $planned;
        }
        if ($plan > 0 || $got > 0 || $pause) {
            $rows[] = ['week' => $week, 'planned' => $plan, 'taken' => $got, 'balance' => round($amount - $repaid, 2), 'paused' => $pause !== null,
                       'state' => $pause ? 'paused' : ($week >= $thisWeek ? 'coming' : ($got + 0.004 >= $plan ? 'taken' : 'short'))];
        }
        if ($planned >= $amount - 0.004 && $week >= max(array_merge([$first], array_keys($taken)))) {
            break;
        }
        $week = date('Y-m-d', strtotime($week . ' +7 days'));
    }
    $balance = max(0.0, round($amount - $repaid, 2));
    $closed = in_array($a['status'], ['cleared', 'cancelled', 'written_off'], true);

    return ['rows' => $rows, 'expected_to_date' => $expectedToDate, 'repaid' => $repaid,
            'behind' => $closed ? 0.0 : max(0.0, round($expectedToDate - $repaid, 2)),
            'weeks_left' => $closed || $weekly <= 0 ? 0 : (int) ceil($balance / $weekly)];
}

/** Why this person may not approve this advance, or null. */
function advance_approval_refusal(array $a): ?string
{
    if ((int) $a['requested_by'] === uid()) {
        return t('Whoever recorded an advance does not approve it.');
    }
    $limit = advance_admin_above();
    $owed = advance_owed((int) $a['candidate_id']);
    if (! can('admin') && ((float) $a['amount'] > $limit + 0.004 || $owed + (float) $a['amount'] > $limit + 0.004)) {
        return t('Above :limit, or when it takes what the person owes above it (:owed already), an administrator approves.', ['limit' => money($limit), 'owed' => money($owed)]);
    }

    return null;
}

/** Pause repayment for a window of weeks. Returns the refusal, or null. */
function advance_pause(array $a, string $from, string $until, string $reason): ?string
{
    if ($a['status'] !== 'paid_out') {
        return t('Only an advance being repaid is paused.');
    }
    if (! valid_date($from) || ! valid_date($until) || $until < $from) {
        return t('A pause runs from one week to another, the first first.');
    }
    $from = week_ending($from);
    $until = week_ending($until);
    if ($from < week_ending(date('Y-m-d'))) {
        return t('A pause starts this week or later: weeks already past are adjusted, not paused.');
    }
    if ((strtotime($until) - strtotime($from)) / 86400 > 7 * 26) {
        return t('A pause is at most 26 weeks.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why repayment is paused.');
    }
    foreach (advance_pauses((int) $a['id']) as $p) {
        if ($from <= $p['until_week'] && $until >= $p['from_week']) {
            return t('That overlaps a pause already set.');
        }
    }
    q('INSERT INTO advance_pauses (advance_id, from_week, until_week, reason, user_id) VALUES (?,?,?,?,?)', [(int) $a['id'], $from, $until, mb_substr(trim($reason), 0, 500), uid() ?: null]);
    advance_event((int) $a['id'], 'paused', d($from) . ' – ' . d($until) . ' · ' . trim($reason));

    return null;
}

/** Write off what is left. Administrators. Returns the refusal, or null. */
function advance_write_off(array $a, string $reason): ?string
{
    if (! can('admin')) {
        return t('Only an administrator writes off what is owed.');
    }
    if ($a['status'] !== 'paid_out') {
        return t('Only an advance being repaid is written off.');
    }
    $balance = advance_balance($a);
    if ($balance <= 0.004) {
        return t('Nothing is left to write off.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why it is written off.');
    }
    q("UPDATE wage_advances SET status = 'written_off', written_off_amount = ?, written_off_reason = ?, written_off_by = ?, written_off_at = NOW() WHERE id = ?",
      [$balance, mb_substr(trim($reason), 0, 500), uid() ?: null, (int) $a['id']]);
    advance_event((int) $a['id'], 'written_off', money($balance) . ' · ' . trim($reason));

    return null;
}

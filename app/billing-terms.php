<?php
/**
 * P5-M70: what the client is billed, written on each project (the owner, 17 Oct 2026: lodging, travel, per
 * diem, overtime and shift premiums must be billed as agreed; every shift is billed at the regular rate unless
 * the agreement says otherwise; all of it configurable).
 *
 * The invoice used to be hours worked x the bill rate, nothing else. Now each approved week is billed as:
 *  - regular hours at the bill rate; overtime (the hours the pay side counts as overtime) at the bill rate
 *    times a multiplier, at the bill rate, or at its own rate;
 *  - a premium per hour for a shift, when the agreement gives one (none by default: every shift at regular);
 *  - per diem, hotel and travel: not billed, at cost, or at cost plus a percentage - the hotel also at a flat
 *    rate per night; the costs are the week's own (its per diem, the nights in it, the trips leaving in it);
 *  - transport on site: not billed, per day on site, or per week.
 * Each part is a line of the invoice, with its quantity, its rate and where it comes from.
 */

declare(strict_types=1);

function bt_defaults(): array
{
    return ['ot_mode' => 'straight', 'ot_multiplier' => 1.5, 'ot_rate' => null, 'per_diem_mode' => 'none', 'per_diem_markup_pct' => 0.0,
            'lodging_mode' => 'none', 'lodging_markup_pct' => 0.0, 'lodging_night_rate' => null, 'travel_mode' => 'none', 'travel_markup_pct' => 0.0,
            'transport_mode' => 'none', 'transport_rate' => null];
}

/** The project's billing terms, or the defaults - as invoices were billed before: every hour at the bill rate, nothing else. */
function bt_terms(int $jobId): array
{
    $r = row('SELECT * FROM job_billing_terms WHERE job_id = ?', [$jobId]);

    return $r ? array_intersect_key($r, bt_defaults()) + bt_defaults() : bt_defaults();
}

function bt_modes(): array
{
    return [
        'ot_mode' => ['multiplier' => t('the bill rate times a multiplier'), 'straight' => t('the bill rate (no premium)'), 'rate' => t('its own rate')],
        'per_diem_mode' => ['none' => t('not billed'), 'cost' => t('at cost'), 'cost_plus' => t('at cost plus a percentage')],
        'lodging_mode' => ['none' => t('not billed'), 'cost' => t('at cost'), 'cost_plus' => t('at cost plus a percentage'), 'flat_night' => t('a flat rate per night')],
        'travel_mode' => ['none' => t('not billed'), 'cost' => t('at cost'), 'cost_plus' => t('at cost plus a percentage')],
        'transport_mode' => ['none' => t('not billed (included)'), 'per_day' => t('per day on site'), 'per_week' => t('per week worked')],
    ];
}

/** Saves the terms and the shift premiums. Returns the refusal, or null. */
function bt_save(int $jobId, array $in, int $by): ?string
{
    $t = bt_defaults();
    foreach (bt_modes() as $k => $choices) {
        $t[$k] = isset($choices[$in[$k] ?? '']) ? (string) $in[$k] : $t[$k];
    }
    $num = static function (string $k, float $min, float $max) use ($in): ?float {
        $v = trim((string) ($in[$k] ?? ''));
        return $v === '' ? null : (is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? round((float) $v, 2) : -1.0);
    };
    $t['ot_multiplier'] = $num('ot_multiplier', 1, 3) ?? 1.5;
    $t['ot_rate'] = $num('ot_rate', 0, 1000);
    foreach (['per_diem_markup_pct', 'lodging_markup_pct', 'travel_markup_pct'] as $k) {
        $t[$k] = $num($k, 0, 100) ?? 0.0;
    }
    $t['lodging_night_rate'] = $num('lodging_night_rate', 0, 2000);
    $t['transport_rate'] = $num('transport_rate', 0, 2000);
    foreach ($t as $k => $v) {
        if ($v === -1.0) {
            return t('Check the numbers: a multiplier from 1 to 3, a percentage from 0 to 100, amounts in dollars.');
        }
    }
    // P5-M72: an amount only goes with the way of billing that uses it - no markup on "at cost", no flat rate unless chosen.
    foreach (['per_diem', 'lodging', 'travel'] as $item) {
        if ($t[$item . '_mode'] !== 'cost_plus') {
            $t[$item . '_markup_pct'] = 0.0;
        } elseif (! $t[$item . '_markup_pct']) {
            return t('At cost plus a percentage needs the percentage. Otherwise choose at cost.');
        }
    }
    if ($t['lodging_mode'] !== 'flat_night') {
        $t['lodging_night_rate'] = null;
    }
    if ($t['ot_mode'] !== 'rate') {
        $t['ot_rate'] = null;
    }
    if ($t['ot_mode'] !== 'multiplier') {
        $t['ot_multiplier'] = 1.5;
    }
    if ($t['transport_mode'] === 'none') {
        $t['transport_rate'] = null;
    }
    if ($t['ot_mode'] === 'rate' && ! $t['ot_rate']) {
        return t('Give the overtime rate billed, or choose another way to bill overtime.');
    }
    if ($t['lodging_mode'] === 'flat_night' && ! $t['lodging_night_rate']) {
        return t('Give the flat rate billed per night.');
    }
    if ($t['transport_mode'] !== 'none' && ! $t['transport_rate']) {
        return t('Give the transport rate billed.');
    }
    $cols = array_keys($t);
    q('INSERT INTO job_billing_terms (job_id, ' . implode(', ', $cols) . ', updated_by) VALUES (?' . str_repeat(',?', count($cols)) . ',?)
       ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($c) => "$c = VALUES($c)", $cols)) . ', updated_by = VALUES(updated_by)',
      [$jobId, ...array_values($t), $by]);
    foreach ((array) ($in['shift_premium'] ?? []) as $shiftId => $premium) {
        $p = trim((string) $premium);
        if ($p !== '' && (! is_numeric($p) || (float) $p < 0 || (float) $p > 200)) {
            return t('A shift premium is dollars per hour, from 0 to 200.');
        }
        q('UPDATE project_shifts SET bill_premium = ? WHERE id = ? AND job_id = ?', [$p === '' ? 0 : round((float) $p, 2), (int) $shiftId, $jobId]);
    }
    log_activity('set what the client is billed', 'job', $jobId);

    return null;
}

/** The premium billed per hour for a shift of the project, by its name (0 when none). */
function bt_shift_premium(int $jobId, ?string $shift): float
{
    if ($shift === null || trim($shift) === '') {
        return 0.0;
    }

    return (float) val('SELECT bill_premium FROM project_shifts WHERE job_id = ? AND LOWER(name) = LOWER(?)', [$jobId, trim($shift)]);
}

/**
 * One approved week of one person, billed by the terms: the parts, each [kind, label, quantity, rate, amount].
 * $sheet: the timesheet with its placement; $m: week_money() of it.
 */
function bt_week_parts(array $sheet, array $m, array $job, array $terms): array
{
    $jobId = (int) $job['id'];
    $bill = (float) $m['bill_rate'];
    $worked = (float) $m['worked'] + (float) ($sheet['late_billed_hours'] ?? 0);
    // Overtime billed at the bill rate is not a line of its own: the hours read as one, as before.
    $ot = $terms['ot_mode'] === 'straight' ? 0.0 : min($worked, max(0.0, (float) ($m['overtime_hours'] ?? 0)));
    $regular = $worked - $ot;
    $parts = [];
    $add = static function (string $kind, string $label, float $qty, float $rate, ?float $amount = null) use (&$parts): void {
        $amount ??= $qty * $rate;
        if (round($amount, 2) != 0.0) {
            $parts[] = ['kind' => $kind, 'label' => $label, 'qty' => round($qty, 2), 'rate' => round($rate, 2), 'amount' => round($amount, 2)];
        }
    };
    $add('regular', t('Regular hours'), $regular, $bill);
    if ($ot > 0) {
        $otRate = match ($terms['ot_mode']) { 'straight' => $bill, 'rate' => (float) $terms['ot_rate'], default => $bill * (float) $terms['ot_multiplier'] };
        $add('overtime', t('Overtime hours'), $ot, $otRate);
    }
    $placement = (int) $sheet['placement_id'];
    $shift = (string) val('SELECT shift_label FROM assignment_details WHERE placement_id = ?', [$placement]);
    if (($premium = bt_shift_premium($jobId, $shift)) > 0) {
        $add('shift', t(':shift shift premium', ['shift' => $shift]), $worked, $premium);
    }
    $markup = static fn(string $mode, float $pct): float => $mode === 'cost_plus' ? 1 + $pct / 100 : 1.0;
    if ($terms['per_diem_mode'] !== 'none' && (float) $m['per_diem'] > 0) {
        $f = $markup($terms['per_diem_mode'], (float) $terms['per_diem_markup_pct']);
        $add('per_diem', $f > 1 ? t('Per diem, at cost plus :pct%', ['pct' => (float) $terms['per_diem_markup_pct']]) : t('Per diem, at cost'), 1, (float) $m['per_diem'] * $f);
    }
    $weekStart = date('Y-m-d', strtotime($sheet['week_ending'] . ' -6 days'));
    $weekAfter = date('Y-m-d', strtotime($sheet['week_ending'] . ' +1 day'));
    if ($terms['lodging_mode'] !== 'none') {
        $nights = 0;
        $cost = 0.0;
        foreach (rows("SELECT l.check_in, l.check_out, COALESCE(l.nightly_rate, h.nightly_rate, 0) AS rate FROM lodging l LEFT JOIN hotels h ON h.id = l.hotel_id
                       WHERE l.placement_id = ? AND l.status <> 'cancelled' AND l.check_in IS NOT NULL AND l.check_in < ? AND COALESCE(l.check_out, ?) > ?",
                      [$placement, $weekAfter, $weekAfter, $weekStart]) as $l) {
            $from = max($l['check_in'], $weekStart);
            $to = min((string) ($l['check_out'] ?: $weekAfter), $weekAfter);
            $n = max(0, (int) round((strtotime($to) - strtotime($from)) / 86400));
            $nights += $n;
            $cost += $n * (float) $l['rate'];
        }
        if ($nights) {
            if ($terms['lodging_mode'] === 'flat_night') {
                $add('lodging', t('Hotel, flat rate per night'), $nights, (float) $terms['lodging_night_rate']);
            } else {
                $f = $markup($terms['lodging_mode'], (float) $terms['lodging_markup_pct']);
                $add('lodging', $f > 1 ? t('Hotel nights, at cost plus :pct%', ['pct' => (float) $terms['lodging_markup_pct']]) : t('Hotel nights, at cost'), $nights, $nights ? $cost * $f / $nights : 0, $cost * $f);
            }
        }
    }
    if ($terms['travel_mode'] !== 'none') {
        $trip = (float) val("SELECT COALESCE(SUM(cost), 0) FROM travel WHERE placement_id = ? AND status <> 'cancelled' AND depart_time >= ? AND depart_time < ?", [$placement, $weekStart, $weekAfter]);
        if ($trip > 0) {
            $f = $markup($terms['travel_mode'], (float) $terms['travel_markup_pct']);
            $add('travel', $f > 1 ? t('Travel, at cost plus :pct%', ['pct' => (float) $terms['travel_markup_pct']]) : t('Travel, at cost'), 1, $trip * $f);
        }
    }
    if ($terms['transport_mode'] === 'per_day') {
        $add('transport', t('Transport on site, per day'), (float) ($sheet['per_diem_days'] ?? 0), (float) $terms['transport_rate']);
    } elseif ($terms['transport_mode'] === 'per_week' && $worked > 0) {
        $add('transport', t('Transport on site, per week'), 1, (float) $terms['transport_rate']);
    }

    return $parts;
}

/** The total of a week's parts. */
function bt_total(array $parts): float
{
    return round(array_sum(array_column($parts, 'amount')), 2);
}

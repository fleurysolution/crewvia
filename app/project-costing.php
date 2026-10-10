<?php
/**
 * Project costing (P3-M01): what a project earned, what it cost, by
 * category, against its budget.
 *
 * Read-only over records that already exist. Nothing here writes a
 * financial transaction. Each cost is taken from exactly one source, so
 * nothing is counted twice:
 *
 *   revenue        approved and paid weeks, hours worked x bill rate (the
 *                  figure frozen at approval). Client invoices are shown
 *                  beside it to reconcile, never added.
 *   labour         the same weeks' frozen labour cost and paid leave, plus
 *                  payroll adjustments made against them. Overtime is the
 *                  part of it above regular hours at the base rate.
 *   burden         employer contributions frozen with each week, plus the
 *                  project's burden rate on gross wages (an estimate of the
 *                  employer taxes and insurance ADP charges).
 *   per diem       the weeks' per diem and their expenses line.
 *   hotels         nights booked x nightly rate, and agency-paid hotel
 *                  claims. Hotel invoices and lodging orders bill those same
 *                  nights, so they are shown to reconcile, never added.
 *   transportation travel bookings, agency-paid flight and transport
 *                  claims, invoices against vehicle orders.
 *   equipment      invoices against safety-equipment orders.
 *   other          other agency-paid claims, invoices against other orders,
 *                  and invoices with no order or hotel.
 *   overhead       the project's overhead rate on revenue.
 *
 * Only approved or paid vendor invoices and claims count. A cancelled
 * booking does not.
 */

declare(strict_types=1);

function project_cost_categories(): array
{
    return ['revenue' => t('Revenue'), 'labour' => t('Labour'), 'burden' => t('Burden'), 'per_diem' => t('Per diem and expenses'),
            'hotels' => t('Hotels'), 'transportation' => t('Transportation'), 'equipment' => t('Equipment'), 'other' => t('Other costs'),
            'overhead' => t('Overhead')];
}

/** The project's current budget per category: the latest change of each. */
function project_budget(int $jobId): array
{
    $out = [];
    foreach (rows('SELECT b.category, b.amount FROM project_budget_changes b
                   WHERE b.job_id = ? AND b.id = (SELECT MAX(x.id) FROM project_budget_changes x WHERE x.job_id = b.job_id AND x.category = b.category)', [$jobId]) as $r) {
        $out[$r['category']] = (float) $r['amount'];
    }

    return $out;
}

/** Change one budget line. Appends; never overwrites. Returns the refusal, or null. */
function project_budget_set(int $jobId, string $category, string $amount, string $reason): ?string
{
    if (! isset(project_cost_categories()[$category])) {
        return t('Choose a budget line from the list.');
    }
    if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 100000000) {
        return t('A budget is an amount from 0 to 100,000,000.');
    }
    if (mb_strlen(trim($reason)) < 3) {
        return t('Say why the budget changes.');
    }
    if (! val('SELECT COUNT(*) FROM jobs WHERE id = ?', [$jobId])) {
        return t('Select a project first.');
    }
    $current = project_budget($jobId)[$category] ?? null;
    if ($current !== null && abs($current - (float) $amount) < 0.005) {
        return t('That is already the budget.');
    }

    q('INSERT INTO project_budget_changes (job_id, category, amount, previous_amount, reason, changed_by) VALUES (?,?,?,?,?,?)',
      [$jobId, $category, round((float) $amount, 2), $current, mb_substr(trim($reason), 0, 500), uid() ?: null]);

    return null;
}

/**
 * Everything the report shows, project to date.
 *
 * @return array{actual:array<string,float>, lines:array, weeks:array, labour:array, reconcile:array, committed:float, rates:array}
 */
function project_costs(int $jobId): array
{
    $job = row('SELECT j.*, pp.weekly_overtime_after, pp.overtime_multiplier FROM jobs j
                LEFT JOIN project_pay_policies pp ON pp.job_id = j.id WHERE j.id = ?', [$jobId]) ?: [];
    $burdenRate = (float) ($job['burden_percent'] ?? 0);
    $overheadRate = (float) ($job['overhead_percent'] ?? 0);

    $a = array_fill_keys(array_keys(project_cost_categories()), 0.0);
    $lines = [];
    $add = static function (string $cat, string $label, float $amount) use (&$a, &$lines): void {
        $amount = round($amount, 2);
        $a[$cat] = round($a[$cat] + $amount, 2);
        $lines[$cat][$label] = round(($lines[$cat][$label] ?? 0) + $amount, 2);
    };

    // ── the weeks: revenue, labour, overtime, per diem, contributions ──
    $labour = ['hours' => 0.0, 'overtime_hours' => 0.0, 'overtime_cost' => 0.0, 'gross' => 0.0, 'sheets' => 0];
    $weeks = [];
    $sheets = rows("SELECT ts.*, snap.result_json AS snapshot_json, p.pay_rate, p.bill_rate, p.per_diem_rate, e.employment_type, e.salary_per_period
                    FROM timesheets ts JOIN placements p ON p.id = ts.placement_id
                    LEFT JOIN employee_profiles e ON e.candidate_id = p.candidate_id
                    LEFT JOIN pay_snapshots snap ON snap.timesheet_id = ts.id
                    WHERE p.job_id = ? AND ts.status IN ('approved','paid') ORDER BY ts.week_ending", [$jobId]);
    foreach ($sheets as $s) {
        $m = week_money($s, $s, $job);
        $wages = (float) $m['labour_cost'] + (float) ($m['leave_pay'] ?? 0);
        $employer = (float) ($m['gross_to_net']['total_employer'] ?? 0);
        $overtime = ($m['salary_basis'] ?? null) !== null ? 0.0 : max(0.0, (float) $m['labour_cost'] - (float) ($m['regular_hours'] ?? 0) * (float) $m['pay_rate']);

        $add('revenue', t('Hours worked x bill rate'), (float) $m['bill_total']);
        $add('labour', t('Wages and paid leave'), $wages);
        $add('per_diem', t('Per diem'), (float) $m['per_diem']);
        $add('per_diem', t('Expenses on the weekly sheet'), (float) $m['expenses']);
        $add('burden', t('Employer contributions'), $employer);
        $add('burden', t('Burden rate on wages'), $wages * $burdenRate / 100);

        $labour['hours'] += (float) $m['worked'];
        $labour['overtime_hours'] += (float) ($m['overtime_hours'] ?? 0);
        $labour['overtime_cost'] += $overtime;
        $labour['gross'] += $wages;
        $labour['sheets']++;

        $wk = (string) $s['week_ending'];
        $weeks[$wk] ??= ['revenue' => 0.0, 'cost' => 0.0, 'hours' => 0.0];
        $weeks[$wk]['revenue'] += (float) $m['bill_total'];
        $weeks[$wk]['cost'] += $wages + (float) $m['per_diem'] + (float) $m['expenses'] + $employer + $wages * $burdenRate / 100;
        $weeks[$wk]['hours'] += (float) $m['worked'];
    }
    $labour = array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $labour);

    // Corrections paid after a week was approved, against that week's sheet.
    try {
        foreach (rows("SELECT a.kind = 'reimbursement' AS reimbursed, SUM(a.amount) AS total FROM payroll_adjustments a
                       JOIN timesheets t ON t.id = a.timesheet_id JOIN placements p ON p.id = t.placement_id
                       WHERE p.job_id = ? GROUP BY a.kind = 'reimbursement'", [$jobId]) as $adj) {
            $add($adj['reimbursed'] ? 'per_diem' : 'labour', $adj['reimbursed'] ? t('Reimbursements paid in a pay period') : t('Payroll adjustments'), (float) $adj['total']);
        }
    } catch (Throwable $e) {
    }

    // ── hotels ──
    $add('hotels', t('Nights booked x nightly rate'), (float) val(
        "SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(COALESCE(l.check_out, CURDATE()), l.check_in)) * COALESCE(l.nightly_rate, 0)), 0)
         FROM lodging l JOIN placements p ON p.id = l.placement_id
         WHERE p.job_id = ? AND l.check_in IS NOT NULL AND l.status <> 'cancelled'", [$jobId]));

    // ── claims the agency pays, by what they were for ──
    foreach (rows("SELECT e.category, SUM(e.amount) AS total FROM expense_claims e JOIN placements p ON p.id = e.placement_id
                   WHERE p.job_id = ? AND e.status IN ('approved','paid') AND e.payer = 'agency' GROUP BY e.category", [$jobId]) as $c) {
        $cat = ['hotel' => 'hotels', 'flight' => 'transportation', 'transport' => 'transportation'][$c['category']] ?? 'other';
        $add($cat, t('Reimbursed claims'), (float) $c['total']);
    }

    // ── travel ──
    $add('transportation', t('Travel bookings'), (float) val(
        "SELECT COALESCE(SUM(t.cost), 0) FROM travel t JOIN placements p ON p.id = t.placement_id WHERE p.job_id = ? AND t.status <> 'cancelled'", [$jobId]));

    // ── vendor invoices: by the order they bill, hotels to reconcile only ──
    $hotelInvoiced = 0.0;
    foreach (rows("SELECT v.amount, v.hotel_id, r.category FROM vendor_invoices v
                   LEFT JOIN purchase_orders o ON o.id = v.purchase_order_id LEFT JOIN purchase_requests r ON r.id = o.request_id
                   WHERE v.job_id = ? AND v.status IN ('approved','paid')", [$jobId]) as $v) {
        if ($v['hotel_id'] || $v['category'] === 'lodging') {
            $hotelInvoiced += (float) $v['amount'];
            continue;
        }
        $cat = ['vehicle' => 'transportation', 'safety_equipment' => 'equipment'][$v['category'] ?? ''] ?? 'other';
        $add($cat, t('Vendor invoices'), (float) $v['amount']);
    }

    // ── overhead ──
    $add('overhead', t('Overhead rate on revenue'), $a['revenue'] * $overheadRate / 100);

    // Ordered and not yet invoiced: money the project will spend.
    $committed = 0.0;
    foreach (procurement_commitments($jobId) as $o) {
        if ($o['category'] !== 'lodging' && $o['status'] === 'approved') {
            $committed += max(0.0, (float) $o['total'] - (float) $o['invoiced']);
        }
    }

    $reconcile = [
        'invoiced_clients' => (float) val("SELECT COALESCE(SUM(total), 0) FROM client_invoices WHERE job_id = ? AND status IN ('issued','paid')", [$jobId]),
        'invoiced_hotels'  => round($hotelInvoiced, 2),
    ];

    krsort($weeks);

    return ['actual' => $a, 'lines' => $lines, 'weeks' => array_slice($weeks, 0, 26, true), 'labour' => $labour,
            'reconcile' => $reconcile, 'committed' => round($committed, 2),
            'rates' => ['burden' => $burdenRate, 'overhead' => $overheadRate]];
}

/** Direct cost, margin, net, from the categories. */
function project_profit(array $actual): array
{
    $direct = 0.0;
    foreach (['labour', 'burden', 'per_diem', 'hotels', 'transportation', 'equipment', 'other'] as $k) {
        $direct += $actual[$k];
    }
    $gross = $actual['revenue'] - $direct;
    $net = $gross - $actual['overhead'];

    return ['direct' => round($direct, 2), 'gross' => round($gross, 2), 'net' => round($net, 2),
            'gross_pct' => $actual['revenue'] > 0 ? round($gross / $actual['revenue'] * 100, 1) : null,
            'net_pct' => $actual['revenue'] > 0 ? round($net / $actual['revenue'] * 100, 1) : null];
}

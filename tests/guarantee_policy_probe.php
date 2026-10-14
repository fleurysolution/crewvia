<?php
/**
 * The P5-M34 test's eyes and hands, on the test database only.
 *   money <timesheet>      the week's pay, as payroll computes it now
 *   taxable <placement>    whether their per diem is taxable
 *   taxcode <code>         sets the ADP earnings code for taxable per diem
 */

declare(strict_types=1);

require __DIR__ . '/test-app/app/bootstrap.php';

if (val('SELECT DATABASE()') !== 'rss_ops_test') { fwrite(STDERR, "Refusing: test database only.\n"); exit(2); }
[$cmd, $arg] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($cmd === 'money') {
    $t = row('SELECT t.*, p.pay_rate, p.bill_rate, p.per_diem_rate, p.per_diem_taxable, p.job_id FROM timesheets t JOIN placements p ON p.id = t.placement_id WHERE t.id = ?', [(int) $arg]);
    $job = row('SELECT j.*, pp.weekly_overtime_after, pp.overtime_multiplier, pp.guarantee_absence_rule, pp.guarantee_day_hours, pp.guarantee_overtime FROM jobs j LEFT JOIN project_pay_policies pp ON pp.job_id = j.id WHERE j.id = ?', [(int) $t['job_id']]);
    $m = week_money($t, $t, $job);
    echo json_encode(array_intersect_key($m, array_flip(['guarantee', 'unexcused_absences', 'guarantee_rule', 'paid_hours', 'regular_hours', 'overtime_hours', 'labour_cost', 'per_diem', 'per_diem_taxable'])));
} elseif ($cmd === 'taxable') {
    echo json_encode((int) val('SELECT per_diem_taxable FROM placements WHERE id = ?', [(int) $arg]));
} elseif ($cmd === 'taxcode') {
    q("INSERT INTO platform_settings(setting_key,setting_value) VALUES ('adp_perdiem_taxable_code',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$arg]);
    echo json_encode(true);
}

<?php
/** Leave entitlement and eligibility, case by case, worked out by hand. No database. */
require (is_dir(__DIR__ . '/test-app') ? __DIR__ . '/test-app' : dirname(__DIR__)) . '/app/leave-calculation.php';

$cases = [];
function lv_assert(string $name, bool $ok, $detail = null): void
{
    global $cases;
    if (! $ok) { throw new RuntimeException($name . ($detail !== null ? ' ' . json_encode($detail) : '')); }
    $cases[] = $name;
    echo 'PASS ' . $name . PHP_EOL;
}

$annual = ['accrual_method' => 'annual', 'days_allowed' => 5, 'carryover_max_days' => 0, 'accrual_cap_days' => null];
$e = leave_entitlement($annual, 0, 0, 0);
lv_assert('Annual: five days a year, as before', $e['allowed'] == 5 && $e['carryover'] == 0, $e);

$unlimited = ['accrual_method' => 'annual', 'days_allowed' => null, 'carryover_max_days' => 5];
$e = leave_entitlement($unlimited, 0, 0, 0);
lv_assert('No allowance means unlimited, and nothing carries', $e['allowed'] === null && $e['carryover'] == 0, $e);

$carry = $annual + [];
$carry['carryover_max_days'] = 3;
$e = leave_entitlement($carry, 0, 0, 1);
lv_assert('Carryover: 4 unused last year, up to 3 carry: 8 this year', $e['allowed'] == 8 && $e['carryover'] == 3, $e);
$e = leave_entitlement($carry, 0, 0, 5);
lv_assert('Carryover: nothing unused, nothing carries', $e['carryover'] == 0 && $e['allowed'] == 5, $e);

$accrual = ['accrual_method' => 'hours_worked', 'accrual_hours_per_day' => 240, 'carryover_max_days' => 0, 'accrual_cap_days' => null];
$e = leave_entitlement($accrual, 600, 0, 0);
lv_assert('Accrual: 600 hours at one day per 240 is 2.5 days', $e['allowed'] == 2.5, $e);
$e = leave_entitlement($accrual, 599, 0, 0);
lv_assert('Accrual rounds down, never up', $e['allowed'] == 2.49, $e);
$capped = $accrual + [];
$capped['accrual_cap_days'] = 3;
$e = leave_entitlement($capped, 2400, 0, 0);
lv_assert('A cap limits what is available at once', $e['allowed'] == 3 && $e['grant'] == 10, $e);
$both = $accrual + [];
$both['carryover_max_days'] = 2;
$e = leave_entitlement($both, 240, 960, 1);
lv_assert('Accrual and carryover: 1 earned now, 3 of 4 unused last year capped at 2 carried', $e['allowed'] == 3 && $e['carryover'] == 2, $e);
$broken = $accrual + [];
$broken['accrual_hours_per_day'] = 0;
lv_assert('Accrual with no rate earns nothing rather than dividing by zero', leave_entitlement($broken, 500, 0, 0)['allowed'] == 0);

$open = ['eligible_after_days' => 0, 'eligible_employment_types' => null];
lv_assert('No conditions: anybody may take it', leave_ineligibility($open, 'contractor', null, '2026-10-01') === null);
$hourlyOnly = ['eligible_after_days' => 0, 'eligible_employment_types' => 'hourly,salaried'];
lv_assert('Restricted to employees: a contractor is refused', leave_ineligibility($hourlyOnly, 'contractor', '2026-01-01', '2026-10-01') === 'employment_type');
lv_assert('Restricted to employees: an hourly employee may', leave_ineligibility($hourlyOnly, 'hourly', '2026-01-01', '2026-10-01') === null);
$wait = ['eligible_after_days' => 90, 'eligible_employment_types' => null];
lv_assert('Waiting period: day 89 is refused', leave_ineligibility($wait, 'hourly', '2026-07-04', '2026-10-01') === 'waiting_period');
lv_assert('Waiting period: day 90 is allowed', leave_ineligibility($wait, 'hourly', '2026-07-03', '2026-10-01') === null);
lv_assert('Waiting period with no start date is refused', leave_ineligibility($wait, 'hourly', null, '2026-10-01') === 'not_started');

lv_assert('Days in a week: a request across the boundary counts only its days inside', leave_days_between('2026-10-02', '2026-10-06', '2026-10-04', '2026-10-10') === 3);
lv_assert('Days in a week: no overlap is zero', leave_days_between('2026-09-01', '2026-09-02', '2026-10-04', '2026-10-10') === 0);

file_put_contents(__DIR__ . '/leave-unit-results.json', json_encode($cases, JSON_PRETTY_PRINT));

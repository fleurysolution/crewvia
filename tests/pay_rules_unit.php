<?php
/**
 * The pay-rules arithmetic, case by case, worked out by hand. No database.
 *
 * These test that configured rules are applied correctly. They do not -
 * and cannot - say a rule set is right for a jurisdiction; that is
 * confirmed by a person when the rule set is activated.
 */
require (is_dir(__DIR__ . '/test-app') ? __DIR__ . '/test-app' : dirname(__DIR__)) . '/app/pay-rules-calculation.php';

$cases = [];
function pr_assert(string $name, bool $result, $detail = null): void
{
    global $cases;
    if (! $result) { throw new RuntimeException($name . ($detail !== null ? ' ' . json_encode($detail) : '')); }
    $cases[] = $name;
    echo 'PASS ' . $name . PHP_EOL;
}

$federal = ['weekly_after' => 40.0, 'weekly_multiplier' => 1.5, 'daily_after' => null, 'daily_multiplier' => 1.5,
            'double_after' => null, 'double_multiplier' => 2.0, 'holiday_multiplier' => null];
$daily   = ['weekly_after' => 40.0, 'weekly_multiplier' => 1.5, 'daily_after' => 8.0, 'daily_multiplier' => 1.5,
            'double_after' => 12.0, 'double_multiplier' => 2.0, 'holiday_multiplier' => null];
$week = static function (array $hours, string $from = '2026-09-28'): array {
    $out = [];
    foreach ($hours as $i => $h) { $out[date('Y-m-d', strtotime($from . " +$i days"))] = (float) $h; }
    return $out;
};
$run = static fn(array $days, array $rules, array $extra = []) => pay_rules_week(
    $days, $extra['week'] ?? array_sum($days), $extra['other'] ?? 0.0, $extra['guarantee'] ?? 0.0, $extra['rate'] ?? 20.0,
    $rules, $extra['holidays'] ?? [], $extra['shift'] ?? 0.0, $extra['flsa'] ?? 'non_exempt',
    $extra['type'] ?? 'hourly', $extra['salary'] ?? null);

$r = $run($week([10, 10, 10, 10, 10]), $federal);
pr_assert('Weekly line: 50 hours is 40 regular and 10 overtime', $r['regular_hours'] == 40 && $r['overtime_hours'] == 10 && $r['labour_cost'] == 40 * 20 + 10 * 30, $r);

$r = $run($week([12, 12, 8, 8]), $daily);
pr_assert('Daily line: two 12-hour days give 8 daily overtime hours, no weekly', $r['daily_overtime_hours'] == 8 && $r['weekly_overtime_hours'] == 0 && $r['regular_hours'] == 32, $r);

$r = $run($week([14]), $daily);
pr_assert('Double time: a 14-hour day is 8 regular, 4 overtime, 2 double', $r['regular_hours'] == 8 && $r['overtime_hours'] == 4 && $r['double_hours'] == 2 && $r['labour_cost'] == 8 * 20 + 4 * 30 + 2 * 40, $r);

$r = $run($week([10, 10, 10, 10, 10]), $daily);
pr_assert('No pyramiding: daily overtime is not counted again by the weekly line', $r['daily_overtime_hours'] == 10 && $r['weekly_overtime_hours'] == 0 && $r['regular_hours'] == 40, $r);

$holiday = $federal + [];
$holiday['holiday_multiplier'] = 1.5;
$days = $week([8, 8, 8, 8, 8]);
$r = $run($days, $holiday, ['holidays' => [array_key_first($days)]]);
pr_assert('Holiday: 8 hours on the holiday paid at its rate, the rest regular', $r['holiday_hours'] == 8 && $r['labour_cost'] == 32 * 20 + 8 * 30, $r);

$days = $week([8, 8, 8, 8, 8, 8]);
$r = $run($days, $holiday, ['holidays' => [array_key_first($days)]]);
pr_assert('Holiday and a long week: holiday premium and overtime, each once', $r['holiday_hours'] == 8 && $r['overtime_hours'] == 8 && $r['labour_cost'] == 32 * 20 + 8 * 30 + 8 * 30, $r);

$r = $run($week([10, 10]), $federal, ['other' => 30.0]);
pr_assert('Other assignments count first: 30 elsewhere and 20 here is 10 regular and 10 overtime here', $r['regular_hours'] == 10 && $r['overtime_hours'] == 10, $r);
pr_assert('Hours on another assignment send the week to the provider', $r['requires_provider_review'] && in_array('hours_on_other_assignments', $r['review_reasons'], true), $r);

$r = $run($week([5, 5]), $federal, ['other' => 45.0]);
pr_assert('Already over the line elsewhere: every hour here is overtime', $r['regular_hours'] == 0 && $r['overtime_hours'] == 10, $r);

$r = $run($week([10, 10, 10, 10, 10]), $federal, ['flsa' => 'exempt']);
pr_assert('Exempt: no overtime is computed', $r['overtime_hours'] == 0 && $r['labour_cost'] == 50 * 20, $r);

$r = $run($week([10, 10, 10, 10, 10]), $federal, ['flsa' => 'not_determined']);
pr_assert('Not yet determined: paid as non-exempt, and flagged', $r['overtime_hours'] == 10 && in_array('overtime_status_not_determined', $r['review_reasons'], true), $r);

$r = $run($week([10, 10, 10, 10, 10]), $federal, ['flsa' => 'not_applicable', 'type' => 'contractor']);
pr_assert('Contractor: no overtime, flagged as not an employee', $r['overtime_hours'] == 0 && in_array('not_an_employee', $r['review_reasons'], true), $r);

$r = $run($week([9, 8, 8, 8, 8]), $federal, ['guarantee' => 50.0, 'rate' => 50.0]);
pr_assert('Guarantee: 41 worked of a 50 guarantee pays 49 regular and 1 overtime, as before', $r['regular_hours'] == 49 && $r['overtime_hours'] == 1 && $r['labour_cost'] == 2525.0, $r);

$r = $run($week([8, 8, 8, 8]), $federal, ['guarantee' => 50.0, 'rate' => 50.0]);
pr_assert('Guarantee never creates overtime', $r['overtime_hours'] == 0 && $r['labour_cost'] == 2500.0, $r);

$r = $run([], $daily, ['week' => 45.0]);
pr_assert('A typed sheet with no days: weekly line only, and it says so', $r['overtime_hours'] == 5 && in_array('daily_rules_not_applied', $r['review_reasons'], true), $r);

$r = $run($week([9, 9, 9, 9, 9]), $federal, ['shift' => 2.0]);
pr_assert('Shift premium is part of the rate overtime is paid on', $r['labour_cost'] == 40 * 22 + 5 * 33, $r);

$r = $run($week([10, 10, 10, 10, 10]), $federal, ['salary' => 1500.0, 'type' => 'salaried']);
pr_assert('Salaried with overtime keeps the salary and goes to the provider', $r['labour_cost'] == 1500.0 && $r['requires_provider_review'], $r);

try { $run($week([25]), $federal); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
pr_assert('A day over 24 hours is rejected', $ok);
$bad = $federal; $bad['weekly_multiplier'] = 0.5;
try { $run($week([8]), $bad); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
pr_assert('A multiplier below 1 is rejected', $ok);

file_put_contents(__DIR__ . '/pay-rules-unit-results.json', json_encode($cases, JSON_PRETTY_PRINT));

<?php
/** Gross to net before taxes, case by case, worked out by hand. No database. */
require (is_dir(__DIR__ . '/test-app') ? __DIR__ . '/test-app' : dirname(__DIR__)) . '/app/gross-to-net-calculation.php';

$cases = [];
function gn_assert(string $name, bool $ok, $detail = null): void
{
    global $cases;
    if (! $ok) { throw new RuntimeException($name . ($detail !== null ? ' ' . json_encode($detail) : '')); }
    $cases[] = $name;
    echo 'PASS ' . $name . PHP_EOL;
}

$fixed = static fn(string $code, float $a, bool $pre = false) => ['code' => $code, 'label' => $code, 'side' => 'deduction', 'method' => 'fixed', 'amount' => $a, 'pre_tax' => $pre, 'provider_code' => null];
$pct = static fn(string $code, float $p, string $side = 'deduction') => ['code' => $code, 'label' => $code, 'side' => $side, 'method' => 'percent_of_gross', 'amount' => $p, 'pre_tax' => false, 'provider_code' => null];
$adv = ['code' => 'advance_repayment', 'label' => 'Advance repayment', 'provider_code' => 'ADV'];

$r = gross_to_net(1000, 0, 0, [], []);
gn_assert('Nothing to deduct: net before tax is the gross', $r['net_before_tax'] == 1000 && $r['total_deductions'] == 0, $r);

$r = gross_to_net(1000, 150, 40, [$fixed('uniform', 25, true), $pct('union', 2)], []);
gn_assert('Fixed and percentage: 25 + 2% of 1000 = 45 taken', $r['total_deductions'] == 45 && $r['pre_tax_deductions'] == 25, $r);
gn_assert('Per diem and expenses are added after, never deducted from', $r['net_before_tax'] == 1000 - 45 + 150 + 40 && $r['reimbursements'] == 190, $r);

$r = gross_to_net(1000, 0, 0, [$pct('workers_comp', 3.5, 'employer_contribution')], []);
gn_assert('An employer contribution costs the employer, not the person', $r['total_employer'] == 35 && $r['net_before_tax'] == 1000, $r);

$r = gross_to_net(1000, 0, 0, [], [['id' => 7, 'balance' => 300, 'weekly' => 100]], $adv);
gn_assert('An advance is repaid at its weekly amount', $r['total_deductions'] == 100 && $r['deductions'][0]['advance_id'] === 7, $r);

$r = gross_to_net(1000, 0, 0, [], [['id' => 7, 'balance' => 60, 'weekly' => 100]], $adv);
gn_assert('Never more than what is still owed', $r['total_deductions'] == 60, $r);

$r = gross_to_net(120, 0, 0, [$fixed('uniform', 50)], [['id' => 7, 'balance' => 300, 'weekly' => 100]], $adv);
gn_assert('Wages never go below zero: 50 then 70 of the 100, and 30 short', $r['total_deductions'] == 120 && $r['net_before_tax'] == 0
          && $r['shortfalls'][0]['amount'] == 30 && $r['shortfalls'][0]['advance_id'] === 7, $r);

$r = gross_to_net(0, 200, 0, [$fixed('uniform', 50)], [], $adv);
gn_assert('A week of only per diem: nothing taken from it, all of it short', $r['total_deductions'] == 0 && $r['shortfalls'][0]['amount'] == 50 && $r['net_before_tax'] == 200, $r);

$r = gross_to_net(1000, 0, 0, [], [['id' => 1, 'balance' => 500, 'weekly' => 200], ['id' => 2, 'balance' => 500, 'weekly' => 900]], $adv);
gn_assert('Two advances, oldest first, the second only partly', $r['deductions'][0]['amount'] == 200 && $r['deductions'][1]['amount'] == 500 && $r['net_before_tax'] == 300, $r);

$r = gross_to_net(1000, 0, 0, [], [['id' => 7, 'balance' => 300, 'weekly' => 100]], null);
gn_assert('Without the advance item, no advance is taken', $r['total_deductions'] == 0, $r);

try { gross_to_net(-1, 0, 0, [], []); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
gn_assert('Negative gross is rejected', $ok);
try { gross_to_net(100, 0, 0, [$fixed('bad', -5)], []); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
gn_assert('A negative deduction is rejected', $ok);

file_put_contents(__DIR__ . '/gross-to-net-unit-results.json', json_encode($cases, JSON_PRETTY_PRINT));

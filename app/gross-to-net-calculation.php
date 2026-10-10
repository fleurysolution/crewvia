<?php
/**
 * Gross to net, before taxes, for one person's week. Pure: no database.
 * Tested in tests/gross_to_net_unit.php.
 *
 * Taxes are not computed here and never will be from this code: the
 * payroll provider withholds them (constraint C9). What this prepares is
 * what the provider needs alongside the hours - the deductions the agency
 * agreed with the person, and what the employer pays on top.
 *
 * Rules, in order:
 *   - gross wages are labour cost plus paid leave; per diem and expenses
 *     are reimbursements, never deducted from, and added after
 *   - fixed and percentage deductions are taken in their configured
 *     order, then open advances at their agreed weekly repayment, oldest
 *     first, never more than the advance's balance
 *   - nothing takes wages below zero: a deduction that does not fit is
 *     taken in part and the shortfall is reported, not carried silently
 *   - employer contributions are a cost to the employer, shown, never
 *     taken from the person
 */

declare(strict_types=1);

/**
 * @param array<int,array{code:string,label:string,side:string,method:string,amount:float,pre_tax:int|bool,provider_code:?string}> $items
 * @param array<int,array{id:int,balance:float,weekly:float}> $advances
 */
function gross_to_net(float $grossWages, float $perDiem, float $expenses, array $items, array $advances,
                      ?array $advanceItem = null): array
{
    foreach ([$grossWages, $perDiem, $expenses] as $n) {
        if (! is_finite($n) || $n < 0) {
            throw new InvalidArgumentException('Invalid pay amounts.');
        }
    }

    $room = round($grossWages, 2);
    $deductions = $employer = $shortfalls = [];

    $take = static function (array $line, float $wanted) use (&$room, &$deductions, &$shortfalls): void {
        $wanted = round(max(0.0, $wanted), 2);
        $taken = round(min($wanted, $room), 2);
        $room = round($room - $taken, 2);

        if ($taken > 0) {
            $deductions[] = $line + ['amount' => $taken];
        }

        if ($taken < $wanted) {
            $shortfalls[] = $line + ['amount' => round($wanted - $taken, 2)];
        }
    };

    foreach ($items as $item) {
        $value = (float) $item['amount'];

        if (! is_finite($value) || $value < 0) {
            throw new InvalidArgumentException('Invalid pay item.');
        }

        $amount = $item['method'] === 'percent_of_gross' ? round($grossWages * $value / 100, 2) : round($value, 2);
        $line = ['code' => (string) $item['code'], 'label' => (string) $item['label'],
                 'pre_tax' => (bool) $item['pre_tax'], 'provider_code' => $item['provider_code'] ?? null];

        if ($item['side'] === 'employer_contribution') {
            if ($amount > 0) {
                $employer[] = $line + ['amount' => $amount];
            }
            continue;
        }

        $take($line, $amount);
    }

    if ($advanceItem !== null) {
        foreach ($advances as $advance) {
            $wanted = min((float) $advance['weekly'], (float) $advance['balance']);
            $take(['code' => (string) $advanceItem['code'], 'label' => (string) $advanceItem['label'],
                   'pre_tax' => false, 'provider_code' => $advanceItem['provider_code'] ?? null,
                   'advance_id' => (int) $advance['id']], $wanted);
        }
    }

    $totalDeductions = round(array_sum(array_column($deductions, 'amount')), 2);

    return [
        'gross_wages'          => round($grossWages, 2),
        'deductions'           => $deductions,
        'total_deductions'     => $totalDeductions,
        'pre_tax_deductions'   => round(array_sum(array_column(array_filter($deductions, fn($d) => $d['pre_tax']), 'amount')), 2),
        'employer'             => $employer,
        'total_employer'       => round(array_sum(array_column($employer, 'amount')), 2),
        'shortfalls'           => $shortfalls,
        'reimbursements'       => round($perDiem + $expenses, 2),
        'net_before_tax'       => round($grossWages - $totalDeductions + $perDiem + $expenses, 2),
    ];
}

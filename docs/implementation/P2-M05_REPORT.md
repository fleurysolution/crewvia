# P2-M05 — loans and wage advances: schedules, approval controls, balances, payroll deductions

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Wage advances: requested → approved → paid out → cleared, or cancelled | existed | `wage_advances`, `/advances` |
| What the person already owes, shown before another is agreed | existed | `advance_owed()` |
| Repayment from pay each week at the agreed amount, oldest first, never above the balance, recorded at approval | existed (P1-M05) | `gross_to_net_for_sheet()`, `wage_advance_payments` |
| Manual repayment, never above the balance | existed | `/advances` |
| Loans (larger, longer) | **missing** | — |
| A start week, and a schedule against what was taken | **missing**: repayment started the week it was paid out | — |
| Approval controls | **missing**: anyone in payroll approved anything, including their own | — |
| Pausing repayment, writing off | **missing** | — |
| Outstanding balances across people, and who is behind | **missing**: one total | — |

## What was built (on `/advances`)

- **Loans as well as advances.** They have the same mechanics, recorded
  and shown as such.
- **A first week.** Repayment starts on the week set (this week or later),
  or else the week it is paid out. Payroll takes nothing before it.
- **The schedule.** It runs week by week from the first week: the weekly
  amount until the total is covered, skipping paused weeks. Beside each
  week is what payroll actually took (taken, short, paused or coming) and
  the balance. An advance that has taken less than its schedule expected
  by last week is **behind**, by how much.
- **Approval controls.**
  - Whoever recorded an advance never approves it.
  - Above the limit, or when it takes what the person already owes above
    it, only an administrator approves. The limit is $1,000 by default.
- **Pauses.** Repayment can be paused for up to 26 weeks, from this week
  on, with a reason: no hours, a plant shutdown, hardship. Pauses never
  overlap. In a paused week payroll takes nothing for that advance (others
  still run) and the schedule expects nothing.
- **Write-offs.** An administrator writes off what is left, with a reason.
  The amount is kept, and payroll stops taking it.
- **Outstanding.** Everyone being repaid, with what they owe, the weekly
  amount, the weeks left, and whether they are on schedule, behind (by
  how much) or paused (until when).
- **History.** Each advance shows when it was recorded, approved, paid out,
  repaid by hand, paused and written off, with who did it.

Schema: `advance_pauses`, `advance_events`; on `wage_advances` the kind,
first week, write-off fields, and status `written_off`; the
`advance_admin_above` setting. Rollback: `install/rollback/p2-m05.sql`
(written-off advances become cleared so payroll does not take them again).

## Tests

`tests/loans_db.php` (rollback including the written-off case, upgrade
keeping repayments as they are, silent repeat) and `tests/loans_http.py`.
They check payroll's actual deductions on a 1,000 week:

- a past first week refused;
- a 400 advance from next week, which a recruiter cannot approve and
  payroll can;
- a 1,500 loan its recorder cannot approve, another payroll user cannot
  (above the limit), and an administrator can;
- this week only the loan is taken, next week both, oldest first;
- schedules of 4 × 100 and 10 × 150;
- a loan started two weeks ago with one 150 taken shows 150 behind;
- pause refusals (role, past week, no reason, overlap); during the pause
  only the advance is taken, after it the loan again;
- the write-off: role and reason refusals, 1,350 written off, no longer
  taken, out of the outstanding list;
- the loan's history in order;
- translations.

Full isolated run: **1466 PASS, 0 FAIL** (browser step skipped).

Mutation: I let payroll ignore pauses. The suite failed on "Payroll takes
nothing for the loan while it is paused".

## Limits

- No interest: a loan is repaid at its amount.
- A write-off is not exported to QuickBooks as an expense; an accountant
  enters it there.
- Recovering what is owed from a final paycheck is subject to state law and
  is not done automatically.

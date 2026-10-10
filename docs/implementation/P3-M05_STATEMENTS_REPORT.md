# P3-M05 (completion) — income statement, balance sheet, cash flow, budgets

The first part of P3-M05 ([P3-M05_REPORT.md](P3-M05_REPORT.md)) closed
months and drew the trial balance of what Crewvia sent to QuickBooks. It
left the statements to QuickBooks, because Crewvia then had no books of its
own. Since P3-M03 it does, so the statements are read from the ledger.

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Posted, balanced journals with posting dates, accounts by side (asset, liability, equity, income, expense) | existed (P3-M03) | `gl_journals`, `gl_lines`, `accounting_accounts` |
| Trial balance by month, from the ledger | existed (P3-M03) | `/ledger` |
| Income statement, balance sheet, cash flow | **missing** | — |
| Budgets and variance | **missing** | — |

## What was built

Everything is read, nothing is entered: every figure is a sum of posted
journals on their posting dates (`app/statements.php`, `/statements`,
payroll and administrators).

**Income statement.**

- Income and expense accounts between two dates, and the net income or
  loss.
- It can be narrowed to one project (the ledger's Class). It then says
  plainly that costs not booked to a project, such as rent, are not in it.

**Balance sheet at a date.**

- Assets, liabilities, equity.
- Earnings are not closed into an account. Earlier years' net income shows
  as retained earnings, this year's as earnings to date. So assets equal
  liabilities and equity whenever the ledger balances, and the page says
  whether they do. The year is the calendar year.

**Cash flow, direct method.**

- Every journal that moves the bank account, grouped by the account on its
  other side:
  - **operating:** received from clients, paid to vendors, payroll and its
    liabilities paid, costs paid directly, other income;
  - **investing:** other assets bought or sold;
  - **financing:** owner contributions and draws, loans and other
    liabilities.
- Opening cash plus the movements must equal the bank balance at the end.
  The page says whether it does.

**Budgets.**

- An amount per income or expense account and month. A range of months can
  be set at once, up to 24.
- Every change is kept: the old amount, the new one, who made it, the note.
  Saving the same amount again changes nothing.
- Budget beside actual, per account and per month, with the variance and
  its percentage. It is favourable when income beats its budget or a cost
  stays under it, and unfavourable otherwise.
- Accounts with no budget still show what was spent.

**Printing.**

- Each statement prints on Letter, black on white, with the brand mark as
  an image and no browser stamp.
- The footer says "Not an audited statement".

**Integrity.** If the ledger's integrity check fails, both the page and
the printout say the figures cannot be trusted.

Schema: tables `gl_budgets` and `gl_budget_events`. Rollback:
`install/rollback/p3-m05-statements.sql`. The P3-M02 and P3-M03 rollbacks
now drop the budgets first, because budgets are set on the accounts those
rollbacks remove.

**Periods page.** The text "the full statements are QuickBooks's" is gone;
the page now links to the statements.

## Tests

`tests/statements_db.php` covers:

- rollback (budgets gone, ledger untouched);
- upgrade with its announcement;
- a silent repeat;
- records dated 2018 and 2019, years no other test uses, so the HTTP test
  can expect exact figures.

`tests/statements_http.py` covers, after typing the owner's contribution
and the office lease as journals (drafted by payroll, posted by an
administrator):

- **Income statement, March 2019:** revenue 10,500, costs 3,300, net
  7,200.
- **One project:** 10,000, 2,300 and 7,700, without the lease. A project
  that does not exist falls back to every project.
- **Balance sheet, 31 March 2019:**
  - bank 10,200, receivables 3,500, assets 13,700;
  - payables 500, owner equity 5,000;
  - earnings 1,000 from 2018 and 7,200 this year;
  - total 13,700, balanced.
- **Balance sheet, end of 2018:** balanced.
- **Cash flow, first quarter 2019:**
  - 0 to 10,200;
  - operating 8,000, −1,500 and −1,300, net 5,200;
  - financing 5,000, investing 0;
  - reconciled to the cent.
- **Cash flow, late March alone:** opens at 11,700.
- **Printing:** Letter with no browser stamp, the brand mark as an image,
  the same figures, "Not an audited statement", and all three statements
  print.
- **Budgets:**
  - four refusals;
  - three budgets set, one of them across a quarter;
  - one revision, with both versions kept;
  - no change when the same amount is saved again;
  - variance −500 (revenue short), −500 (hotels over, unfavourable) and
    +2,000 (lease under, favourable);
  - unbudgeted costs still shown;
  - the history listed.
- **Roles:** a recruiter refused.
- **Translations:** French and Spanish.

`/statements` is in the access matrix and the CSRF sweep (`budget`).

Full isolated run: **1693 PASS, 0 FAIL** (browser step skipped).

Mutation: I reversed the sign of the variance on cost accounts, so a cost over budget looked favourable. The suite failed on "Hotels: 1,500 budgeted, 2,000 spent, 500 over".

## Limits

- The statements cover what Crewvia records, plus the journals typed for
  the rest. Anything RSS records only in QuickBooks is not in them. They
  are not audited.
- The fiscal year is the calendar year. No year-end closing journal is
  posted: earnings are carried as retained earnings on the balance sheet,
  as above.
- Cash is one bank account (1000). A second bank account added to the
  chart would show as "other assets" in the cash flow, not as cash.
- No comparison with an earlier period, and no export to a spreadsheet:
  role-controlled exports and the report builder are P4-M03.

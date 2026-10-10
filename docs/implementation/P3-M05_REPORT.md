# P3-M05 — financial periods, and the trial balance of what was exported

## Scope, under the QuickBooks decision

The books are in QuickBooks Online (P3-M02). QuickBooks draws RSS's trial
balance, income statement, balance sheet and cash flow from everything RSS
records, most of which never passes through Crewvia (rent, insurance,
bank fees, payroll from ADP). Statements drawn from Crewvia's share alone
would look complete and be wrong, so **they are not built**. Budgets were
built in P3-M01. What P3-M05 adds is what the export needs to be
trustworthy:

1. **Financial periods**: a month can be closed, after which nothing new is
   dated in it.
2. **The trial balance of what Crewvia exported**, by month and account, to
   tie out with the same accounts in QuickBooks.

## What was built

**Financial periods** (`/periods`, Pay and billing; payroll reads,
administrators close and reopen).

- **Closing a month.** It must have ended, and nothing dated in it may still
  be waiting to export. A reason is required.
- **Once closed, nothing new is dated in the month:**
  - payments received or made;
  - credit notes;
  - client invoices issued;
  - vendor bills approved (dated by their due date);
  - expense claims paid;
  - QuickBooks exports carrying an entry dated in it;
  - reversals of exports, payments or credit notes dated in it.

  Each is refused with the month named; the correction goes in an open month.
- **Reopening**: administrators only, with a reason. Every close and reopen
  is kept with who and why.
- **Trial balance** of the exported entries for a month: opening, debits,
  credits, closing debit or credit per account, and the QuickBooks account
  each maps to. It counts every batch, exports and reversals, so a
  reversal dated in a later month leaves the earlier month as it was. It
  says when debits and credits differ.

Schema: `financial_periods`, `financial_period_events`. Rollback:
`install/rollback/p3-m05.sql` (every month open again; nothing else touched).

## Tests

`tests/periods_db.php` (rollback, upgrade, silent repeat) builds an invoice
and two bills in March 2023, a month no other suite touches.
`tests/periods_http.py` covers:

- the closing rules: the current month, entries still waiting, no reason,
  closing twice;
- the March trial balance after export (A/R 1,000, revenue 1,000, other
  costs 400, A/P 400, 1,400 each side);
- refusals in the closed month: a payment, a credit note, approving a bill,
  an export carrying an entry that slipped in, and a reversal;
- the same payment accepted when dated today;
- March unchanged after a reversal dated today;
- an unbalanced month flagged;
- reopening, after which the late bill can be approved, and the
  close/reopen history;
- roles and translations.

`/periods` is in the access matrix and the CSRF sweep.

**Test runner change.** The suites together now make more than the 100
sign-ins per 15 minutes per address that the login allows. The runner
clears that one counter for 127.0.0.1 on the test database before the
access matrix (`tests/reset_login_ip.php`). The product's limit is
unchanged.

Full isolated run: **1194 PASS, 0 FAIL** (browser step skipped).

Mutation: I removed the closed-month check on payments. The suite failed on
"A payment dated in the closed month is refused".

## Limits

- Payroll periods keep their own locks (P1-M06); closing a month does not
  lock its payroll weeks.
- Closing is per month; there are no quarters or fiscal years.
- An edit made directly in the database to a closed month is not caught;
  the trial balance's tie-out with QuickBooks is the check.

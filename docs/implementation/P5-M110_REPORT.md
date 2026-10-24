# P5-M110 — A worker's expenses paid back on the next pay

## The case

A worker flown in has the flight paid by the agency, but pays the checked bag at the counter with their own card. They
keep the receipt and are owed the amount on their next pay. The same goes for a taxi, a screening, a hotel night paid
at the desk.

What existed:

- `/expenses`: the worker files a claim with a receipt, and payroll approves it (never the person who filed it).
- `payroll_adjustments` of kind `reimbursement`: kept out of taxable wages and out of the ADP wage export.

What was missing was the link between the two. Nothing turned an approved claim into money on the pay. A claim could
be paid separately, typed by hand onto a pay period, and also entered on the weekly sheet's "expenses" field. So it
could be paid twice, or never. In the accounts it would also have been counted twice: once as a separate payment, and
again in the pay period's journal.

## Now

- **The worker** opens Reimbursements and fills in the claim: what it was for (**Baggage** is now a category), the
  amount, and the receipt. On a phone the receipt field offers the camera directly. A receipt already uploaded can be
  chosen instead, and one receipt backs one claim only.
- **Payroll** approves the claim, then pays it one of two ways:
  - **on the pay**: from the claim, or from the open pay period's "Reimbursements to pay" list. In one transaction,
    this creates a reimbursement adjustment tied to the claim and marks the claim paid;
  - **another way** (direct deposit, check, cash), with its reference.
- **One way, once.** The database refuses:
  - a claim paid on the pay without its adjustment;
  - a claim paid separately without a reference, or with an adjustment;
  - an adjustment for a claim that is not approved, or for another amount or another worker;
  - any change to a paid claim.
  
  `payroll_adjustments.expense_claim_id` is unique. A reimbursement typed by hand on a pay period is refused while the
  person has an approved claim waiting.
- **Both ways round.**
  - The pay period's adjustment links to its claim and receipt.
  - The claim says "Paid on the pay of the week ending …" and links to the period.
  - The worker's payslip names the claim ("Reimbursement: Baggage (claim #12)").
  - The worker is notified.
- **Accounts.**
  - A claim paid on the pay is posted once, in the pay period's journal, and booked to the claim's project.
  - A claim paid another way is posted as before.
  - Project costing counts the claim by category, as before. Baggage counts as transportation.
- **Payroll sees every project** on Reimbursements, as on Pay periods. Claims waiting for a decision come first.
- **System health** warns about paid claims that break the rule. The database prevents this from now on; the check
  finds rows from before.

Not changed, to decide with Firmin: the "expenses" field of the weekly sheet (Hours) remains a separate way to pay an
amount. Should it go, now that claims are paid on the pay?

Next, to decide with Firmin: **reading the receipt automatically**. The amount can be read on the phone (free, nothing
leaves the phone, weak on a creased receipt) or by an OCR service (reliable, a few cents a receipt, but the receipt
leaves the system, and it can show the card's last four digits and the name). Either way, the amount read is only a
suggestion that the worker confirms, and approval stays with a person.

## The demonstration flush keeps what was written about real people

The second dry run of `demo-flush` on production still refused, this time over 1,487 rows: notes, calls, and who
opened a real candidate's bank details or tax form. Every one was written by the account "Owner Number 1" (#9). That
account is a demonstration account, used by Fleury to work on the real applicants. The rows are those people's records
and their audit trail.

- A row about a real person stays, even when it is reachable from the demonstration only through who wrote it (its
  `user_id`). A real person here means the row's candidate, or the candidate of its application or placement.
- The account that wrote such a row is not removed. The flush retires it:
  - its name stays readable, with "(demo, retired)" added;
  - it can no longer sign in;
  - its email address becomes `retired-demo-<id>@retired.invalid`, so it receives no mail.
- `demo-flush` (dry run) names each account it will retire.

Account #9 is still used for filming. It is retired only when the flush is run with `--execute`.

## Migration

`install/upgrade.php`:

- `payroll_adjustments.expense_claim_id` (unique);
- `expense_claims.category` gains `baggage`;
- `expense_claims.payment_method` gains `payroll`;
- triggers `expense_claims_paid_one_way` and `payroll_adjustments_claim_approved`.

Rollback: `install/rollback/p5-m110.sql`.

## Tests

`tests/reimbursements_http.py` (19 checks) follows the worker's photo, the review, the refusals, the payment on the
pay, the links, the payslip, the notice and the accounts.

`tests/demo_separate_db.php` (now 18 checks) adds:

- a demonstration account's notes and calls about a real person: kept;
- its notes about the demonstration: removed;
- a real flush: the demonstration is gone, and the real records and trail remain;
- the account retired.

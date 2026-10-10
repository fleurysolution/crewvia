# P3-M04 — client and vendor balances, aging, credits, reconciliation, payment allocation

P3-M03 (a native ledger) was skipped by decision: the books are in
QuickBooks Online (P3-M02).

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Client invoices: draft → issued → paid, void | existed; **paid was a status flip**, no payment, no due date | `client_invoices`, `/client-invoices` |
| Vendor bills: received → approved → paid, with method and reference | existed; **paid in full only** | `vendor_invoices`, `/accounts-payable` |
| Part payments, one payment covering several invoices, money on account | **missing** | — |
| Credit notes | **missing** | — |
| Balances by client or vendor, aging | **missing** | — |
| Client payment terms | **missing** | — |

## What was built

**Receivables and payables** (`/balances`, Pay and billing; payroll and
administrators). One set of rules for clients and vendors (`app/balances.php`).

- **Payments are recorded once** (date, amount, how, reference) and applied
  to one or more of the same party's invoices. A payment is refused, all or
  nothing, if:
  - an application exceeds the payment or what the invoice still asks;
  - it reaches another party's invoice, or a bill not yet approved;
  - the reference is already used for that party;
  - it is dated in the future.
- **On account**: what is not applied stays on the payment, and can be
  applied later.
- **Credit notes** lower what an invoice asks. Administrators only, with a
  reason, never above what is left.
- **Status follows the balance**: nothing left, the invoice is paid (dated
  by its last payment); something left again after a reversal, it is
  issued again (approved, for a bill). An invoice marked paid before this
  module is *settled*: it asks nothing and takes nothing.
- **Nothing is deleted.** An application is taken back, a payment reversed
  (all its applications with it), a credit note reversed, each with a
  reason, and all stay.
- **Aging** by party: not yet due, 1–30, 31–60, 61–90, over 90 days past
  due, plus money on account and the net. Client invoices get a due date
  when issued, from the client's payment terms (30 days unless an
  administrator changes them). Invoices already out were given issue + 30
  days by the upgrade.
- **Statement** per client or vendor: invoices, credit notes, payments and
  reversals in date order, with the running balance.
- **To reconcile**: invoices whose status does not match their balance,
  anything paid or credited above what it asks, and payments on account for
  over 30 days.
- **The existing buttons now record payments.** *Mark payment received* on
  client invoices asks for a method and reference, and records a payment of
  the open balance. *Pay* on the bill register does the same. Part
  payments are made on the new page.
- **QuickBooks** (P3-M02) gains entries for:
  - payments received (Dr bank, Cr A/R);
  - payments made (Dr A/P, Cr bank);
  - client credit notes (Dr revenue, Cr A/R);
  - vendor credits (Dr A/P, Cr the cost);
  - the reversal of any of these, posted the other way.

  An invoice settled through recorded payments no longer sends the old
  single payment entry, so nothing is counted twice. Invoices settled
  before this module still send theirs.

Schema: `ar_payments`, `ar_allocations`, `ar_credits`, `ap_payments`,
`ap_allocations`, `ap_credits`; `clients.payment_terms_days`,
`client_invoices.due_on`. Rollback: `install/rollback/p3-m04.sql` (invoice
statuses stay).

## Tests

`tests/balances_db.php` (rollback keeping statuses, upgrade dating invoices
already out, silent repeat) and `tests/balances_http.py` (70 checks). They
cover:

- aging into the right buckets (3,500: 1,000 / 2,000 / 500, without the
  settled, draft or other client's invoices);
- a 2,600 check applied 2,000 + 500 with 100 on account;
- five refusals that record nothing;
- applying the money on account;
- the credit-note rules (150 for two days billed twice);
- taking back and reversing (a bounced check);
- statuses following the balance, and the statement's running balance
  matching the aging (3,350);
- the reconciliation flags;
- payment terms (45 days giving the due date);
- both old buttons recording payments;
- vendor part payment, credit and its reversal;
- the QuickBooks lines for each, every journal balanced, and no duplicate
  payment entry.

`/balances` is in the access matrix (payroll-only list) and the CSRF sweep.

Full isolated run: **1156 PASS, 0 FAIL** (browser step skipped).

Mutation: I let an application exceed what the invoice still asks. The
suite failed on that refusal.

## Limits

- Vendors are known by the name on their bills; two spellings are two vendors.
- Aging is as of today; no aging at a past date.
- Bank reconciliation (matching to bank statements) stays in QuickBooks.
- Payments are not split by project in the QuickBooks entry; the invoices
  they settle carry the project.

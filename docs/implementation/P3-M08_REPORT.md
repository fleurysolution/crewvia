# P3-M08 — receiving, three-way matching, purchasing exceptions, payment readiness

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Receiving: quantity and date against an approved order, never beyond the order | existed (R45) | `purchase_receipts`, `/procurement` |
| Bills linked to an order | existed | `vendor_invoices.purchase_order_id` |
| Bill approval, then payment (now through recorded payments, P3-M04) | existed | `/accounts-payable`, `/balances` |
| Recording what arrived and was refused | **missing** | — |
| Checking a bill against its order and what arrived | **missing**: an approved bill was payable whatever it said | — |
| Exceptions and who lets them through | **missing** | — |

## What was built

**Receiving with rejections.**

- A receipt now records what was accepted and what was refused (damaged,
  wrong item), with the reason. A delivery that is only rejections is
  recorded too.
- Refused items are never counted as received: they do not fill the order,
  and they are not billable.

**Three-way matching** (`app/matching.php`). Each bill against an order is
checked against the order and against the value of what was accepted. An
exception is raised when:

| Code | When |
|---|---|
| `order_not_authorised` | the order is rejected, cancelled, or a revision is waiting for approval (P3-M07) |
| `vendor_mismatch` | the bill's vendor is not the order's |
| `over_order` | the order's bills, up to this one, total more than the order |
| `not_received` | nothing has been accepted against the order |
| `over_receipt` | the order's bills, up to this one, total more than what was accepted is worth |
| `price_variance` | the bill states a quantity, and its unit price is not the order's |

- Bills are counted in the order they were entered, so an earlier bill is
  never blamed for a later one.
- Differences within the tolerance are not exceptions. The tolerance is a
  percentage or an amount, whichever is more: 2% or $10 by default, set by
  administrators.

**Exceptions are cleared by an administrator, with a reason, for exactly
the exceptions shown.** If they change (another bill, a receipt, a
revision, a new tolerance), the clearance no longer counts and the bill is
held again.

**Payment readiness.** A bill is ready to pay when it is approved and either
matches or has its exceptions cleared. Payments are refused otherwise, with
the reason, wherever they are made: *Pay* on the bill register, recording a
payment under Payables, or applying money on account. All three go through
one check.

**Bills with no order** (utilities, one-off services) are checked by their
approval alone, as before. They are marked *No order: approval only*.

**Screens.**

- **Bill matching** (`/bill-matching`, Pay and billing): exceptions to
  clear, open bills with ordered, received and billed figures, what is
  ready to pay and its total, and the tolerance setting.
- **The bill register** shows each bill's match. Bills can state the
  quantity they charge for.

Schema: `bill_match_clearances`; `purchase_receipts.rejected_quantity`,
`rejection_reason`; `vendor_invoices.quantity`; two tolerance settings.
Rollback: `install/rollback/p3-m08.sql` (bills are then paid on approval
alone).

## Tests

`tests/matching_db.php` (rollback, upgrade counting open bills now checked,
silent repeat) and `tests/matching_http.py`. They cover:

- receiving: a rejection needs a reason, 6 accepted and 2 rejected, a
  rejections-only delivery, refused items not counting toward the order;
- a matched bill paid;
- a 250 bill beyond the order and receipt refused on the register and under
  Payables, then cleared by an administrator and paid;
- a price difference;
- a clearance that stops counting when the tolerance changes the exceptions;
- vendor mismatch;
- not received, then matched on arrival;
- held while an order revision waits, matched once it is approved;
- a bill with no order ready on approval;
- the register's markers, roles, refusals and translations.

`/bill-matching` is in the access matrix and the CSRF sweep.

Full isolated run: **1334 PASS, 0 FAIL** (browser step skipped).

Mutation: I let any earlier clearance count whatever the current
exceptions. The suite failed on "At 20 % the exceptions change: the
clearance no longer counts".

## Limits

- Matching is by amount, and by unit price when the bill states a quantity;
  bills have no line items.
- Rejected items are recorded, not returned through a vendor credit; a
  credit note is entered under Payables (P3-M04).
- Lodging is matched on rooms confirmed × price × nights, as ordered.

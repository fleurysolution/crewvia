# P3-M07 — requests for quotation, supplier quotations, order revisions and authorization

Decision: **no quotation comparison** (R43, no bid analysis). Quotations are
recorded and listed in the order they arrived, never ranked or compared;
nothing here picks a winner.

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Quotations | a vendor and price typed onto a request; **listed cheapest first, and the cheapest pre-filled the order form** | `purchase_quotations`, `/procurement` |
| Asking vendors to quote | **missing** | — |
| Purchase orders with approval by tier, never one person twice | existed (P3-M06) | `purchase_orders`, `purchase_order_approvals` |
| Changing an order once raised | **missing**: a wrong order could only be rejected or cancelled | — |
| A printable order or request | **missing** | — |

## What was built

**Requests for quotation** (on each open request in `/procurement`;
logistics and payroll).

- Send to one or more vendors, each approved for the category, with a
  reply-by date. Each vendor gets its own request with its own reference
  (`RFQ-YYYYMM-NNN`). A vendor still to answer cannot be asked twice.
- Each request prints as a Letter page with the brand logo, saying it is a
  request for a price, not an order. Outbound email stays disabled, so it
  is sent by hand.
- **Answers**: the vendor's unit price, and how long it holds, become a
  quotation linked to its request. Otherwise the request is marked declined
  (with the reason) or withdrawn.

**Quotations, not compared.**

- They are listed as they came, with their validity.
- The order form is no longer pre-filled with the cheapest.
- *Order on this quote* raises the order with that quotation's vendor and
  price, and records which quotation it came from. An expired quotation is
  refused.
- The approved-vendor checks of P3-M06 still apply.

**Revisions.** An order waiting for approval, or approved, is revised with
a reason: its quantity, its unit price, and its split across projects
(kept, scaled to the new total, unless new shares are given).

- A revision **never** asks for fewer than already arrived, nor totals less
  than already invoiced. A revision that changes nothing is refused.
- Each revision keeps the order as it was and as it became (quantity,
  price, total, shares, approval rule, status), with the reason and who
  made it. The order shows them all.
- **It is authorized again.** It returns to *awaiting approval* under the
  tier of its new total and the budget check of its new shares (P3-M06).
  Approvals given to an earlier revision stay on record and do not count.
  Nothing is received while it waits.

**The printed order** shows the revision, the total, the projects charged
and who authorized it. Until it is approved, it prints *NOT AUTHORISED — do
not send to the vendor*.

**Test runner**: the count of sign-ins per address is now cleared before
every HTTP suite, not only before the access matrix. The product's limit is
unchanged.

Schema: `purchase_rfqs`, `purchase_order_revisions`;
`purchase_quotations.rfq_id`, `valid_until`; `purchase_orders.revision`,
`quotation_id`; `purchase_order_approvals.revision`. The unique key becomes
one approval per role per revision. Rollback: `install/rollback/p3-m07.sql`
(puts the old key back). Procurement's rollback drops the two new tables
first.

## Tests

`tests/rfq_db.php` (rollback restoring the key, upgrade, silent repeat) and
`tests/rfq_http.py`. They cover:

- RFQ refusals (none chosen, wrong category, unapproved vendor, past date),
  two RFQs with their own references, no second ask, the printout;
- answers: validity, answered once, a decline needs a reason;
- quotations listed in arrival order, cheaper second, and no pre-fill;
- an expired quotation refused, an order raised on Alpha's 12 (240);
- revision refusals (no reason, below received, no change);
- revision 1 to 360 needing the budget owner again, its before/after
  record, nothing received while waiting, both approvals kept per revision;
- below-invoiced refused, revision 2 at 6,000 going to an administrator;
- the printout authorized, then *NOT AUTHORISED* after revision 3;
- the revision list, another project's document refused, translations.

Full isolated run: **1295 PASS, 0 FAIL** (browser step skipped).

Mutation: I let approvals of an earlier revision count. The suite failed on
"The approval of revision 0 does not count for revision 1".

## Limits

- Requests for quotation are printed and sent by hand (outbound email
  disabled).
- The vendor of an order is not revised: a different vendor is a new order.
- Lodging orders keep the rooms already confirmed on the hotel board when
  revised.

# P3-M06 — approved vendors, approval thresholds, project purchasing allocations

Decision: **the quotation comparison of P3-M07 stays out** (R43, no bid
analysis). Quotations remain notes on a request.

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Purchase requisitions: request → order → receipt | existed (R41–R45) | `purchase_requests`, `purchase_orders`, `/procurement` |
| Approval by the project's budget owner (or an administrator), never the author | existed (R44) | `procurement_can_decide()` |
| Vendors | **a name typed on each order and bill**, no list, no approval | — |
| Approval by order size | **missing**: one approval whatever the amount | — |
| Budget check at ordering | **missing** | — |
| One order for several projects | **missing**: an order belonged to one project | — |

## What was built

**Vendors** (`/vendors`, Deployment menu; logistics and payroll propose,
administrators decide).

- A vendor has a name, what it supplies (lodging, vehicles, safety
  equipment, other), whether its W-9 is on file, and its insurance expiry.
  Optionally, a contact and a note.
- Proposed vendors wait for approval by an administrator other than the
  proposer. An approved vendor can be suspended and reinstated, each with a
  reason, and every step is in its history.
- **An order is refused** unless its vendor is registered and approved,
  approved for the category bought, has a W-9 on file, and is insured on
  the day. Each refusal says which of these failed.
- The upgrade carries over every vendor already on an order, a bill or the
  hotel board, as approved, with a note to check the W-9 and insurance.

**Who approves an order**: tiers by total, set by administrators.

- The defaults are: up to 5,000 the budget owner; up to 25,000 an
  administrator; above that, both.
- When both are needed, two different people approve: one person never
  gives both approvals. As before, an administrator may stand in for the
  budget owner, and the author never approves.
- An order that takes any project it is charged to past that project's
  budget line (P3-M01: budget less spent less ordered and not invoiced)
  also needs an administrator.
- The rule is fixed when the order is raised. The order list shows the
  approvals given and who it still waits for.

**Split across projects**: an order can be shared between projects, and the
shares must add up to its total. Its invoice then counts on each project's
costs by share (P3-M01), and so do its commitments. In QuickBooks, the
bill's cost goes to each project's Class by share (P3-M02). Every existing
order was given one share: its own project.

**Also fixed**: on a phone, the wrapped header with its menus open was
pinned and covered the screen; under 720px it now scrolls away.

Schema: `vendors`, `vendor_events`, `procurement_thresholds`,
`purchase_order_approvals`, `purchase_order_allocations`;
`purchase_orders.approvers`, `over_budget`. Rollback:
`install/rollback/p3-m06.sql`. Procurement's own rollback now drops the two
tables that depend on orders first.

## Tests

`tests/vendors_db.php` (rollback, upgrade with carry-over, the default
tiers, every order allocated, silent repeat) and `tests/vendors_http.py`.
They cover:

- vendor rules: roles, refusals, the proposer unable to approve;
- order refusals: a pending, unregistered, uninsured or W-9-less vendor,
  and the wrong category;
- the tiers: 800 to the budget owner, 6,000 to an administrator, 30,000 to
  two people;
- an over-budget order needing a second approval, and the same person
  refused twice;
- a 1,000 order split 600/400 with its invoice costing 600 and 400 on the
  two projects and going to two Classes in QuickBooks;
- suspend and reinstate;
- tier changes and their refusals;
- translations.

The procurement suite's vendors are now registered in its fixture.

Full isolated run: **1248 PASS, 0 FAIL** (browser step skipped).

Mutation: I let one person give both approvals. The suite failed on "The
same administrator cannot give the second approval".

## Limits

- Vendors are matched by name; a renamed vendor's old orders keep the old
  name.
- Bills entered in payables are not required to come from an approved
  vendor; only purchase orders are.
- A supervisor cannot switch the working project; they act on the newest
  active one, as before this module.

# Assets — categories, status, condition, repairs, inspections, history

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| An item with a name, a unique tag, an owner (agency or client) and a serial | existed | `equipment`, `equipment_ownership` |
| Issue to a worker on the project, one holder at a time | existed | `equipment_issues`, Operations |
| A client's item only on that client's project | existed | Operations |
| Closing an assignment blocked while an item is out | existed | Operations, offboarding |
| Return with a free-text note | existed | Operations |
| Category, status, purchase, inspection date | **missing** | — |
| Condition at issue and at return, who issued and who took back | **missing** | — |
| Repairs, loss, retirement | **missing**: a lost item stayed issuable | — |
| A register of every item and its history | **missing**: only the project's outstanding list | — |
| Items from a purchase order | **missing** | — |

## What was built

- **One set of rules** (`app/assets.php`). Operations and the new register
  both call it, so an item is issued and taken back the same way everywhere:
  - only an *available* item is issued; one in repair, lost or retired is not;
  - an item past its inspection date is not issued;
  - a client's item only on that client's project (unchanged);
  - *issued* is not stored: it is an open line in `equipment_issues`, so it
    cannot drift from who holds the item.
- **Issue and return with a condition** (Operations): good, worn or damaged
  out; good, worn, damaged or lost back. Damaged or lost needs a note.
  Returned damaged, the item goes to repair; returned lost, it is marked lost.
  Who issued and who took it back are kept. The issue list now shows only what
  can be issued.
- **The register** (`/assets`, Deployment menu): counts by state, filters by
  state, category and name/tag/serial, and each item's page with who had it
  and its full history.
  - Recruiters see it. Logistics (`hotels`) and administrators register items
    and record: inspected, sent for repair, back from repair (with its cost),
    lost, retired, found or put back in service. Repair, loss and retirement
    need a reason. Which move is allowed from which state is listed once.
  - An inspection sets the next one from the category (protective equipment
    365 days, fall protection 180, gas detection 30) or from a date given.
- **Register what an order delivered**: from an approved equipment order (not
  lodging), up to the quantity received and not yet registered, each item
  tagged with a prefix and a number and costed at the order's unit price.

Schema: `asset_categories`, `asset_events`; on `equipment` category, status,
purchase date, cost and order, inspection date, notes; on `equipment_issues`
the two conditions and who issued and received. Existing items start
*available*, uncategorised. Rollback: `install/rollback/assets.sql` (keeps
every item and issue).

## Tests

`tests/assets_db.php` (rollback, upgrade, silent repeat, seeded categories)
and `tests/assets_http.py` (7 and 42 checks). They cover the role limits, register
refusals, registration from an order (lodging refused, more than received
refused, numbering and cost), the client and inspection refusals, condition
and holder at issue, return damaged and lost, repair with its cost,
restore and retire, inspections, the register filters, the order of an item's
history, and French and Spanish. `/assets` was added to the access matrix
(forbidden to payroll, supervisor, worker and client) and to the CSRF sweep,
with Operations' issue form.

Full isolated run: **934 PASS, 0 FAIL** (browser step skipped).
Mutation: removing the overdue-inspection refusal made the suite fail on
"An item past its inspection is refused".

## Limits

- No depreciation or book value; the purchase cost and repair costs are kept,
  nothing is computed from them.
- Consumables (gloves, ear plugs) are not tracked by item; they stay on the
  purchase order.
- No reminder is sent before an inspection falls due; the register shows the
  overdue count.

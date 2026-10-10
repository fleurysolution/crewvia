# P3-M01 — project costs, burden, revenue, overtime, profitability, budget against actual

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Revenue and labour per week, frozen at approval (hours, overtime hours, labour cost, per diem, expenses, bill total) | existed | `pay_snapshots`, `week_money()` |
| Employer contributions per week | existed (P1-M05) | snapshot `gross_to_net.total_employer` |
| Corrections paid after approval | existed (P1-M06) | `payroll_adjustments` |
| Hotel nights and rates | existed | `lodging` |
| Travel costs | existed | `travel.cost` |
| Agency-paid claims | existed | `expense_claims` |
| Vendor invoices, linked to purchase orders | existed (procurement) | `vendor_invoices`, `purchase_orders` |
| Client invoices | existed | `client_invoices` |
| A margin view: pay against billing, lodging and travel shown apart | existed, partial | Billing |
| Burden rate, overhead allocation | **missing** | — |
| Costs by category, one figure per project | **missing** | — |
| Budget, and budget against actual | **missing** | — |

## What was built

**Project costs** (`/project-costs`, under Pay and billing; payroll and
administrators). It covers the selected project from the start to today.

The report only reads: it creates, posts and changes no financial record.
The test checks this by counting nine record tables before and after the
report is opened four times. **Each cost comes from exactly one source:**

| Line | Taken from | Shown beside it, never added |
|---|---|---|
| Revenue | approved/paid weeks: hours worked × bill rate | client invoices issued or paid |
| Labour | those weeks' wages and paid leave, plus payroll corrections against them; overtime shown as hours and cost | — |
| Burden | employer contributions frozen with each week, plus the project's burden rate on wages (employer taxes and insurance ADP charges) | — |
| Per diem and expenses | the weeks' per diem and expenses line, plus reimbursements paid in a pay period | — |
| Hotels | nights booked × nightly rate, plus agency-paid hotel claims | hotel invoices and lodging-order invoices (they bill the same nights) |
| Transportation | travel bookings, agency-paid flight and transport claims, invoices on vehicle orders | — |
| Equipment | invoices on safety-equipment orders | — |
| Other | other agency-paid claims, invoices on other orders or none | — |
| Overhead | the project's overhead rate on revenue | — |

Not counted: weeks not yet approved, cancelled bookings, claims the client
pays or that are not approved, vendor invoices not approved, void client
invoices. Approved orders not yet invoiced show as *ordered and not yet
invoiced*.

From these: direct cost, gross margin, margin after overhead (amounts and
percentages), budget against actual per line (variance, % used, red when
over a cost budget or under the revenue budget), and week by week.

**Budget** (administrators): one amount per line for the whole project.
Changes are appended, never overwritten. The current figure is the latest
change, and each change keeps the amount it replaced, the reason, who
and when. **Burden and overhead rates** are set per project by
administrators; unset, the report says *not set* and estimates nothing.

Schema: `project_budget_changes`; `jobs.burden_percent`,
`jobs.overhead_percent`. Rollback: `install/rollback/p3-m01.sql`.

## Tests

`tests/costing_db.php` (rollback, upgrade, silent repeat) builds one project
with a record of every kind, including the ones that must not count.
`tests/costing_http.py` checks each figure against hand arithmetic:

- revenue 8,075; labour 2,700; per diem and expenses 350; hotels 640;
  transportation 1,450; equipment 300; other 90;
- 5 overtime hours costing 225; 200 still committed; 8,000 and 840 to
  reconcile;
- with 10% burden and 5% overhead: burden 312.50, overhead 403.75, direct
  cost 5,842.50, gross margin 2,232.50 (27.6%), after overhead 1,828.75
  (22.6%);
- the budget refusals; labour 200 over (108% used) and revenue 75 above;
  the change to 3,000 kept with what it replaced;
- the roles, and the read-only count.

`/project-costs` is in the access matrix (payroll-only list) and the CSRF
sweep.

Full isolated run: **1046 PASS, 0 FAIL** (browser step skipped).

Mutation: I made hotel invoices count as a cost too, which counts the same
nights twice. The suite failed on the hotels figure.

## Limits

- Project to date only; no date range yet. The week-by-week table covers the
  last 26 approved weeks.
- Burden is an estimate from a rate: employer taxes stay with ADP and are
  not imported.
- Vehicles have no daily cost in Crewvia; a rented vehicle counts through
  its order's invoice. Equipment repairs are not charged to a project.
- No posting to a ledger: that is P3-M02/M03, and needs the architecture
  decision on external against native accounting.

# P4-M02 — dashboards: recruitment, placement, utilization, revenue, profitability, finance, procurement

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Applications with stages and a dated history of each move | existed | `applications`, `application_events`, `/recruitment` |
| Scope of work (positions per line), placements with status and dates | existed | `job_order_lines`, `placements` |
| Approved weekly sheets with hours and paid leave | existed | `timesheets` |
| Per-project costing: revenue, labour with burden, purchases, hotels, claims, overhead, budget, commitments | existed (P3-M01) | `app/project-costing.php`, `/project-costs` |
| Ledger, statements, receivable and payable ageing | existed (P3-M03, P3-M04, P3-M05) | `/ledger`, `/statements`, `/balances` |
| Orders, approvals, receipts, rejections, bills and their matching | existed (P3-M06 to P3-M08) | `/procurement`, `/bill-matching` |
| Conversion, placement rates, utilization, revenue by client, profitability across projects, a financial and a procurement dashboard | **missing** | — |

Everything the dashboards measure was already recorded, so P4-M02 adds no
table and no migration. Its rollback is to deploy the code before it.

## What was built

**Dashboards** (`/analytics`, `app/analytics.php`) open with headline
figures, followed by tables. They are read each time from the records,
and from the ledger after it catches up; nothing is stored. Every
dashboard opened is logged with its filters.

| Dashboard | Read by | What it measures |
|---|---|---|
| Recruitment conversion | recruiting | applications made in a period; how many reached screening, interview, offer, acceptance (each against applications and against the stage before); rejected, withdrawn, still open; median days to acceptance; by source and by project |
| Placement rates | recruiting | per project: positions ordered (scope lines, or the headcount target), on assignment at the end of the period, fill rate, still to fill; assignments offered in the period, taken up, fell through, waiting; take-up rate |
| Worker utilization | recruiting, payroll | per assignment: weeks it ran in the period, hours expected (its guarantee, or 40 a week), hours worked on approved sheets, paid leave, utilization; who is available with no assignment |
| Client revenue | payroll | per client, from the ledger: revenue net of credit notes, share, cash received, receivables at the end of the period, overdue today, days of sales outstanding |
| Project profitability | payroll | per project to date, computed exactly as Project costs computes it: revenue, direct cost, gross margin and %, overhead, net and %, budgeted revenue and cost, committed and not billed; projects losing money |
| Financial dashboard | payroll | at a date: cash, revenue and net for the month and the year, receivables and payables with what is overdue, the last twelve months (revenue, expenses, net, cash at each month's end) |
| Procurement dashboard | hotel desk, payroll | requests waiting for an order, orders and value waiting for approval, approved in the period with average days to approve, delivered share, quantity rejected, billed, bills on hold; by category and by vendor |

Administrators open all seven. Supervisors, workers and clients open
none.

The measures reuse the functions behind the pages they summarize:

- `project_costs` and `project_profit` for profitability;
- `bal_aging` for what is overdue;
- `stmt_income` and the ledger for the financial dashboard;
- `bill_match` for the bills on hold.

A dashboard therefore never disagrees with the page it summarizes.

One subtlety was caught while testing. Days to acceptance are counted
between calendar dates, because the clock change of 13 March 2016 would
otherwise have shaved a day off a twenty-day wait.

## Tests

`tests/analytics_db.php` builds a fixture dated 2016, a year no other test
uses, on four projects of its own:

- seven applications with their histories;
- a scope of four positions and five assignments in every state;
- approved sheets;
- two clients' invoices, a credit note, a payment, a hotel bill paid in
  part;
- a project with costs, a budget and an open order;
- three orders with receipts, a rejection and a bill over its order.

Every figure was first checked by calling `analytics_run` on a copy of the
fixture, then written into the test.

`tests/analytics_http.py` checks:

- **Access:** each role lists exactly its dashboards; six refusals; an
  unknown dashboard is not found.
- **Recruitment:**
  - 6 applications, 2 accepted, 33.3 %, median 15 days;
  - the funnel 6, 5, 4, 3, 2 and its stage-to-stage rates;
  - by source;
  - 7 applications without the project filter, and none in February.
- **Placement:**
  - 4 ordered, 2 filled, 50 %;
  - 4 offered: 2 taken up, 1 fell through, 1 waiting, 66.7 % take-up.
- **Utilization:**
  - 150 of 200 hours (75 %) and 100 of 150 (66.7 %), 71.4 % in all;
  - assignments not yet started, cancelled or only offered left out;
  - the bench.
- **Revenue:**
  - 9,000 after the credit note (64.3 %), 6,000 received, 3,000 open and
    overdue;
  - 5,000 for the second client;
  - the hotel vendor not taken for a client;
  - 17.1 days of sales outstanding.
- **Profitability:** 1,000 of direct cost and 1,000 lost on nothing
  earned, the budget, 700 committed.
- **Financial:**
  - 4,100 of cash;
  - 14,000 revenue and 12,000 net in April, 11,000 net for the year;
  - twelve months, with February to April as recorded.
- **Procurement:**
  - 1 request waiting, 250 waiting for approval;
  - 1,300 approved in 3 days on average;
  - 80 % delivered, 1 unit rejected, 900 billed, 1 bill on hold;
  - by category and by vendor.
- **Logging:** each view logged.
- **Translations:** French and Spanish.

`/analytics` is in the access matrix, which checks that supervisors,
workers and clients cannot open it. It has no form, so nothing to add to
the CSRF sweep.

Full isolated run: **1805 PASS, 0 FAIL** (browser step skipped).

Mutation: I made utilization ignore the assignment guarantee and always expect 40 hours a week. The suite failed on "The new starter on a 50-hour guarantee" (120 hours expected instead of 150).

## Limits

- On screen only. Exports, saved views and scheduled delivery are P4-M03.
- **Placement and utilization:** utilization counts approved sheets only.
  A week not yet approved shows as unused.
- **Expected hours:** where an assignment has no guarantee, 40 hours a
  week is assumed, and the table says so.
- **Profitability** is to date, not for a period, as on the Project costs
  page.
- **Revenue** is per client name as the ledger carries it, so a client
  renamed after posting shows under both names.
- **Overdue** amounts are as of today, whatever the period chosen, because
  the ageing is.
- **Procurement** counts an order split across projects on the project
  that raised it; the Project costs page splits it by share.

# P4-M01 — workforce and payroll reports

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Project overview: on assignment, present, absent and on leave today, two daily series | existed (P1) | `app/workforce-overview.php`, project home |
| Executive overview across projects (people, weekly pay and bill) | existed, admin | `/overview` |
| Per-record pages: time off, attendance, pay periods, advances, benefits, appraisals and goals | existed (P1, P2) | each module |
| One place with reports across people and projects, by date, by project, on paper | **missing** | — |

Every figure the reports need was already recorded, so P4-M01 adds no
table and no migration. Its rollback is to deploy the code before it.

## What was built

**Reports** (`/reports`, `app/reports.php`) list the reports the person
may read. Each report has filters (a period, a date, a year, a project,
depending on the report), totals, notes on what it counts, and a print
version. The figures are read from the records each time and never
stored.

| Report | Read by | What it gives |
|---|---|---|
| Employees | recruiting, payroll | employee number, kind of employment, trade, availability, current or last assignment, state, start, rehire status (no pay) |
| Headcount | recruiting, payroll | per project: on assignment at the start and end of the period, started, ended, change |
| Attendance | recruiting, payroll | per person and project: days present and absent on the roll call, attendance rate, approved leave days |
| Leave | recruiting, payroll | per person and kind of leave for a year: allowed, taken, left, requests waiting (the time-off page's own balance) |
| Salaries and rates | payroll | rate or salary in force on a date (after dated changes), bill rate, margin, grade, outside its band |
| Deductions and contributions | payroll | per pay item, from the frozen pay of approved weeks: people, weeks, amount; also what was not taken for lack of pay |
| Payroll | payroll | per pay period: gross, deductions, employer cost, reimbursed, net before tax, adjustments, payable, sheets paid |
| Advances and loans | payroll | amount, repaid, balance, behind its schedule, written off |
| Benefits | payroll | per plan on a date: covered, waived, fixed cost to the person and to the agency |
| Performance | recruiting | reviews approved in a period, latest score, grade, would rehire, goals open, achieved and missed, development actions open and overdue |

Who reads which report follows the pages the figures come from:

- Pay, deductions, loans and benefits belong to payroll.
- Reviews belong to recruiting.
- The rest belongs to both desks.
- Administrators read everything.
- The hotel desk, supervisors, workers and clients read none.

Every report opened or printed is logged with its filters.

The figures are before tax: Crewvia does not calculate taxes, ADP does.
The payroll and deductions reports say so.

**Printing.**

- Letter, turned sideways when the report has more than six columns.
- Black on white, with no browser stamp.
- The brand mark as an image.
- The scope (dates, project) at the top.
- A footer with who printed it, when, and "Confidential".

## Tests

`tests/reports_db.php` builds a fixture dated 2017, a year no other test
uses, on two projects of its own:

- four people;
- a raise, a grade with a band;
- roll-call marks, leave, three frozen pay weeks in two pay periods;
- an advance, a written-off loan and an unapproved request;
- a benefit plan with one person covered, one who waived and one whose
  cover ended;
- a review, goals and a development action.

The figures were first checked against `report_run` directly, then
written into the test.

`tests/reports_http.py` checks each report's exact rows and totals:

- **Employees:** the three on the project, Dee only without the project
  filter, no pay column.
- **Headcount:** 3 at the start → +1 −1 → 2 at the end; the other project
  holds 1.
- **Attendance:** 3 present and 1 absent for 75 %, 3 leave days; 80 % in
  all.
- **Leave:** 10 allowed, 3 taken, 7 left; one request waiting; nothing in
  2016.
- **Salaries and rates:**
  - 32 after the raise, 30 before it;
  - Cal under his band;
  - Ben's salary of 2,000 while he was there.
- **Deductions:** union 60 over 3 weeks and 2 people; match 15; the 5 not
  taken; nothing on the other project.
- **Payroll:**
  - week ending 11 February: 2,310 payable, 1 of 2 sheets paid;
  - week ending 18 February: 1,180;
  - the month: gross 3,400, payable 3,490.
- **Advances and loans:** 400 left and 400 behind; the loan written off
  for 300; the unapproved request left out.
- **Benefits:** 1 covered and 1 waived at the month's end; 2 covered on
  5 February.
- **Performance:** 85 %, B, would rehire; goals and an overdue action;
  Cal's missed goal; nothing approved in March.
- **Roles:** what each role may list and open (the hotel desk none;
  recruiting refused the five payroll reports; payroll refused
  performance); an unknown report is not found.
- **Printing:** landscape for a wide report, upright for a narrow one, the
  brand mark, the same figures, "Confidential".
- **Logging:** the view and the print are both logged with their filters.
- **Translations:** French and Spanish.

`/reports` is in the access matrix, which checks that the hotel desk,
supervisors, workers and clients cannot open it. It has no form, so
nothing to add to the CSRF sweep.

Full isolated run: **1753 PASS, 0 FAIL** (browser step skipped).

Mutation: I opened the salary report to recruiting. The suite failed on "Recruiting sees employees, headcount, attendance, leave and performance".

## Limits

- No spreadsheet export and no saved or scheduled reports: those are
  P4-M03 (report builder, role-controlled exports, scheduling, secure
  delivery).
- Project-level P4-M02 measures (recruitment conversion, placement rates,
  utilization, client revenue, profitability) are not here.
- Payroll is by pay period across every project, as pay periods are.
  The deductions report can be narrowed to a project.
- Attendance counts only days marked on the roll call. A day nobody marked
  counts neither way, and the report says so.
- Leave is in days, as the time-off page counts them, both ends included.

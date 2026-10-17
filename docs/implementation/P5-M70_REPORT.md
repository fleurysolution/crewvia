# P5-M70 — what the client is billed, and the site position from the start

The owner, 17 Oct 2026, during the rehearsal:
"Lodging, travel… the owner must be able to bill that to the client… shift
equal value." Then: "shift one day is paid and billable. ABC should be
configurable."

The invoice used to be hours worked × the bill rate, nothing else.
Now each project carries **What the client is billed** (Projects → the plant,
`#billing-terms`, before the agreement form):

- **Overtime**: the hours the pay side already counts as overtime, billed at
  the bill rate × a multiplier, at the bill rate (no premium), or at its own
  rate.
- **Shift premium**: each shift of the project can carry a premium, in $ per
  hour. Empty means the shift is billed at the regular rate, so by default
  every shift is billed the same.
- **Per diem**: not billed, at cost, or at cost plus a percentage. The cost is
  the week's own per diem.
- **Hotel**: not billed, at cost, at cost plus a percentage, or at a flat rate
  per night. Only the nights that fall in the week are counted.
- **Travel**: not billed, at cost, or at cost plus a percentage, for the trips
  leaving that week.
- **Transport on site**: not billed (included), per day on site, or per week
  worked.

Each item becomes its own line under the worker's week on the invoice, with
its quantity, rate and amount. Billing (`/billing`) uses the same total.
A project without terms bills exactly as before: every hour at the bill rate.

**Site position at creation.** Projects → Take on a project now asks for the
site latitude, longitude and clock-in radius. A "My position here" button
fills them from the phone. Before, the position could only be set afterwards
on the plant, so the clock-in (P5-M67) had nothing to check against.

Data (reversed by install/rollback/p5-m70.sql):
- new table `job_billing_terms`, one row per project;
- `project_shifts.bill_premium`, guarded separately, because the P5-M27
  rollback drops project_shifts.

Tests:
- `tests/billing_terms_db.php`: a week of 50 hours on the night shift. Without
  terms it bills $2,500, as before. With the terms set it bills $4,440:
  - 40 regular hours;
  - 10 overtime hours at 1.5×;
  - $3/h night premium;
  - per diem at cost +10%;
  - 7 hotel nights at cost;
  - the flight at cost +5%;
  - transport $12/day.

  It also checks the refusal (overtime at its own rate needs that rate), the
  flat hotel rate per night, and that overtime at the bill rate stays one line.
- `tests/clients_http.py`: the project is created with its position and its
  radius. The plant then shows **What the client is billed**, saves the terms,
  and refuses overtime "at its own rate" given without a rate, keeping the
  saved terms.

Overtime billed "at the bill rate" (the default) reads as one line of hours,
as the invoices did before. Only a premium splits it into its own line.

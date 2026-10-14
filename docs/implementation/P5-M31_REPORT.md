# P5-M31 — the worker's registration with ADP

The owner's flow, 14 Oct 2026: ADP gives the agency a registration code. The
employee registers with ADP and proves who they are with an ITIN or SSN, a
date of birth, or an employee ID. Crewvia should give the employee a way to
do this, and must not collect the SSN.

## Found before building

- **Payroll's side** existed:
  - each person's ADP employee ID (Employee folder);
  - the ADP RUN export;
  - the import record;
  - the payroll register reconciliation.
- **The worker's side** did not exist. Nobody told the worker how to get onto
  ADP, and payroll could not see who still had to register before the first
  pay run.

## What was built

**Payroll export → Workers registering with ADP:**
- Payroll enters the registration code ADP gave, ADP's site, and an optional
  note for workers.
- The site must be ADP's own over https (adp.com or a subdomain), so the link
  workers follow to prove who they are can never point anywhere else.
- Every worker on the project is listed with their ADP employee ID, which can
  be entered there, and with whether they registered.
- The list shows those not yet registered first, and the count ":done of :n
  registered with ADP".
- Recording an employee ID tells the worker it is ready.

**The portal → Register with ADP to be paid.** The card shows while the
worker is confirmed, travelling or on site, and the code is set. Its steps:
1. Go to ADP's site, the link, and create an account.
2. Enter the registration code.
3. Prove who you are: your employee ID once payroll records it. If ADP asks
   for the Social Security number or ITIN and date of birth, enter those on
   ADP's site only.
4. Choose how you are paid there.

It also says: "Crewvia never asks for your Social Security number or ITIN.
If anyone asks for them here or by text, do not answer."

"I have registered with ADP" keeps the date and tells payroll. The home page
lists the registration under what waits on them until then.

**No SSN, no ITIN, no date of birth** is asked or stored. The test checks the
portal has no such field.

**Data:** one reversible column, `employee_profiles.adp_registered_at`, plus
three settings (install/rollback/p5-m31.sql).

## For the owner

- **The real code.** Enter ADP's actual registration code on Payroll export
  before workers see the card. Until then, nothing is shown.
- **Trust.** "Registered" is what the worker says. ADP's own records are the
  proof. The payroll register reconciliation already compares ADP's
  employees with Crewvia's each week.

## Tests

- **`tests/adp_registration_db.php`:**
  - rollback keeps the ADP employee IDs;
  - upgrade, then silence on a repeat;
  - the fixture.
- **`tests/adp_registration_http.py`:**
  - nothing shown before setup;
  - three wrong sites refused, and a bad code refused;
  - the steps, the code and the note;
  - waiting on them;
  - no identity fields;
  - the employee ID refused, then recorded, the worker told and shown it;
  - done: date kept, payroll told, list and count;
  - code cleared: card gone and marking refused.

**Mutation:** with any site accepted as ADP's address, the test fails.

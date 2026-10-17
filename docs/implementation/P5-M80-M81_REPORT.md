# P5-M80 and M81 — the long screens folded, tasks for the team at each stage, supervisors' vehicles, Back

RSS, 17 Oct 2026, with the owner.

## P5-M80 — the long screens folded

What the screen is for stays open, and so do alerts and things to act on. The
rest folds to its title, using the same folds as the plant
(`details.card.fold`, `fold.js`):

- **The person** (`/candidates/N`): applications, assignment, paperwork,
  record, skills, every contact, and the details form are folded. The
  do-not-use alert, the next step, the contact card and the register stay
  open.
- **Hours**:
  - folded: pay rules this week, paid leave this week, gross to net;
  - folded together: import attendance and the payroll policy.
- **Billing**: the "where the money lands" explanation.
- **Employee folder**: pay and grade, goals, employment history, deductions,
  classification.
- **Assets**: lifecycle, history, categories.
- **HR records**: grants, access log.
- **Feedback**: what was read.
- **Training**: requirements.
- **Appraisals**: history.
- **Ledger**: ledger versus export, chart of accounts.
- **Reports**: policy, all schedules.
- **Performance**: progress record, cycles.

**The plant starts with every section folded, the scope too.** Each section
stays as the person left it, and a link to a section opens it.

**The scope's layout** is fixed: in a folded card without padding, the title
and the line under it now sit in the padded band, aligned with the table.

## P5-M81 — the team's tasks at each stage

"After you push it, it should take you to mobilisation… send the tasks to the
other guys."

When the project reaches a stage, it creates its tasks as project tasks. Each
task is assigned to someone on that desk (the one with the fewest open
tasks; the project manager for the site), with a due date and a
notification.

- **Mobilising:**
  - check the job descriptions and open the requisitions (recruiter);
  - recruit and send the offers (recruiter);
  - book the hotel rooms (hotels);
  - book the travel (hotels);
  - check the tax forms, the direct deposit and ADP (payroll);
  - prepare the site: shifts, buses, clock-in (site).
- **On site:**
  - approve the first week's hours (payroll);
  - invoice the client (admin).
- **Demobilising:**
  - travel home and hotel check-outs (hotels);
  - equipment and vehicles back (site);
  - last week paid (payroll).

A task already on the project is not made twice. The message after Advance
says how many tasks were sent.

## Supervisors get a vehicle (Manning)

"Add the vehicle if that person is a supervisor."

A crew member counts as a supervisor when:
- their trade is supervisor, foreman, lead or superintendent;
- or they supervise others on the project.

Manning shows:
- how many supervisors have no vehicle, with "Reserve a vehicle for …";
- "Needs a vehicle" or "Vehicle reserved" on their line.

The reservation form opens with the person and the driver filled in.

## Back

Every page has **← Back** in the work bar when it was opened from another
Crewvia screen. It returns there.

## ADP list

The ADP screen lists the people payroll can act on: those ready, and those
who have started their forms. The others on an assignment are counted, with
a link to Onboarding.

## Tests

`tests/client_contacts_http.py` covers:
- every plant section starting folded;
- reaching Mobilising: the six tasks, each with a due date, each assigned on
  its desk.

`tests/adp_db.php` covers: somebody who has not started a form is counted,
not listed.

Checked in the local preview:
- Back;
- Manning with a supervisor: the alert, the line, and the reservation form
  filled in.

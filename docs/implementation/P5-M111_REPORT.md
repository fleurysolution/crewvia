# P5-M111 — A seat reserved by the approval is not a hire

## What the walk found (29 Oct 2026, production)

Sample Applicant applied to *Welder (demonstration)* and was approved by the hiring manager on 24 Oct. The
application is still in **Screening**: no offer, no contract. Signed in to the portal, Sample had:

- the **employee** menu: My attendance, Time off, My pay statements, My benefits, My reimbursements, HR, I-9 & W-4,
  Documents, Offboarding…;
- seven onboarding tasks, including the **W-4, the I-9 and the direct deposit** (Social Security and bank numbers);
- on Activity, the project's headcount: "Plant 3 — 200 of 200 on the job, 0 still to place";
- an Activity line "Onboarding task to complete" that opened the top of the portal, not the tasks.

## Why

P5-M87: the hiring manager's approval reserves the seat with an **offered** placement, before any offer.
`candidate_is_employee()` counted any placement, so the reservation made Sample an employee (P5-M54's candidate
menu stopped applying), and `onboarding_scope_sql()` created the tasks of every offered placement.

The hire is the countersignature (P5-M53, `offer_countersign()`) or the signed contract (`contract_accept()`): both
put the application at **accepted**.

## What changed

- `placement_hired_sql()` (app/bootstrap.php): an *offered* placement is held back only when it is the approval's
  reservation — its requisition's application was approved by the hiring manager and is not yet *accepted*. Every
  other placement is a hire as before: past *offered*, on no requisition, or never approved (a hire before the offer
  flow, an import, a transfer — some old placements were linked to a requisition by an upgrade backfill while their
  application stayed in screening).
- `candidate_is_employee()` counts only hires. A reserved seat leaves the person on the **candidate** menu
  (portal, jobs, contracts, proofs, screening, notifications, activity, account).
- Onboarding tasks are created, shown, counted and accepted only for hires: `onboarding_scope_sql()`, the portal's
  tasks, assignments and task answer, Activity (worker and office counts), the office's Onboarding list.
- Activity: a worker or a client never sees the project's headcount; a worker reads "What needs you. Each line
  opens the screen where you do it."; "Onboarding task to complete" opens `/portal#onboarding`.

No migration. Tasks already created for a reserved seat stay in the database, hidden, and come back as they are
when the person is hired (`INSERT IGNORE`).

## Tests

`tests/reserved_seat_db.php` (8 checks): reserved seat = candidate and no task; offered not countersigned = still
not a hire; accepted = employee and the tasks come; offered placement on no requisition = hire; an older hire never approved = hire; confirmed = hire.
Wired into `tests/run_windows.py`. `offer_flow_http.py` (P5-M53) still checks the candidate menu before the
countersignature and the employee menu after.

## Still to do (from the same walk)

- /onboarding "Who" lists 500 people over every candidate; it should list the project's people, with search.
- Retrying an expired invitation should issue a new token and expiry.
- Sending the travel details does not close the "Travel details" task.

## P5-M111b — checked on production after the deploy

Sample's portal: the candidate menu, no onboarding task, no headcount on Activity. The empty Activity told a worker
"When an application arrives, a contract needs approving or a timesheet needs a decision…", the office's wording; a
worker or a client now reads "When something needs you, it appears here."

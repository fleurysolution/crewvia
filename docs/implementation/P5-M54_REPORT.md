# P5-M54 — A candidate's portal is a candidate's; signing an offer settles the rest

The owner, 15 Oct 2026, after the first offer signed and countersigned in production (P5-M53):
a candidate who is not an employee saw every employee module (attendance, time off, pay statements,
HR, "My self-service"); having signed one offer, their other applications stayed open "pending for
consideration"; a Cancel did not work; and the office should not have to ask for each follow-up step.

## What changes

- **A candidate's portal** (`worker_is_candidate()`, `candidate_paths()` in `app/bootstrap.php`): a
  portal account is a candidate until placed (or on the employee register with a number, as imported
  employees are). A candidate's menu holds: My portal, Jobs for you, Notifications, Activity, and
  under More their papers (Contracts & signatures, Proof uploads, My screening, the screening
  questionnaire) and phone notifications. The portal home shows what waits on them, Jobs for you and
  their applications and offers; not the self-service cards, the pay statements or the assignments.
  The other screens are refused on the server, not only hidden: "That part of the portal opens once
  you are hired." Countersigned (P5-M53), the employee portal and its screens open.
- **Signing an offer withdraws the other applications** (`offer_withdraw_others()`): every other
  application in progress (New, Screening, Interview, Offered) becomes Withdrawn with "Withdrawn: the
  candidate signed the offer for <job>." The letter says it before they sign ("Signing this offer
  withdraws your other application(s) in progress"); the office's notice counts them. This is for a
  candidate, not yet an employee: an employee already working with us (placed, or on the employee
  register) may hold applications for their next assignments, and signing one keeps the others.
- **A settled application closes what was open on it** (`review_close_for_application()` in
  `app/reviews.php`): rejected, withdrawn (by hand, by declining, by signing another offer) or hired,
  a manager's review still waiting is taken back and an interview still to come is cancelled; the
  manager or the interviewer is told.
- **Cancel** in "Decline this offer" closes the section and clears it (there was no way to back out
  but to click the summary again).
- Offers accepted before P5-M53 read "Hired on their signature, before the office countersigned offers"
  instead of "Countersigned by Hired on signature…"; offers signed through the contract workflow say so.

No table changes, so no migration and no rollback.

## In production, done with the owner's approval (15 Oct 2026)

Leo Noonan's offer (CNC Machinist, Plant 1) countersigned by Owner Number 1; his two other open
applications (Industrial Maintenance Tech, Mechanical Engineer) withdrawn with the reason; his
review still waiting with the manager taken back. General conditions written in Settings (standard
text, to be reviewed by the agency).

## Tests

`tests/offer_flow_http.py` gains 6 checks (23 in all): a third application with an interview to come;
the candidate's menu without pay, attendance, time off or HR screens; those screens refused on the
server; the letter warning that signing withdraws the others; signing withdraws the third, saying
why, and cancels its interview; hired, the employee screens in the menu and open.

`tests/http_tests.py` covers the employee case: its worker, already on assignment, signs an offer and
their other applications stay in play (the questionnaire and contract checks that follow use them).
The first full run, before this exception, failed there ("Mandatory questionnaire blocks offer"): the
application had been withdrawn.

Clicked in the browser on the test server: "Decline this offer" opens, Cancel closes it and clears
the reason; "Print or save as PDF" prints the letter alone; the candidate's menu and More.

Mutation: the withdrawal of the other applications disabled (caught: "Signing withdraws the other
applications in progress").

Full suite: 3743 PASS, 0 FAIL (3737 before, plus the 6 new checks).

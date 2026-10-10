# P2-M07 — employee self-service: profile, benefits, leave, pay documents, reviews, HR requests

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Own details: changes proposed by the worker, checked by staff; bank details | existed (R22–R24) | `/employee-folder` (own), `app/self-service.php` |
| Time off and what is left | existed (P1-M04) | `/timeoff` |
| Pay statements | existed (P1-M06) | `/my-payslips` |
| Benefits, own coverage | existed (P2-M04) | `/benefits` |
| Reviews, own view; goals and development | existed (P2-M02, M03) | `/appraisals`, `/performance` |
| HR record: recognition, decisions to read and answer | existed (P2-M06) | `/hr-records` |
| Reimbursements | existed | `/expenses` |
| One place for all of it, and what waits on the worker | **missing**: ten separate menu items | — |
| Asking HR for something | **missing** | — |
| Employment letters | **missing** | — |

## What was built

**My self-service** (`/self-service`, first in the worker's menu).

- **Waiting on you** comes first: a review asking for their own view, an
  HR decision to read and acknowledge, an HR request answered.
- Then one card per area, each linking to the page where it is done:
  - details (with changes still waiting for HR to check);
  - time off (what is left of each kind, requests waiting);
  - pay statements;
  - benefits (current coverage);
  - reviews, goals, the HR record and HR requests;
  - reimbursements and notifications (unread).
- It is read-only by design: the rules stay on the pages that own them.

**HR requests** (`/hr-requests`).

- A worker asks, from their own account only, for: an employment
  verification letter, an answer about their pay, a copy of a document, a
  change to their schedule, or something else. Each gets a subject, what
  they need, and a reference (`REQ-YYYYMM-NNNN`).
- **Routed to one desk**: pay questions to payroll, the rest to
  recruiting. The desk is told. Payroll does not see recruiting's
  requests, nor recruiting payroll's; administrators see both. Logistics,
  supervisors and clients see none, and one worker never sees another's.
- **A conversation**: the desk answers (the worker is told, and the home
  says so), the worker replies (it opens again), and either closes it once
  settled. Nothing is added after. Only the worker withdraws their own. At
  most ten are open at once.

**Employment letters.** On an employment-letter request, recruiting
issues the letter from the records:

- the name, since when they have worked with the agency, their current or
  last assignment and trade, and the kind of employment;
- **the pay rate only if the worker asked for it** (a landlord or a bank
  sometimes needs it);
- "states facts held in our records on [date]… It is not a reference".

The letter is **frozen when issued**, with its SHA-256. It prints as a
Letter page with the brand mark and the issuer's name. One that no longer
matches its fingerprint does not open. It is issued once.

Schema: `hr_requests`, `hr_request_replies`. Rollback:
`install/rollback/p2-m07.sql`.

## Tests

`tests/self_service_home_db.php` (rollback, upgrade counting worker
accounts, silent repeat; one worker with a review and a decision waiting,
one with nothing) and `tests/self_service_home_http.py`. They cover:

- the home: what waits first, every card, nothing waiting for the second
  worker, no home for staff, no requests for logistics;
- request refusals, including staff posing as a worker;
- routing to payroll (told) and to recruiting, each desk seeing only its
  own, and neither the other worker nor the other desk able to open them;
- the conversation: answered and told, the home saying so, reopened,
  withdraw refused to staff, closed with nothing after;
- the letter: name, trade, project, $32.00 an hour as asked, the
  fingerprint, issued once, printable, not opened by the other worker or
  once altered;
- a letter not asked to state pay not stating it;
- withdrawing, and translations.

`/self-service` and `/hr-requests` are in the access matrix and the CSRF
sweep.

Full isolated run: **1562 PASS, 0 FAIL** (browser step skipped).

Mutation: I put the pay rate on every letter, asked for or not. The suite
failed on "A letter not asked to state the pay does not".

## Limits

- Outbound email stays disabled: notifications are in the app, and letters
  are printed or downloaded by the worker.
- A request carries no attachment. A document the worker sends goes
  through their documents (proofs); one HR sends back is in their
  agreements or folder.
- The letter is in the language of the person who issued it.

---

Phase 2 (P2-M01 to P2-M07) is complete.

# P5-M19 — the three findings of the review

The owner's request, 13 Oct 2026: "fix all three". These were found while
checking the backlog against the code (review of 12 Oct).

## 1. A termination now locks the worker's access at once (R31, BACKLOG T21)

**Found:** recording a termination never switched the worker's portal
account off. Nothing anywhere set `users.is_active = 0`; the only way was
the manual toggle on People. R31 says "On termination their access is
locked immediately".

**Built** (`hr_lock_access()` in `app/hr-records.php`):

- **When it runs:**
  - a case is decided as a **termination**;
  - a separation is recorded with the reason **terminated**.
- **What it does:**
  - switches the portal account off;
  - ends every open session, by moving the session version on;
  - revokes every trusted phone (reason "terminated"). Push already stops
    for an account that is off.
  - writes "portal access locked" on the person's history.
- **Other endings leave the portal open:** an assignment completed, a
  resignation, a no-show. People between jobs still read their pay
  statements and answer job offers there.
- An administrator can switch the account back on from People.
- **People terminated before this change** kept a working login. The
  upgrade switches their accounts off **once**:
  - who: anyone with a decided termination case, or a separation recorded
    as terminated;
  - the upgrade prints how many;
  - an account switched back on afterwards stays on (the sweep is marked
    done in `platform_settings`).

## 2. Supervisors grade their own crew's assignments

**Found:** the Roster's grading form was meant for recruiters and
supervisors, but the Roster admits only recruiters, Logistics and payroll.
The supervisor's half could never be reached.

**Built:**

- **One save for both pages:** `assignment_review_save()` in `app/hr.php`,
  used by the Roster and by My team, so the rules cannot drift apart. The
  rules: a grade; whether to have them back, with a reason when not;
  scores per criterion; the history event.
- **My team** now shows "finished assignments to grade":
  - the supervisor's **own** crew only;
  - finished in the **last 60 days**;
  - not yet graded.

  The form is the Roster's own, now one partial
  (`app/views/_grade-form.php`).
- **The Roster's grading** is the recruiter's, as its page always was.

## 3. A worker's and a supervisor's menu in their own words

**Found:** the menu used the desks' groups for everybody. A worker's own
contracts and I-9 sat under "Hiring", their HR record under
"Administration", and the rest under "Workspace".

**Built:** `workspace_sections()` gives workers and supervisors their own
groups:

| Group | Pages |
|---|---|
| My work | portal, Jobs for you, My team (supervisors), tasks, project tasks, board, workload, attendance, lateness, time off, procurement |
| Safety and training | safety plan, safety & learning, my training |
| My papers | contracts, documents, proof uploads, I-9 & W-4, employee folder, screening, qualifications, questionnaire |
| My pay | self-service, pay statements, reimbursements, benefits |
| HR | requests, records, reviews, goals, offboarding |
| Messages and recognition | activity, notifications, communications, Gmail, recognition, feedback, surveys, phones |

The desks' menus are unchanged. Six new strings are translated into French
and Spanish.

**Data:** no table or column changes. The upgrade's one-time sweep (above)
is the only data change.

## Tests

- **`tests/review_fixes_db.php`:**
  - **the sweep:**
    - switches off a worker terminated before the lock, and says so;
    - leaves alone a worker whose case is still open;
    - runs once: switched back on, the account stays on, and the next
      upgrade is silent;
    - the suite's other accounts are put back afterwards;
  - **the fixture.**
- **`tests/review_fixes_http.py`:**
  - **The lock:**
    - a worker signed in with a trusted phone;
    - the case decided as a termination switches the account off, ends
      their open session, stops them signing in again, revokes the phone,
      and writes the history;
    - a resignation leaves the portal open;
    - a separation recorded as terminated locks too;
    - People can switch it back on, and the worker signs in again.
  - **Grading:**
    - My team lists only the supervisor's crew member who finished 10 days
      ago: not 90 days ago, not still on site, not another supervisor's;
    - not having them back needs a reason;
    - the grade is recorded by the supervisor and written on the history,
      then leaves the list;
    - another supervisor's crew is refused;
    - a supervisor cannot grade from the Roster;
    - a recruiter still can.
  - **Menu:**
    - a worker's contracts under My papers (no Hiring group), their HR
      record under HR (no Administration group), pay statements under My
      pay, Jobs for you under My work;
    - a supervisor's team under My work;
    - the desks keep their groups;
    - Spanish.
- **`tests/review_fixes_probe.php`:** reads accounts, phones, reviews and
  events.
- **Mutation:** with the lock removed from a termination decision, the
  test failed at "A case decided as a termination switches the account
  off". Restored afterwards.

## Not changed

- **Reaching the people inside Crewvia:** a locked worker keeps their crew
  record, pay history and documents; only the sign-in is stopped.
- **A termination recorded straight in the database,** bypassing the
  screens, is not locked until the separation is recorded on the screen,
  or by the one-time sweep for those that existed before it.

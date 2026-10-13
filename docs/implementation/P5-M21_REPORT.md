# P5-M21 — layout walkthrough, wave 2: a home page for each role

The owner's decisions, 13 Oct 2026:

- **Homes:** one home per role, built for its mission.
- **Workers:** one home, merging My portal and My self-service.

## Found before building

- **One landing page for every desk.** Administrators, recruiters,
  Logistics and payroll all landed on the same project dashboard (`/`).
  Payroll was greeted with a hiring pipeline, Logistics with margins.
- **Two overlapping worker homes:**
  - **My portal:** a long column of assignments, onboarding tasks, hotel,
    travel and applications;
  - **My self-service:** a grid of cards for their records.
- **My team** listed the supervisor's crew and nothing about today.
- **The logo** led nowhere.

## What was built

**Where each role lands after signing in** (`home_for()` in
`app/home.php`, used by the sign-in page and the logo):

| Role | Home |
|---|---|
| Administrator | Executive overview (`/overview`) |
| Recruiter, Logistics, payroll | **Today** (`/home`), first in their menu |
| Supervisor | My team, now with their crew today |
| Worker | their portal, now their only home |
| Client | the client portal |

A page asked for before signing in still comes first. The project
dashboard (`/`) is unchanged and stays in the menu under Overview.

**Today** shows figures across all running projects. Each figure comes
from the same query or function as the page it leads to;
`project_figures()` is summed for the deployment problems.

- **Recruiter:**
  - **tiles:** applied this week, new applicants waiting for review, in
    screening or interview, offers waiting for an answer;
  - **requisitions still short of people,** with hired against openings
    and new applicants, each leading to its Hiring workspace;
  - **reaching the pool:** campaigns going out, waiting for an
    administrator, drafts, and new people nobody has contacted.
- **Logistics:**
  - **tiles:** arriving in the next 7 days, placed with no bed, sharing a
    room, travel not booked;
  - **the week's arrivals,** each with whether the bed and the travel are
    booked;
  - **waiting on Logistics:** folios to finish, purchase requests to order,
    people blocked from deploying, people on site.
- **Payroll:**
  - **tiles:** days of hours waiting for approval, last week's sheets not
    approved, lateness disputes, bills past due;
  - **to check:** detail or bank changes, time-off requests, bills due in 7
    days;
  - **the week in order:** approve the days and settle lateness; import and
    approve the week; close the pay period and export to ADP; bill the
    clients.

**My team** opens on the crew today:

- **tiles:** on site, not marked at today's roll call, days of hours to
  approve, open tasks (and how many are late);
- **links:** the week's late arrivals, project tasks, and asking for
  something the crew needs;
- then the crew, and the assignments to grade (P5-M19).

**The worker's portal** is their one home:

- the standard header with Contracts and Pay statements;
- **what waits on them:** a review, an HR decision, HR's answers, plus
  onboarding tasks to complete and job offers to answer;
- **the self-service cards:** details, time off, pay statements, benefits,
  reviews, goals, HR record, HR requests, reimbursements, notifications;
- Jobs for you, and their assignment and crew;
- hotel, travel and pickups, side by side, when there are any;
- hotel charges, onboarding tasks and applications, with the forms they
  had.

`/self-service` now leads to the portal's self-service section, keeping
the language of the link (`?lang=fr`), and the menu no longer lists it
separately. The cards are one partial, `app/views/_self-service-cards.php`.

**Also:**

- **The logo** leads to the role's home.
- **The automatic section label** looks for a label of the page's own only
  up to the title, so a label further down (My team's "How it went") no
  longer hides it.
- **Greetings** are whole sentences ("Good morning, :name"), so they
  translate properly.
- **54 new strings** in French and Spanish.
- **Data:** no data changes, no migration.

## Tests

- **`tests/home_db.php`:** one user per desk; a supervisor with two of
  their crew on site; a worker with an onboarding task; a running project
  with an open requisition; a hotel.
- **`tests/home_http.py`:**
  - **Landing:**
    - every role lands on its home (seven roles);
    - a page asked for before signing in still comes first;
    - an administrator's Today is the overview.
  - **Recruiter:** the tiles and lists; the short requisition leading to
    Hiring; one more application moves this week +1 and waiting +1.
  - **Logistics:** someone confirmed for 3 days' time moves arriving, no
    bed and no travel each +1, and appears in the arrivals unbooked.
  - **Payroll:** a submitted day, a disputed lateness and a past-due bill
    each move their tile +1.
  - **Supervisor:** two on site and both unmarked; after a roll-call mark,
    one left; a day to approve.
  - **Worker:**
    - the portal holds what waits on them and all ten cards, with the
      onboarding task first;
    - Jobs for you and the assignment;
    - `/self-service` leads to the portal and is out of the menu.
  - **Menus:** Today first in a desk's menu; the logo leads home.
  - **Spanish.**
- **`tests/home_probe.php`:** adds each of those things.
- **`tests/layout_http.py`:** the portal now has the standard header, so
  it left the pending list.
- **`tests/http_tests.py`:** a worker sent away from a staff page is
  recognised by the portal's Assignments card, since the portal's old
  subtitle is gone.
- **Mutations:**
  - with sign-in sending everyone to `/` again, the test failed at "admin
    lands on /overview";
  - without the signed subtraction below, it failed with a 500 at the
    over-filled requisition.

  Both restored afterwards.

## Found by the full suite

**A crash.** A requisition with more people hired than openings took the
recruiter's Today down. The column `openings` is unsigned, so
`openings - hired` fell below zero and the database refused it. The
recruiter suite's data has such a requisition, and so can production.

**A lost language.** `/self-service?lang=fr` led to the portal in English,
because the redirect dropped the language. It now carries it, and a check
in `home_http.py` covers it (a mutation without it fails there).

**The fix for the crash:** the subtraction is now signed. The over-filled requisition
is left out of the short list, and the test adds one.

## Next

- **Wave 3:** the menus, with daily screens first and the rest under
  "More".
- **Wave 4:** the page-by-page pass.

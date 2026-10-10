# P2-M03 — performance goals, development plans, evaluation cycles, follow-up, progress records

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Reviews: templates, self and supervisor scoring, approval, history | existed (P2-M02) | `appraisals`, `/appraisals` |
| Opening reviews in bulk, by period | **missing**: one at a time | — |
| Goals, development actions, their follow-up and progress | **missing** | — |
| Training records (safety and learning) | existed, separate | `/learning` |

## What was built

**Evaluation cycles** (`/performance`, recruiters).

- A cycle has a name, a review template, a period, a due date, and a scope
  (one project or all).
- Opening it opens a review for every live assignment in scope (confirmed,
  travelling or on site, on an active project), reviewed by the
  assignment's supervisor (P2-M02).
- It lists who it could not open, and why: no supervisor, a review already
  open. Opening again picks up new assignments and opens nobody twice.
- A cycle with unfinished reviews closes only with a reason. A closed cycle
  does not open again. The list shows approved against total.

**Goals.**

- Each goal has what is to be achieved, how it is measured and a target
  date, and optionally the review it came from.
- Recruiters, and the supervisor of one of the person's live assignments,
  set goals, record progress (0–100%, with what was done) and close them
  as achieved (progress 100), missed or dropped, with how it ended.
- The worker is told when a goal is set, and can report progress on their
  own goals. That is marked as their own account.
- Every update is kept.

**Development plans and follow-up.**

- An action is training, coaching, a certification or other, with a
  description, an owner who follows it up (a recruiter or a supervisor)
  and a due date. The owner is told.
- Past its date, an action is overdue: in the recruiter's overdue list and
  the owner's *Yours to follow up*.
- The owner, a recruiter or the person's supervisor marks it done (saying
  what came of it) or cancelled (saying why).

**Progress record.** One timeline per person: approved reviews, goals set
and closed, every progress update (whose account), and actions planned and
closed. It is on the person's page and in the employee folder. Each review
links to the person's goals and plan, and goals and actions can be tied to
the review they came from.

**Who sees what.**

- Recruiters see everyone.
- A supervisor sees their crew, and the actions they follow up.
- A worker sees only their own goals and plan, without the forms or the
  record.
- Payroll, logistics and clients see none of it.

Schema: `appraisal_cycles`, `performance_goals`, `goal_updates`,
`development_actions`; `appraisals.cycle_id`. Rollback:
`install/rollback/p2-m03.sql` (reviews stay, without their cycle). The
P2-M02 rollback now drops these tables first.

## Tests

`tests/performance_db.php` (rollback, upgrade, silent repeat; four
assignments: one with a worker account, one without, one with no
supervisor, one finished) and `tests/performance_http.py`. They cover:

- the cycle: refusals, opening for exactly the two eligible assignments,
  Charlie listed without a supervisor, idempotent reopening, the
  close-with-reason rule, no reopening once closed;
- goals: refusals, the worker told, another crew's supervisor refused;
- progress: the worker's own 40% marked as theirs, refused on another's
  goal, limits on percentage and note, the supervisor's 60%, closing
  achieved at 100, no progress after closing;
- actions: refusals, the owner told, overdue in both lists, done;
- visibility: the worker's own view, another crew's supervisor refused,
  the progress record, employee folder and review link;
- translations.

`/performance` is in the access matrix and the CSRF sweep.

Full isolated run: **1383 PASS, 0 FAIL** (browser step skipped).

Mutation: I let any supervisor manage anyone. The suite failed on "The
supervisor of another crew cannot set Bravo a goal".

## Limits

- No automatic reminders before a due date; overdue items are listed, and
  owners are notified when the action is planned.
- Development actions are not linked to courses in Safety and learning; a
  certification earned is still recorded there.
- A supervisor works on the newest active project when switching is needed
  (unchanged since P3-M06).

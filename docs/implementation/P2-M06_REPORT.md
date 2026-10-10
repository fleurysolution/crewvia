# P2-M06 — awards and recognition, disciplinary cases, separation records, controlled HR access

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Do-not-rehire register: a decision with a reason, shown beside the name everywhere | existed | `employee_profiles.rehire_status`, `/candidates/{id}` |
| Ending an assignment, with offboarding tasks and equipment returned | existed | `/operations`, `offboarding_tasks` |
| Safety incidents | existed | `safety_incidents` |
| Recognition | **missing** | — |
| Disciplinary cases | **missing** | — |
| Why an assignment ended | **missing**: only that it was completed | — |
| Access narrower than a role | **missing** | — |

## What was built (`/hr-records`)

**Controlled access.**

- Disciplinary cases and separation records are seen only by
  administrators, and by recruiters, payroll or logistics users an
  administrator grants **HR access** (with the reason). Grants can be
  revoked.
- A supervisor reports cases on their own crew and sees only the cases
  they reported. The worker sees a case about them once its outcome is
  recorded. Nobody else sees them: not payroll or logistics without the
  grant, nor a recruiter without it, nor clients.
- **Every opening** of a person's HR record or of a case, and every grant
  and revocation, is logged. Administrators see the log (*Who opened
  what*).

**Recognition.** Award, commendation, safety recognition, years of service.

- Recorded by recruiters or the person's own supervisor, dated today or
  earlier.
- It goes on the person's record, and the worker is told and sees it.

**Disciplinary cases.**

- **Opened** by HR, or by the person's supervisor, with a category, the
  date, the facts (at least 20 characters: what happened, when, who saw
  it), and optionally the safety incident. Each case gets a reference
  (`HR-YYYY-NNNN`).
- **The facts are never edited.** Notes are added by HR.
- **The outcome**: no action, verbal, written or final warning, suspension,
  termination, with its explanation.
  - A termination is decided by an administrator.
  - Whoever opened a case does not decide a final warning, a suspension or
    a termination on it.
- **The worker** is told, reads the outcome, gives their own account once
  (it is not rewritten), and acknowledges it. Acknowledging means having
  read it, not agreeing.
- **Closed** by HR, which records whether it was acknowledged. Nothing is
  added after that.
- The case's record lists, in order, everything added and by whom.

**Separations.** Once per ended assignment (it is closed on Operations
first).

- It records the reason (completed, resigned, terminated, no-show, laid
  off, other), whether it was voluntary, what happened (required unless
  the assignment simply finished), and the rehire recommendation.
- A termination must refer to the case that decided it.
- *No rehire* puts the person on the existing register with the separation
  as reason. *Check before calling* sets it unless a block is already
  there.
- HR sees the ended assignments still waiting for a separation.

The employee folder links to the person's recognition and HR records.

Schema: `recognitions`, `disciplinary_cases`, `disciplinary_notes`,
`separations`, `hr_access_grants`, `hr_access_log`. Rollback:
`install/rollback/p2-m06.sql`, which warns that these are personnel
records an employer may have to keep: export them first. Register entries
stay.

## Tests

`tests/hr_records_db.php` (rollback, upgrade, silent repeat) and
`tests/hr_records_http.py`. They cover:

- payroll refused without the grant;
- recognition: a future date, another crew's supervisor refused, the
  worker told and seeing it;
- the grant: a recruiter refused, a reason required, logged;
- a case: refused without HR access and with thin facts, reported by the
  supervisor and tied to the incident, its reference;
- the supervisor reads it but cannot add, and another supervisor, a
  recruiter without access and the worker (while open) cannot see it;
- the granted recruiter's reading logged, a note, a termination refused to
  non-administrators, the explanation required;
- a written warning that leaves the facts unchanged and notifies the
  worker;
- the worker's account and acknowledgement, not rewritten;
- closing, with nothing added after, and the record's order;
- a second case: its opener refused a final warning, an administrator's
  termination;
- separations: a live assignment refused, a voluntary resignation, one
  separation per assignment, a termination needing its case and putting
  the person on the register, separations hidden from a recruiter without
  access;
- revoking, which takes the case away;
- the log in the administrator's view, and translations.

`/hr-records` is in the access matrix (refused to logistics, payroll and
clients without a grant) and the CSRF sweep.

Full isolated run: **1519 PASS, 0 FAIL** (browser step skipped).

Mutation: I let any recruiter see cases, with or without the grant. The
suite failed on "A recruiter without HR access cannot see it".

## Limits

- Documents (signed warnings, statements) are not attached to a case here;
  they stay in the person's documents.
- Recognition carries no bonus. A cash award is paid as a payroll
  adjustment (P1-M06).
- No retention schedule: nothing is deleted automatically, and how long
  personnel records are kept is RSS's policy.

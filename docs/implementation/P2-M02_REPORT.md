# P2-M02 — appraisal templates, criteria, self and supervisor evaluations, scoring, approval, history

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| A catalogue of criteria, retired rather than deleted | existed (5 criteria) | `assignment_review_criteria` |
| One end-of-assignment review per assignment: grade A–F, would rehire, 1–5 per criterion | existed | `assignment_reviews`, `assignment_review_scores`, Roster |
| The review written to the person's record | existed | `candidate_events` |
| Templates, weights, a configurable scale or grade thresholds | **missing** | — |
| The worker's own view | **missing** | — |
| Approval by somebody other than the reviewer | **missing**: the grade was final when typed | — |
| Reviews other than end of assignment (periodic, probation) | **missing** | — |
| A review's history | **missing** | — |

The roster's quick review is kept: an approved end-of-assignment appraisal
writes it, so the roster, the rehire decision and the person's record read
one grade. No second criteria table was created.

## What was built

- **Templates** (`/appraisal-templates`, administrators): name, kind (end of
  assignment, periodic, end of probation), scale 1 to 3–10, whether the
  worker gives their own view first, A/B/C/D thresholds, and a weight of
  0–10 for each criterion (0 leaves it out). New criteria are added to the
  shared catalogue. A template a review has used cannot change; a new version
  is a new template and the old one is retired. The upgrade creates *End of
  assignment* from the existing criteria on 1–5, safety counting double.
- **Scoring**: each reviewer mark over the scale, weighted, as a percentage,
  graded against the template's thresholds. The worker's own marks are shown
  to the reviewer and kept, never counted.
- **The workflow** (`/appraisals`), every step an event with who and why:
  1. a recruiter opens it for somebody on the project; the reviewer is the
     assignment's supervisor unless another is chosen; nobody reviews
     themself, and one open review per template per assignment;
  2. *self-review* (if the template asks and the worker has an account),
     or a recruiter skips it with a reason;
  3. *supervisor review*: every criterion scored; a 1 needs a comment;
     "would not rehire" needs a reason;
  4. *awaiting approval*: a recruiter other than the reviewer approves, or
     returns it with a note the reviewer sees;
  5. *approved*: frozen. An open review can be cancelled with a reason.
- **Who sees what**: recruiters see all; a supervisor sees those they score
  or whose crew they run; a worker sees their own while it asks for their
  view and once approved, never the reviewer's draft; payroll, logistics and
  clients see none. Notifications go to the worker and the reviewer at each
  hand-over.
- **History**: each review's own timeline; approved reviews in the employee
  folder's employment history; the person's record (`candidate_events`).

Schema: `appraisal_templates`, `appraisal_template_criteria`, `appraisals`,
`appraisal_scores`, `appraisal_events`. Rollback: `install/rollback/p2-m02.sql`
(keeps the roster's reviews).

## Tests

`tests/appraisals_db.php` (rollback, upgrade, seeded template, silent repeat)
and `tests/appraisals_http.py`. They cover template refusals and creation,
a new criterion, opening (self-review or straight to the reviewer, duplicate
and no-reviewer refusals), the worker's view and its visibility, the
reviewer's refusals (score of 1, above the scale, no reason to not rehire),
the weighted score (80.00% B, then 93.33% A after a return), return and
approval by a second recruiter, the roster review written on approval, a
1–10 probation template (78.00% C) that does not touch the roster, the
reviewer unable to approve their own, a used template locked, a retired one
unusable, cancelling, the employment history, list scoping, and French and
Spanish. `/appraisals` and `/appraisal-templates` are in the access matrix
and the CSRF sweep.

Full isolated run: **1001 PASS, 0 FAIL** (browser step skipped).
Mutation: removing the rule that the reviewer cannot approve their own made
the suite fail on "The reviewer cannot approve their own review".

## Limits

- No review cycles or reminders by date; a recruiter opens each review.
  Cycles, goals and follow-up are P2-M03.
- One reviewer per review; no panel or multi-level approval chain.
- A returned review keeps the reviewer's earlier marks as the starting point;
  only the final marks are kept, the events say it was returned and why.

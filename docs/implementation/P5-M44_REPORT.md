# P5-M44 — From the recruiter to the manager: review, another interview, validation; interviews with a scorecard

The owner's flow: "the recruiters review and screen the candidates first; if one meets the
requirements, they pass them to a manager for validation or another interview." Measured before
this module: a manager (a supervisor, or a client) never saw applicants; the only validation was
on the offer (approval chains, P5-M28); "interview" was a stage name with nothing behind it.

## The flow now

1. **Recruiter, Hiring → an application → Manager review → Pass to the manager**: who (any
   active owner, recruiter or supervisor, not oneself), a fit from 1 to 5, and a recommendation
   in words (required). One manager at a time; the recruiter can take it back. The manager is
   told in the app; the applicant list shows "With the manager".
2. **Manager, Candidate reviews** (`/reviews`, in the menu of the owner, the recruiters and the
   supervisors): what was passed to them, with the screening score and the recruiter's fit. Opened,
   the applicant's dossier: what the recruiter says, the screening answers (with their points and
   any disqualifying answer), the documents (a supervisor can open only the documents of
   applicants passed to them or interviewed by them), the interviews, and the application's
   history. The decision:
   - **Validate**: the recruiter goes on toward an offer (the offer chain, if any, still applies);
   - **Another interview**: with a reason, and scheduled in the same step if wanted;
   - **Decline**: with a reason; the recruiter closes it with the candidate.
   The recruiter is told in the app; Hiring shows the decision on the application and in the list.
3. **Interviews**: scheduled from Hiring (or by the manager) with a date and time, length, video,
   phone or in person, the place or call link, and the interviewer. The application moves to
   Interview. The interviewer finds it under "Interviews to hold" and records the scorecard: held
   (a rating from 1 to 5, a verdict hire / maybe / do not hire, what they saw), no-show, or
   cancelled. The recruiter is told; Hiring shows "4/5 · Hire" and the words.
4. **Activity** counts, for each person, the applicants waiting for their review and the
   interviews they hold.

Nothing here writes to a candidate or moves an application to an offer or a rejection: those stay
the recruiter's. Everything is also written in words on the application's history, so a rollback
loses no record of what happened.

Also fixed on the way: the menu item "Candidate resumes" now reads "Candidate documents" (P5-M42
renamed the page).

## Changes

- `app/reviews.php` (new): statuses, people, request, withdraw, decide, interview schedule and
  record, the dossier, document access for a reviewer.
- `app/pages/reviews.php`, `app/views/reviews.php` (new): Candidate reviews.
- `app/pages/hiring.php`, `app/views/hiring.php`, `app/hiring.php`: the Manager review and
  Interviews cards, the actions, the review status in the list.
- `app/pages/resumes.php`: a supervisor downloads only the documents of applicants given to them.
- `app/navigation.php`, `public/index.php`: `/reviews` routed, in the menus (the supervisor's
  own menu included) and allowed for supervisors.
- `app/activity.php`: the two counts.
- `install/upgrade.php`: `application_reviews` and `interviews`; `install/rollback/p5-m44.sql`.
- `app/lang/messages.tsv`: 89 FR/ES strings.

## Tests

- `tests/reviews_http.py` (25): the whole flow above, with the refusals (no recommendation, to
  oneself, twice at once, another interview without a reason, a held interview without a rating
  and verdict), the supervisor kept out of Hiring and of other applicants' documents, the menu.
- `tests/reviews_db.php` (4): rollback (tables gone, history kept), upgrade, silent repeat.

Measured: chain + Indeed import + add applicant 95, board scores 8, own scoring 7 + 8, reviews
25 + 4, no server error. Mutation: a reviewer allowed every document (caught: "but not the
documents of anybody not passed to them" got 200). Seen in the browser as the supervisor: the
dossier.

Full suite: the first run stopped at `menus_http.py`, which pins each desk's daily screens in order;
Candidate reviews is now one of them for the owner, the recruiters and the supervisors, so the
test's expectation was updated (home + menus then 118 PASS). The whole suite was then run on
P5-M44, M45 and M46 together (after one more expectation in `page_pass_http.py` for the
poster, P5-M46): 3670 PASS, 0 FAIL.

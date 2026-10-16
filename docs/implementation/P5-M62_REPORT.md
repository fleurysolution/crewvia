# P5-M62 — The demonstration rehearsal: a project manager's own screens; the candidate is always told

16 Oct 2026, the day before the demonstration to RSS: the demonstration was loaded on the test server
exactly as in production (owner1, vp1, manager1, supervisor1, recruiter1, coordinator1, payroll1, the
three plants) and played from A to Z, each role in its own session: the seven accounts sign in and open
every screen of their menu (389 screens); a candidate applies from the poster's QR code with a CV and a
screening answer and opens a portal on the spot; screening; passed to the project manager, who
validates; an interview; the offer letter with the general conditions; signed, countersigned, hired;
onboarding submitted and approved; a supervisor and a shift; the paperwork gate before deployment; a
hotel room; then a demo employee on site clocks in, the supervisor validates, hours entered and
approved; payroll and the owner open their screens. Two faults were found.

## Found and fixed

1. **The project manager's own menu had two refused screens**: Procurement (403) and Site safety plan
   (404). Both, and the safety incidents of Safety & learning, decided "supervises a crew on this
   project" with their own query instead of `supervised_job_ids()`, which P5-M59 taught about the
   projects a person manages. They now use it (crews, budgets, projects managed).
2. **A real candidate was not told in their portal when a demonstration account worked on their
   file.** The demonstration guard (`notices_cross_demo`, P5-M35) keeps demonstration and real accounts
   from notifying each other; but in production the demonstration accounts are the ones in use (Owner
   Number 1 is one) and the candidates are real, so the interview, offer and hiring notices reached the
   candidate by email but not in their portal. `notify_candidate()` now always tells the person
   concerned. Between staff accounts the guard is unchanged: a demonstration account still does not
   notify a real member of staff (Jerry Polkhovskiy, for one, is not told when Owner Number 1 passes
   him a candidate) - a decision left to the owner.

No table changes, so no migration and no rollback.

## Tests

`tests/demo_notice_db.php` (2): a demonstration account working on a real candidate's file, the
candidate is told in their portal; between staff the guard still holds.
`tests/project_manager_http.py` gains 2: the purchasing and the site safety plan open on the project
they manage.

Mutations: the old `notify_candidate()` (caught: "the candidate is told in their portal"); the old
procurement rule (caught: "The purchasing opens on their project", 403).

Full suite: 3796 PASS, 0 FAIL (3792 before, plus the 4 new checks).

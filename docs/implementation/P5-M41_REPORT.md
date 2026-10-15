# P5-M41 — Scope "Placed" counts the crew on the line; past start dates read "starts immediately" in Hiring

Found while working the live Indeed requisitions on Plant 1.

## What was wrong

1. **Scope "Placed" showed 0 on lines that had people on site.** `scope_line_filled()` only
   counted placements reached through an application on a requisition raised against the line.
   A crew placed straight onto the line (a roster load, the plant setup) has a placement that
   names the line (`placements.order_line_id`) but no application, so it counted as nobody. The
   old join could also count one placement twice when the person had two applications on the
   line's requisition.
2. **Hiring showed a start date that had already passed** ("starts 14 Sep 2026") for
   requisitions that start immediately. The careers page and My jobs already said "starts
   immediately" (`starts_words()`, P5-M36); Hiring's list and requisition header did not.
3. **The upgrade message said "0 candidate(s) … were brought forward".** It now says no
   candidate was behind their application, or gives the count with the right plural.

## The change

- `app/scope.php` `scope_line_filled()`: counts live placements (`confirmed`, `travelling`,
  `on_site`) that name the line; a placement that names no line still counts through the line's
  requisitions, once (`EXISTS`, not a join).
- `app/views/hiring.php`: both start labels use `starts_words()`.
- `install/upgrade.php`: the candidate-stage message (P5-M26 step).

No table changes, so no migration and no rollback.

## Tests

- `tests/scope_filled_db.php` (new, wired into `run_windows.py` after the Indeed import test):
  a crew placed onto a line counts (2); an older placement through the requisition counts once,
  even with two applications (3); an offer and a person on another line do not.
- `tests/indeed_import_http.py`: a requisition whose start is a month back reads "starts
  immediately" in the Hiring header (`indeed_import_probe.php past-start`).

Measured:
- Walkthrough chain + plant setup + notices + Indeed import: 81 PASS; scope test 3 PASS.
- Mutation run (the previous `scope.php` and `views/hiring.php` swapped into the test copy):
  the Hiring check fails with `starts 14 Sep 2026`, the scope check fails with `0`. Both caught.
- Full suite on M40 before these changes: 3572 PASS, 0 FAIL. Full suite on this commit: 3576 PASS,
  0 FAIL (the 4 new checks included).

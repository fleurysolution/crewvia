# P5-M60 — The working-project bar on a phone

Found in the audit of 16 Oct 2026, at phone width (375 px), on every screen that reads the working
project: the project menu was squeezed to 45 px, too narrow to show the project's name, and the
"Open project" button ran 27 px under the "waiting for you" pill. The rule meant to give the bar its own
line on a small screen (`width: 100%`) was undone by `flex: 1`, whose basis of 0 let the pill share the
line.

## What changes

`app/views/_layout.php`: under 620 px the bar takes the whole line (`flex: 1 1 100%`) and the menu may
shrink properly (`min-width: 0`), so the pill goes to the next line. Measured in the browser at 375 px:
the menu 130 px wide with the project's name, the button inside the screen, the pill below.

## The rest of the phone-width audit

No horizontal scrolling at 375 px on the administrator's daily screens (Executive overview, Activity,
Approvals, Project dashboard, Projects, Hiring and an application, Candidate reviews, Hours, Billing,
Team, Settings), the supervisor's (My team, Attendance, Lateness, Project board), the employee's
(portal, attendance, time off, pay statements) and the candidate's (portal with the offer letter, Jobs
for you).

## Tests

`tests/browser_tests.cjs` gains the check (at 375 px on Hours: the button inside the screen, the menu
at least 90 px wide, the pill clear of the button). That test needs Playwright, which this machine
does not have (the suite runs with SKIP_BROWSER=1): it was not run here. The change was measured in
the in-app browser instead, before and after.

Full suite: 3790 PASS, 0 FAIL (unchanged: the new check is in the browser test, skipped here).

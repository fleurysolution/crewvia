# P5-M58 — Every desk's menu opens; links go only where the desk can go; /assets under Apache

The owner, 16 Oct 2026: make sure every portal, module, menu, sub-menu and button works, that the
role-based menus and permissions hold, before the platform is shown to the client.

## How it was audited

- On the test server, one account per kind (administrator, recruiter, supervisor, Hotels & Travel,
  Payroll & Billing, employee, candidate, client): every link of its menu (daily screens and "More"),
  then every link on those screens one level down, and every form's target.
- In production, read only, as the owner: every one of the 104 screens of the menu, timed, on the
  real data (1,099 accounts, three plants).

## Found and fixed

1. **/assets (the equipment register) never opened in production.** `/assets` is both the screen and
   the folder of stylesheets and images; Apache answered the folder (a redirect to `/assets/`, then a
   refused listing, 403). PHP's built-in server, used by most tests, routes it to the application, so
   no test saw it. `public/.htaccess` now sends `/assets` to the application; the files inside the
   folder are still served. Reproduced and checked under Apache (`tests/load_http.py`).
2. **The project dashboard linked desks to screens they cannot open**: Recruiting to Hours; Hotels &
   Travel to Candidates, Application screening and Hours; Payroll & Billing to Candidates and
   Application screening (each answering "Not your desk"). A step of the dashboard is now a link only
   when the desk opens its screen (`workspace_can_open()`, the menu's own rule).
3. **Payroll's home** counted "time-off requests to decide" with a link to Time off, which Recruiting
   decides and Payroll cannot open: the count stays, marked "(Recruiting decides)", without the link.
4. **The client's bell** opened a refused page: a client now opens their own notifications.
5. **"Sign in / connect with Google"** on the sign-in page led, while Google is not set up (as in
   production), to a bare error page: it is offered only once Google is configured.

## Gmail

Off in production: "Google setup required". Not a fault of the code: the agency's Google OAuth client
(client ID and secret, the callback `https://crewvia.bpms247.com/google-callback`, Gmail API, read and
send) must be created with the agency's Google account and written into the server's `config.php`.
The steps are in `docs/GMAIL_SETUP.md`.

## Scheduled jobs

System health in production: the nightly backup is failing (last one 14 Oct, by hand), workflows and
report schedules never ran, no restore drill: the cron jobs of `docs/OPERATIONS.md` section 2 are not
set on the server.

Measured and fine: in production the 103 other screens answer 200 without a PHP error, the slowest in
about 2 seconds (Pay rules); forms answering 405 to a GET are the POST-only ones (language, working
project, contacts).

No table changes, so no migration and no rollback.

## Tests

`tests/menu_crawl_http.py` (14), with `tests/menu_crawl_db.php` (a Hotels & Travel and a Payroll &
Billing account): for each of the seven kinds of account, every screen of the menu opens (200, no PHP
error, not refused), and every link on its home screen and the project dashboard opens.
`tests/integrations_http.py` gains: the sign-in page does not offer Google while it is off.
`tests/load_http.py` gains: under Apache, /assets opens the equipment screen and the folder still
serves its files.

Mutation: the dashboard's links unfiltered again (caught: "Recruiter: every link on its home screen
and the project dashboard opens - /hours: status 403").

Full suite (M58 and M59 together): 3790 PASS, 0 FAIL.

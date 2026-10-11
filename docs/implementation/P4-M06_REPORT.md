# P4-M06 — security, privacy and accessibility review

**What this module does and does not claim.**

- This is a code review of Crewvia, made inside the repository, with the
  remediations it found and tests that prove each one through the screens
  or the database.
- It is not a penetration test by an outside party, an accessibility audit
  against WCAG with assistive technology, or a legal review. No
  certification and no compliance with any regulation (GDPR, CCPA, SOC 2,
  WCAG, PCI) is claimed.
- The privacy notice is the agency's own text. Crewvia shows it, records
  who agreed to which version, exports and anonymises. It does not write
  the notice, and none is published until an administrator does.

## How the review was made

Eight areas, each read in the code: tenant isolation, authorization,
sensitive data, uploads, sessions, the audit log, accessibility, privacy.
Every finding was reproduced or traced in the code before anything
changed. Two findings were false positives and were left alone:

- Two findings that the operations page acted on placements of other
  projects: every action there already refuses a placement outside the
  current project (`placements WHERE id=? AND job_id=?`).

## Verified findings and what was done

| # | Area | Finding | Severity | Remediation |
|---|---|---|---|---|
| 1 | Tenant isolation | A session id from one workspace was accepted under another's cookie name on the same server: sessions were not bound to their workspace | high | each session carries its workspace; a session presented to another is emptied and given a new id |
| 2 | Sessions | A session id the server never issued was adopted | medium | `session.use_strict_mode` |
| 3 | Sessions | No absolute lifetime: a session kept alive by activity lasted for ever | medium | 12 hours from sign-in (`session_absolute_hours` in the configuration), then sign in again |
| 4 | Sessions | Signing out destroyed the session but left the cookie | low | the cookie is expired |
| 5 | Sessions | The project switch followed `/\host`, which browsers read as `//host` | medium | backslashes and control characters refused; only a plain local path is followed |
| 6 | Headers | No content security policy, no HSTS, no permissions policy | medium | CSP (own scripts and styles, no framing, no plugins, no base, forms only to this site and the known providers); HSTS behind HTTPS; camera, microphone, location, payment, USB off |
| 7 | Passwords | Two length rules (10 on the account page and recovery, 12 on activation), and up to 200 bytes accepted where bcrypt silently ignores everything past 72 | medium | one rule everywhere: 12 characters at least, 72 bytes at most |
| 8 | Passwords | Hashes made at an older cost stayed at that cost | low | rehashed on the next successful sign-in |
| 9 | Two-step | Nothing could require two-step verification for the accounts that can change pay | medium | an administrator can require it for administrators and payroll; they are then held on the two-step page until it is on. An administrator who has not turned it on for themselves cannot require it |
| 10 | Sensitive data | A recruiter reading an employee folder saw the details of pay changes, deductions, loans and benefits in the history | medium | those entries read "(details for payroll only)" for anyone without payroll |
| 11 | Sensitive data | Payroll could type a worker's bank details and they were used at once: one person alone could send someone's pay to another account | high | payroll's entry is a proposal, like the worker's own, accepted on Change requests by another person |
| 12 | Sensitive data | An ADP employee id beginning with `=` reached the review CSV as a formula | medium | the cell is neutralised; the id is validated when typed and when imported |
| 13 | Sensitive data | The do-not-rehire register showed the separation's free-text detail | low | it shows the reason and the date, and says the account is in the HR record |
| 14 | Uploads | A signed contract PDF was accepted on its extension | medium | its content is checked (`application/pdf`) |
| 15 | Authorization | A supervisor opened the purchasing of any project. They also could not switch projects at all (the switch sent them back to My team), so they worked on whichever project opened by default | medium | purchasing only on a project they supervise a crew on, or whose budget they own; a supervisor opens on one of their own projects, the chooser lists only those, and switching to another is refused |
| 16 | Authorization | A supervisor could report a safety incident against any project | low | only a project they supervise a crew on |
| 17 | Audit log | Not recorded: failed sign-ins, failed two-step codes, throttling, recovery requests and resets, activations, accounts switched on and off, the old and new role, the permissions granted and revoked, CV, contract, crew-list and client-portal downloads, bank proposals and reveals | medium | all recorded, with the address each came from (failed attempts rate-limited so a flood cannot fill the log) |
| 18 | Audit log | The log could be changed or deleted by anyone with database access, the application included; no address; no screen to read it | medium | an `ip` column; database triggers that refuse any change or deletion; an Audit log page for administrators, filtered by person, action, record and dates; reading it is logged |
| 19 | Privacy | No privacy notice, consent recorded only as a sentence in an event, no subject export, no erasure, expired recovery links and nonces kept for ever | medium | a versioned notice at `/privacy`, linked from the public forms; each consent recorded against the version shown; Privacy page: publish, export everything held about a person as JSON (fingerprint logged), anonymise an applicant never placed; expired recovery links and old nonces purged by the workflows |
| 20 | Accessibility | No skip link, no visible focus on several controls, flash messages not announced, several contrasts under 4.5:1, inputs without labels on the timesheet and time-off grids, the language and project choosers submitting on change | medium | skip link and focusable main region; a 3 px focus outline; flash messages as alert/status; contrasts raised (navigation, amber and grey tags, input borders); labels on the grids and the bank fields; the choosers have a button |

## Deferred, not changed

- **Login throttling and lockout policy** (DEFERRED_SECURITY.md X6): an
  owner decision, left as it is.
- **Inline scripts.** The CSP still allows `'unsafe-inline'` for scripts
  and styles: the views use inline handlers. Moving them out is a separate
  piece of work. The CSP still stops framing, plugins, foreign scripts and
  base-tag tricks.
- **Labels on the remaining views.** The grids people use most were
  labelled. Roughly fifty older views were not reviewed field by field.
- **Report builder:** a GET marks a delivered report as downloaded. Not a
  security issue, left for the reports module.
- **Candidate retention.** How long an applicant's record is kept is the
  agency's decision. Crewvia anonymises on request; it does not delete on
  a timer.

## Effects on deploy

- **Everyone signs in again once.** Sessions now carry their workspace;
  the sessions open before the deploy carry none and are reset.
- Two-step verification is **not** required until an administrator
  requires it under Account security.
- No privacy notice is published until an administrator writes one under
  Privacy. Until then the public forms say there is none yet.
- If the database user cannot create triggers, the upgrade says so with a
  WARNING. The log then works but is not protected against change.

## Tests

`tests/security_review_db.php` (7 checks):

- P4-M06 in place; the rollback removes the privacy tables, the audit
  address column and the triggers, and keeps every audit entry;
- with the column gone, an entry is still written (without its address)
  rather than lost;
- the upgrade announces the review, puts the triggers back, and is silent
  the second time;
- the database refuses to change an audit entry;
- the fixture: two administrators, two payroll, a recruiter, a supervisor
  with a crew on one project and none on another, a placed worker with a
  pay change on record, an applicant never placed.

`tests/security_review_http.py` (60 checks):

- **Headers:** CSP, permissions policy; no HSTS over plain HTTP, HSTS
  behind HTTPS.
- **Sessions:** a forged session id not adopted; the project switch
  refusing `/\evil.test` and following a local path; sign-out expiring
  the cookie.
- **Passwords:** a wrong password refused and logged with its address; a
  cost-4 hash upgraded at sign-in; 11 characters refused, 73 bytes
  refused, the password unchanged.
- **Two-step:** the requirement needs the current password; an
  administrator without two-step cannot require it, and the refusal is
  announced (`role="alert"`); required, payroll is held on the two-step
  page and a recruiter is not; lifted, payroll works again.
- **Sensitive data:** a recruiter sees the folder history with the pay
  change hidden, payroll sees it; bank details payroll types are a
  pending proposal, logged with the last four digits only; the same
  person cannot accept them, a second payroll person does; an ADP id
  with a formula refused when typed, and one already stored written to
  the review CSV as text.
- **Supervisors:** they open on their own project, the chooser lists only
  theirs, switching to another is refused, their project's purchasing
  opens; once their crew leaves a project, its purchasing closes to them
  even though it is still their working project; an incident on a
  project they do not supervise is not recorded, one on theirs is.
- **Privacy:** the public page says no notice is published; the talent
  pool form links to it; a consent given before any notice is recorded
  against none; a short notice refused, version 1 published and shown;
  a later consent recorded against it; the subject export as JSON,
  logged with its fingerprint; a placed worker not anonymised; an
  applicant anonymised (name, email, phone, notes, CV text) and logged
  with the reason; a recruiter refused.
- **Audit log:** read and filtered by an administrator, with addresses;
  reading it logged; payroll refused; the database refusing a change and
  a deletion; the payroll review download logged.
- **Accessibility:** the skip link and focusable main region; the two
  choosers without auto-submit and with their buttons.
- **Translations:** the audit log in French, privacy requests in Spanish,
  the public notice in French.

Also:

- `tests/tenant_http.py`: an Alpha session id replayed under Bravo's
  cookie name is not signed in at Bravo (finding 1);
- `tests/hr_records_http.py`: the register gives the reason and date, not
  the separation's detail (finding 13);
- the access matrix: `/privacy-requests` and `/audit-log` for every role
  (26 checks: no PHP error, and only administrators open them);
- the CSRF sweep: `publish`, `anonymise` and `require_two_step`.

Two older fixtures were corrected along the way:

- `pay_periods_db.php` used weeks that fell inside the current week on a
  Sunday, and failed that day; its weeks are now 7 and 8 weeks back.
- `procurement_db.php` gives its supervisor a crew on the project, since
  a supervisor without one is now refused.

### A regression found by the tests, and fixed

The new supervisor scope first broke supervisors' purchasing altogether.
The project switch had always sent a supervisor back to My team, so they
worked on whichever project opened by default, usually not theirs. Before
this module that went unnoticed, because every project's purchasing was
open to them. A supervisor now opens on one of their own projects, can
switch between those, and is refused any other.

### Results

Full isolated run: **2080 PASS, 0 FAIL** (browser step skipped).

Mutation: I removed the binding of a session to its workspace (finding
1, the high one). The suite failed on "An Alpha session id replayed under
Bravo's cookie name is not signed in at Bravo", after 2077 passes.

### Not covered by an automated test

- The 12-hour absolute session lifetime. The code is short and was read,
  but no test waits 12 hours or ages a session file.
- Contrast and focus styles. They are CSS values, not checked by a test;
  nobody has measured them with an accessibility checker in a browser.
- The labels on the worker's own bank form, the timesheet and time-off
  grids, and the portal signature field.
- The signed contract PDF content check refusing a file that is not a
  PDF. The existing contract test uploads a real PDF, which passes the new
  check, but no test sends a renamed file.
- The purge of expired recovery links and nonces in the workflows. It runs
  in the suite (`run-workflows.php` twice) but nothing asserts what it
  removed.

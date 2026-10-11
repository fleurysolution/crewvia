# P4-M03 — report builder, filtering, role-controlled exports, scheduling, secure delivery

## What existed, verified before building

| Part | State before | Where |
|---|---|---|
| Ten reports with filters (period, date, year, project), their readers by role, printing | existed (P4-M01) | `/reports`, `app/reports.php` |
| Notifications inside Crewvia | existed | `notifications` |
| Command-line jobs run from the server | existed | `install/*.php`, CLI only |
| Outbound email | disabled by decision; the outbox exists but sends nothing | `install/deliver-emails.php` |
| Saved reports, choice of columns, conditions on rows, order, limit | **missing** | — |
| Export to a file, and who may export | **missing**: reports printed only | — |
| Schedules and delivery | **missing** | — |

## What was built

**Saved reports** (`/report-builder`, recruiting, payroll, administrators;
`app/report-builder.php`). A saved report is built on one of the ten
reports and keeps:

- the columns chosen;
- a period: fixed dates, or relative to the day it runs (today, this or
  last week, this or last month, the last 30 days, this or last year);
- a project;
- up to five conditions on its rows (is, is not, contains, more than, at
  least, less than, at most, empty, not empty; numbers compared as
  numbers);
- an order and a limit of 1 to 5,000 rows.

Totals are recomputed on the rows kept. When the limit cuts rows, the page
says how many of how many are shown.

Who opens what:

- A saved report is private to its owner unless shared.
- Even shared, it opens only for someone who may read its source report,
  so building a report never widens access.
- Only its owner or an administrator changes, schedules or archives it.
- Its source cannot be changed while it is scheduled.

**Exports.**

- The file is a CSV of exactly what the screen shows: column names, then
  raw values (dates as year-month-day, amounts as numbers).
- Text that a spreadsheet would run as a formula is neutralised. Numbers
  are not, so a negative amount stays a number.
- Every file is logged with who exported it, its filters, its row count
  and its SHA-256, which is also sent in a header.

**Who may export.**

- By default, whoever may read a report may export it.
- An administrator can narrow export, per report, to some of its reader
  roles, or to administrators only. A role refused export still reads the
  report on screen and is not offered the button; a direct request is
  refused.
- Administrators may always export.

**Schedules.**

- Daily, weekly (a weekday) or monthly (day 1 to 28, so every month has
  it), at an hour.
- For 1 to 20 staff members, each of whom must be able to both read and
  export the report. Workers, supervisors and clients never receive one.

**Delivery, inside Crewvia.** Outbound email stays disabled; nothing is
emailed.

- `install/run-report-schedules.php` runs each schedule that is due. It is
  meant for an hourly cron entry; an administrator can also click "Run what
  is due now".
- **Once per slot.** The slot is claimed by moving the schedule's next run
  forward, and a delivery is unique per slot. Running it twice delivers
  nothing twice.
- **Checked at run time.** If the owner may no longer read the report, the
  run is recorded as not done, with the reason. A recipient who may no
  longer read or export it is not sent it, and the reason is kept.
- **The file** is stored with its SHA-256, and each recipient gets a
  notification linking to it.
- **Downloading** requires being signed in as a recipient and still
  allowed to read and export the report. The file must match its
  fingerprint, within 30 days. Each download is recorded per recipient and
  in the export log.
- **After 30 days** the file itself is erased by the runner; the record of
  the delivery stays.

Schema:

- `report_definitions`
- `report_export_policy`
- `report_schedules`
- `report_deliveries`
- `report_delivery_recipients`
- `report_exports`

Rollback: `install/rollback/p4-m03.sql`, after removing the cron line if
one was added.

## Tests

`tests/report_builder_db.php` covers:

- rollback and upgrade, with an announcement that says nothing is
  emailed;
- a silent repeat;
- the runner from the command line;
- users, plus a person named like a spreadsheet formula, placed on the
  P4-M01 project.

`tests/report_builder_http.py`, on the P4-M01 February 2017 figures,
covers:

- **Refusals:** a report the role may not read, no column, a number
  compared with text, too many rows.
- **The saved report:**
  - only the columns kept;
  - sorted by payable, largest first;
  - totals of the rows kept (3,400 and 3,490);
  - a condition (payable more than 2,000);
  - a limit of 1, with the note.
- **Export:**
  - the exact CSV;
  - its SHA-256 in the header and in the log, with the user and row count;
  - the formula name neutralised.
- **Access:**
  - private to its owner;
  - shared, opened by another payroll user but still not by recruiting;
  - only the owner changes or archives it;
  - not listed for those who may not read it.
- **Export policy:**
  - only administrators change it;
  - narrowed, payroll still reads but cannot export, and an administrator
    still can;
  - restored.
- **Relative period:** last month worked out on the day.
- **Schedules:**
  - refused for a recipient who may not read the report, for a worker, for
    nobody, for the 31st of a month, and for a user who is not the owner;
  - weekly on Monday at 6, the next run in the future.
- **The runner:**
  - nothing due, then due: one delivery to two people, told in Crewvia;
  - the next run moved on;
  - nothing twice on a second run.
- **Download:**
  - the stored file with its fingerprint, recorded per recipient;
  - a non-recipient refused, administrator included;
  - a recipient whose role changed refused;
  - a tampered file refused (409), then restored.
- **Next slot:** a recipient who lost access is skipped with the reason and
  cannot download.
- **Expiry:** refused (410), and the runner erases the file.
- **Stopping and running by hand:**
  - a stopped schedule does not run;
  - only an administrator runs what is due from the page;
  - archiving stops the schedules.
- **Translations:** French and Spanish.

`/report-builder` is in the access matrix: the hotel desk, supervisors,
workers and clients cannot open it. The CSRF sweep covers `save`, `policy`
and `run_due`.

Full isolated run: **1871 PASS, 0 FAIL** (browser step skipped).

Mutation: I removed the check, at download time, that the recipient may still read and export the report. The suite failed on "A recipient whose role no longer reads the report is refused".

## Limits

- Built on the ten P4-M01 reports. The dashboards (P4-M02) are not
  sources yet.
- CSV only. A spreadsheet opens it; no PDF or XLSX is produced. Each report
  prints from its own page.
- Delivery is inside Crewvia, by design. Email stays disabled, so a
  recipient learns of a delivery on signing in. Turning email on for
  delivery would be a later, explicit decision, and still sending a link,
  never the file.
- **Cron:** the runner needs a cron entry to be on time. Adding it to the
  VPS is a server change left to you.
- **One instance per database:** in tenant mode, the runner runs for the
  database its configuration points at.
- **Where the files live:** delivered files are kept in the database for
  their 30 days. They are protected only as well as the database and its
  backups are; they are not encrypted separately.

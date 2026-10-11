# P4-M07 — concurrent load, backup restoration, disaster recovery, error monitoring, operational readiness

**What this module does and does not claim.**

- Every figure here was measured on the Windows test machine: Apache 2.4
  with mod_php, MariaDB 10.4, the test database after the suite and a
  10,000-worker synthetic fixture. None of it is a capacity figure for
  the production server. Nothing here was run against production.
- The concurrency tests show the application stays correct when people
  work at the same moment. They do not certify a number of users.
- The backup, restore and recovery tools were exercised end to end on the
  test server. On production they have not run yet. Their first run there
  is part of the deploy steps below, and the cron jobs are not scheduled
  until the agency sets them up.
- No outside monitoring service, off-server backup destination or alerting
  channel was connected. Each is named below as an agency decision.

## What existed, verified before building

| Area | State before | Where |
|---|---|---|
| Volume | 10,000 synthetic workers across 10 projects; pages timed one after another; `concurrent_load_certified: False` | `tests/scale_http.py` |
| Backup | a test-suite drill: mysqldump of the test database, restored into another, tables compared | `tests/backup_restore_drill.py` |
| Errors | PHP's own log (`error_log`); display off unless debug | `app/bootstrap.php` |
| Readiness | pre-flight checks: document root, private storage not served, debug, the key, external services | `install/preflight.php` |

What was missing:

| Gap |
|---|
| No test where people work at the same moment. PHP's built-in server, used by the suite, answers one request at a time on Windows |
| No backup tool for the server: nothing backed up the documents (`storage/`), nothing checked a backup, nothing restored one |
| No disaster-recovery procedure, and none ever rehearsed |
| No record of errors that an operator could read: no reference shown to the person, no grouping, no retention |
| No health endpoint, no health check for cron, no record of when backups or scheduled jobs last ran |
| No runbook: which cron jobs, where backups go, key custody, how to restore |

## What was built

**Error monitoring** (`app/operations.php`, installed by `app/bootstrap.php`):

- Every uncaught exception, and every fatal error, is written as one JSON
  line to `storage/logs/errors-YYYY-MM-DD.log`. That includes out of memory,
  for which the handler lifts the limit before writing. Each line holds:
  - when, and the request id;
  - the class, the message (500 characters at most), the file and line;
  - the method, the page path without its query string (links carry
    tokens), and the user id.
- The person sees "Something went wrong" and the 12-character reference,
  nothing else: no message, no class, no file. Every response carries the
  same id in `X-Request-Id`.
- A database that cannot be reached is logged too, without the connection
  details.
- The logs live in `storage/`, which is never served, and are deleted
  after 30 days by `install/run-workflows.php`.

**Health:**

- `/health`, public, for an outside uptime monitor: `200 {"status":"ok"}`,
  or `503 {"status":"down"}` when the database tables or the documents
  folder fail. No version and no detail.
- **System health** (administrators, under Administration):
  - the checks: database, storage, disk, debug, key, backup age, last
    drill, scheduled jobs, errors in 24 hours;
  - the backups on disk;
  - the record of runs;
  - the errors of 7 days, grouped by the place in the code.
- `install/health-check.php`, for cron, runs the same checks. With
  `--quiet` it prints only the lines that need looking at, so cron mails
  only then. Exit 0, 1 (failing) or 2 (needs looking at).
- `ops_runs` (migration `install/operations.sql`, rollback
  `install/rollback/p4-m07.sql`): each backup, drill, restore, health check
  and scheduled-job pass records when it ran, how long it took and how it
  went. A missing table never stops the job itself.

**Backup** (`install/backup.php`, `app/backup.php`):

- One folder per backup:
  - `database.sql.gz`: mysqldump with `--single-transaction`, including
    the audit-log triggers;
  - `storage.tar.gz`: every document;
  - `storage-files.json`: each document with its SHA-256;
  - `manifest.json`: the fingerprint of each file, every table with its
    exact rows and checksum, the triggers, the commit, and a check value
    of the key.
- The manifest is **signed** with a key derived from the encryption key.
  The fingerprints catch a damaged or incomplete copy; the signature
  catches a file and its fingerprint changed together.
- The encryption key is never in a backup.
- The password reaches mysqldump in a private options file, never on a
  command line. No shell is involved.
- Refused into a folder under `public_html`, `www`, `htdocs` or the
  application folder.
- Default: `backup_dir` in `config.php`, or `~/crewvia-backups`. It keeps
  14, is locked against two runs at once, and leaves nothing behind when
  it fails.

**Restore** (`install/restore.php`). Every mode first checks every
fingerprint, the signature and the key; a backup that fails is never used.

- `--check`: only that.
- `--drill --target-db=NAME`:
  1. imports into an empty scratch database (it refuses one that is not
     empty, and the live one);
  2. compares every table's rows and checksum, and the triggers;
  3. decrypts a bank record with the configured key;
  4. extracts every document and checks its SHA-256;
  5. empties the scratch database again.
- `--live --confirm=<db name>` (disaster recovery):
  1. takes a safety backup first, unless `--skip-safety-backup`;
  2. checks the documents before touching anything;
  3. empties the database and imports;
  4. swaps the documents folder in, keeping the old one beside it;
  5. compares everything;
  6. says when `upgrade.php` must run because the code is newer.

  If the import fails, the error names the safety backup to restore.
- Failures go to stderr, so cron mails them even with stdout discarded.

**Load** (`tests/apache_server.py`, `tests/load_http.py`):

- A private Apache 2.4 with mod_php on 127.0.0.1:8096, with its own
  configuration, pid and logs, and 64 threads. It honours the
  application's `.htaccess`, as production does.
- PHP's built-in server, used by the rest of the suite, answers one
  request at a time on Windows and could not show concurrency.

**Runbook** (`docs/OPERATIONS.md`):

- one-time setup: the backup folder, key custody, the drill database, the
  cron mail address;
- the five cron jobs;
- watching: System health, `/health`, finding an error by its reference;
- backups and the off-server copy;
- the monthly drill;
- disaster recovery, for lost data and for a lost server;
- deploy and rollback;
- what is not automated.

**Also in this module:** 15 files had their line endings rewritten by
earlier modules (13 in P4-M06, 2 in P4-M04). Each is restored to the
ending it had before. The content is unchanged: `git diff
--ignore-cr-at-eol` shows nothing for them.

## What the load test found, and what was fixed

The first concurrent run failed, and that failure is the main finding of
this module. Forty people at once, on the 10,000-worker fixture:

- the 95th percentile was 51 to 57 s, and the slowest request 105 to 109 s;
- in one run Apache cut a connection mid-answer: on Windows, PHP's time
  limit counts wall time, and Apache drops a reply stalled for 60 s.

Measured one cause at a time:

| Measurement | Alone | 8 at once | 40 at once |
|---|---|---|---|
| `/hours`, 1,000-person project, before | 2.3 s | 5.2 s | 61–70 s |
| a page that only computes (no database) | 1.0 s | | 7.3 s for all 40 |
| a page that sends 2,000 tiny queries | 1.4 s | 3.3 s | 29–36 s |

So the time went in database round trips. One view of `/hours` sent
**2,000 queries**: for every person, two lookups in `compensation_changes`
for a dated rate change, almost always finding none. `/roster` sent
**1,000**: one review lookup per person.

**Fixed:**

- `compensation_amount_on()` asks once per web request which assignments
  have any change. For the others, both lookups would find nothing, so
  they return at once. The list is cleared when a change is recorded or
  cancelled. It is not kept on the command line, where a script may write
  a change and read it back.
- The roster reads the project's reviews in one query; the database allows
  one review per assignment, so the result is the same.

| After | Alone | 40 at once |
|---|---|---|
| `/hours` | 0.4 s, 39 queries | 7.2 s for all 40 |
| `/roster` | 0.4 s, 39 queries | 12.5 s for all 40 |
| the 600-request mixed burst (all pages) | | two runs: median 1.7 and 2.6 s, 95th percentile 14.1 and 23.6 s, slowest 25.6 and 42.7 s (before: 57 s and 105 s) |

**The rest is this machine.** On XAMPP for Windows:

- Apache runs every thread in one process.
- MariaDB uses a thread pool over loopback TCP. Each round trip costs
  about 0.7 ms, and the 40 connections together top out near 2,200 small
  queries a second.
- Even a page that does nothing but compute gets about 5.5 times the work
  done with 40 at once, on 32 logical cores.

A Linux server is built differently: PHP in separate processes, and the
database over a local socket. Its figures will differ and have not been
measured.

**Also found, not changed:** the login limiter counts every attempt
exactly. In a race, though, it can read a count other requests have
already raised, and throttle early: 6 attempts reached the password check
and 14 were throttled, where 10 and 10 were expected. That is the safe
direction, since never more than 10 get through, so it was left as it is.

## Tests

`tests/operations_db.php` (12 checks):

- rollback and upgrade of `ops_runs`;
- a job that still runs while the table is gone;
- silence on a repeat;
- a run recorded with its duration and host;
- the backup-folder refusals (`public_html`, `www`, `htdocs`, the
  application folder);
- the database host read with its port.

`tests/operations_http.py` (22 checks):

- **Errors:**
  - an exception, an undefined function and an out-of-memory fatal, each
    logged once under the reference the person is shown, with class,
    message, file, line and path;
  - the error page shows nothing of the error;
  - the query string is never logged;
  - every page carries its request id.
- **Health:**
  - `/health` returns `{"status":"ok"}` and nothing more;
  - the workflow pass and the report schedules record their runs;
  - error logs past 30 days are purged and recent ones kept;
  - System health lists every check, with what is true of the test install
    (debug on: failing), the errors grouped, and the runs;
  - a recruiter is refused;
  - the cron health check and `--quiet`, with their exit codes.
- **Translations:** French and Spanish.

`tests/disaster_recovery_drill.py` (27 checks, after the whole suite has
filled the test database):

1. **The backup:** four files, each fingerprinted; all 203 tables with rows
   and checksums, and the triggers; all 7 documents. The encryption key
   is nowhere in the dump, the archive or the manifest, and the dump is
   complete.
2. **Refusals:** a damaged copy; files and fingerprints changed together
   (the signature); another key configured.
3. **The drill:** passes, then empties the scratch database. It refuses a
   database that is not empty, and the live one. A live restore without
   the name repeated is refused.
4. **The disaster:** the test database dropped and the documents folder
   deleted. `/health` says down. The live restore brings both back, and
   `/health` says up. Every count is what it was, every document is back
   byte for byte, an administrator signs in and works, and the audit log
   is still append-only.
5. **Then:** a live restore with its safety backup, which keeps the folder
   it replaced; retention keeping 2; the tool refusing `public_html`.

The rest of the suite then runs on the restored database: the
relationship check, the 10,000-worker scale test, the load test and the
tenant isolation tests.

`tests/load_http.py` (13 checks, on the private Apache, after the
10,000-worker scale fixture):

- the shipped `.htaccess` rules hold, which PHP's built-in server never
  tested;
- 40 sign-ins at the same moment;
- `/hours` and `/roster` for a 1,000-person project: 39 queries each;
- 600 requests by 40 people at once: all 200, no PHP error, no error page;
- every audit-log read under load is in the audit log (40 of 40);
- 12 people accept one bank change at the same instant: 1 succeeds, 11 are
  refused, and one record and one audit entry result;
- 20 wrong passwords at once: all refused, exactly 20 counted, at most 10
  reach the password check, all logged; then the right password is
  refused;
- nothing written to the error log.

The access matrix covers `/system-health` for every role.

### Results

Full isolated run: **2157 PASS, 0 FAIL** (browser step skipped), and again on the pushed commit 8f45023: **2157 PASS, 0 FAIL**, with backup 3.6 s, drill 12.7 s, recovery 7.5 s.

Measured in that run, on this machine:

| | |
|---|---|
| backup | 3.5 s |
| restore drill | 11.3 s |
| recovery from total loss | 7.2 s (203 tables, 7 documents) |
| 600 requests, 40 people at once | 9.6 a second; median 1.7 s, 95th percentile 14.1 s, slowest 25.6 s. The run on the pushed commit: 6.1 a second; median 2.6 s, 95th percentile 23.6 s, slowest 42.7 s. The spread is this machine's, from run to run |
| slowest pages under that load (median) | `/overview` 13.9 s, `/organization` 11.7 s, `/roster` 6.9 s, `/hours` 5.2 s |

`/organization` sends 10.6 MB of HTML for the 10,000-worker fixture, and
`/overview` adds up every project. Neither was changed; both are named in
the open items.

Mutation: I turned the per-request list of assignments with rate changes
off, which puts back two lookups per person. The suite failed on "/hours
for a 1,000-person project asks the database 2038 times", after 2136
passes.

### Not covered by an automated test

- Anything on the production server (see above).
- The cron schedule itself, and cron mail: they belong to the server.
- An off-server copy: none exists.
- The safety backup of a live restore when the database is gone
  (`--skip-safety-backup` is the tested path for that case).
- A backup of another workspace (tenant). The tool backs up the workspace
  its configuration names; the base workspace's backup leaves other
  workspaces' folders out, and a restore puts them back untouched. Only
  the base workspace was exercised.

## Before going live, for real

On the server, after this deploy:

1. **Set the backup folder.** Add `'backup_dir' => '/home/veloraweb/crewvia-backups'`
   to `config.php`. Then take the first backup, check it, and run a health
   check:

   ```bash
   php install/backup.php
   php install/restore.php --check latest
   php install/health-check.php
   ```

   The health check will still say the cron jobs were never recorded,
   until they are set.
2. **Key custody.** Copy the `encryption_key` from `config.php` into the
   agency's password manager. It is not in any backup, and a backup cannot
   be read without it.
3. **Drill database.** In cPanel, create `veloraweb_crewvia_drill` and give
   Crewvia's database user all rights on it. Then run the first drill:

   ```bash
   php install/restore.php --drill --target-db=veloraweb_crewvia_drill latest
   ```

4. **Cron jobs.** Set the five jobs in `docs/OPERATIONS.md` section 2, and
   set the cron email address.
5. **Agency decisions**, not done here:
   - **An off-server copy of the backups.** A backup on the VPS does not
     survive losing the VPS. This is the largest gap left.
   - **An outside uptime monitor** on `https://<site>/health`.

Not covered:

- No test on the production server itself: its speed under load, how long
  its backup and restore take, its cron.
- The error log has no alerting of its own. The hourly health check mails
  when errors appear (`warn`) or pass 50 in a day (`fail`).
- `/health` checks only the database and the documents folder, so it
  stays cheap to call every minute. The deeper checks are on System
  health and in the cron health check.
- Restoring one table or one document is not built: a restore is the
  whole backup. A drill database (`--keep-target`) can be used to look up
  a single record by hand.

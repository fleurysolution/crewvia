# Crewvia — operations runbook

What has to run, where things are, and what to do when something breaks.
Written for the production install at `/home/veloraweb/public_html/crewvia`
(cPanel on the agency's VPS). Every command runs as the cPanel account
user, never as root, from the application folder.

## 1. One-time setup

1. **Backup folder outside the web root.** In `config.php` add:

   ```php
   'backup_dir' => '/home/veloraweb/crewvia-backups',
   ```

   The backup tool refuses any folder under `public_html`, `www` or
   `htdocs`, or inside the application folder.

2. **Keep the encryption key off the server.** Copy the `encryption_key`
   value from `config.php` into the password manager the agency already
   uses. It is never in a backup. Without it, a backup's documents and
   bank details cannot be read: the backup is useless.

3. **A scratch database for restore drills.** In cPanel, open **MySQL
   Databases** and create a database `veloraweb_crewvia_drill`. Give the
   same database user Crewvia uses **ALL PRIVILEGES** on it. It stays
   empty between drills.

4. **Find the PHP that cron should use:**

   ```bash
   command -v php
   ```

   Use that full path in the cron lines below. The examples use
   `/usr/local/bin/php`.

5. **Where cron's mail goes.** In cPanel, open **Cron Jobs** and set
   **Cron Email** to an address someone reads. Every job below runs with
   `--quiet` or prints only on problems, so a mail means something needs
   looking at.

## 2. Scheduled jobs (cPanel → Cron Jobs)

| When | Command | What it does |
|---|---|---|
| every 15 minutes | `cd /home/veloraweb/public_html/crewvia && /usr/local/bin/php install/run-workflows.php > /dev/null` | renewal and follow-up alerts, expiries, the purges (expired links, nonces, error logs past 30 days) |
| every 15 minutes | `cd /home/veloraweb/public_html/crewvia && /usr/local/bin/php install/run-report-schedules.php > /dev/null` | scheduled reports |
| 02:30 every night | `cd /home/veloraweb/public_html/crewvia && /usr/local/bin/php install/backup.php --quiet` | database and documents, 14 kept |
| every hour | `cd /home/veloraweb/public_html/crewvia && /usr/local/bin/php install/health-check.php --quiet` | mails only when a check fails or needs looking at |
| 1st of the month, 03:30 | `cd /home/veloraweb/public_html/crewvia && /usr/local/bin/php install/restore.php --drill --target-db=veloraweb_crewvia_drill latest > /dev/null` | proves the latest backup restores; failure is mailed (it prints to stderr) |

The workflow and report jobs send their normal output to `/dev/null`.
Their failures still reach cron mail through stderr, and System health
shows when each last ran.

## 3. Watching it

- **System health** (administrators, under Administration) shows:
  - every check;
  - the backups on disk;
  - the record of backups, drills and jobs;
  - the errors of the last 7 days, grouped by the place in the code.
- **`/health`** answers `200 {"status":"ok"}` or `503 {"status":"down"}`
  and nothing more. Point an outside uptime monitor at
  `https://<site>/health`, so an outage is noticed even when the server
  itself cannot send mail. Choosing that service is the agency's
  decision; none is connected.
- **Errors.** A person who hits an error sees "Something went wrong" and
  a 12-character reference. Search System health, or the log, for it:

  ```bash
  grep '"request_id":"<reference>"' storage/logs/errors-*.log
  ```

  The log is one JSON line per error: when, the reference, the class, the
  message, the file and line, the page path (never its query string), and
  the user id. It lives in `storage/logs`, which is never served. Each log
  is kept for 30 days.

## 4. Backups

- **What one holds:**
  - the database (mysqldump, with the audit-log triggers);
  - the documents folder;
  - a list of every document with its SHA-256;
  - a manifest with each file's fingerprint, every table with its rows
    and checksum, and the code commit.

  The manifest is signed with a key derived from the encryption key, so a
  backup altered after the fact is refused.
- **Where:** `/home/veloraweb/crewvia-backups/crewvia-YYYYMMDD-HHMMSS/`.
  The folder is private to the account, and the newest 14 are kept.
- **Off the server: not done yet, and it matters.** A backup on the VPS
  does not survive losing the VPS. Copy the backup folder elsewhere every
  night, for example:
  - WHM's **Backup Configuration** with an additional destination
    (SFTP, S3, Backblaze);
  - an `rsync` from another machine.

  Choosing the destination is the agency's decision. Until one exists,
  the recovery point for a lost server is whatever was last copied off it
  by hand.
- **Check a backup without restoring it:**

  ```bash
  php install/restore.php --check latest
  ```

## 5. Restore drill (monthly, or after any change to the tools)

```bash
php install/restore.php --drill --target-db=veloraweb_crewvia_drill latest
```

The drill:

1. imports into the scratch database;
2. compares every table with the manifest;
3. decrypts a bank record with the configured key;
4. extracts and checks every document;
5. empties the scratch database again.

Nothing of the live site is touched. The result appears under System
health ("restore drill"). When the backup was taken while people were
working, a table can differ by the rows written during the dump: the drill
lists which ones.

## 6. Disaster recovery

**The database or the documents are lost or damaged; the server works.**

1. Tell the office the site is down. Stop the cron jobs (comment them
   out) so nothing writes during the restore.
2. Check the backup:

   ```bash
   php install/restore.php --check latest
   ```

3. Restore:

   ```bash
   php install/restore.php --live --confirm=<db_name from config.php> latest
   ```

   It first takes a safety backup of whatever is there. Add
   `--skip-safety-backup` only if the database is gone entirely. It then:
   - empties the database and imports the backup;
   - puts the documents back, keeping the old folder beside them as
     `storage.before-restore-…`;
   - compares everything with the manifest.
4. If it says the code is newer than the backup:

   ```bash
   php install/upgrade.php
   ```

5. Check `/health`, sign in, open a few records. Put the cron jobs back.
6. Once confirmed, delete `storage.before-restore-…`.

**The server is lost.**

1. Get a new server with PHP 8.2 and MariaDB.
2. Deploy the code:

   ```bash
   git clone https://github.com/fleurysolution/crewvia.git
   ```

   Then check out the backup's commit, which is in its `manifest.json`.
3. Write `config.php` with the **original encryption key** from the
   password manager and the new database settings. Create the empty
   database.
4. Copy the latest off-server backup to the new server, and run the
   restore with `--skip-safety-backup` (step 3 above).
5. Then:

   ```bash
   php install/upgrade.php
   php install/preflight.php https://<site>
   ```

   Point the domain at the new server, and set up the cron jobs again.

Measured on the test machine (see the P4-M07 report): a full recovery of
the test database and documents takes seconds. On the production server
it depends on the size of the database and documents. Run a drill to know
the real figure.

## 7. Deploy and roll back

- **Deploy:**

  ```bash
  cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h && php install/upgrade.php && php install/upgrade.php
  ```

  The second upgrade must print nothing new.
- **Before a deploy that changes the database:**

  ```bash
  php install/backup.php
  ```

- **Roll back a module:**
  1. Restore the previous code:

     ```bash
     git checkout <previous commit>
     ```

  2. Run the module's `install/rollback/<module>.sql`. Each file says
     what it loses.
  3. If data must also go back, use the live restore of the backup taken
     before the deploy.

## 8. What is not automated

- The off-server copy of backups (section 4): the agency chooses the
  destination.
- The outside uptime monitor on `/health` (section 3): the agency chooses
  the service.
- Rotating the encryption key: not supported. A new key cannot read
  existing documents or backups.

# Staging copy of Crewvia, for the demonstration films (P5-M107)

Firmin's decision of 23 Oct 2026: Part 2 of the films (hiring, time, pay, billing) is recorded on a **copy** of
production, never on production. A test person can then be hired, clock in, be paid and be invoiced without touching
RSS's ledger, invoices or payroll.

**The copy holds RSS's personal data.** It is acceptable only under these four conditions, which are all built into
the steps below:

1. **A password over the whole site,** careers page included, set in the web server (Apache) and not in Crewvia.
2. **No way to send anything.** No email (`outbound_disabled` in the code, and sending switched off), no text messages, no
   phone notifications (their keys are deleted from the copy), no connection to the hub, no scheduled tasks.
3. **A deletion date, set before creation: 14 November 2026.** Production's System health warns while the copy
   exists, and fails after that date.
4. **noindex** (search engines told to stay away).

---

## 0. Before anything: read the teardown (section C)

The copy lives next to production Crewvia, on a server that also holds bpms247 and letakunga. Every command below
names **the machine** and **the exact path**. The teardown is written now, so it is not improvised at the end.

| What | Value |
|---|---|
| Machine | **server1** (VPS), as root: `[root@server1 ~]#` |
| Production (do not touch) | `/home/veloraweb/public_html/crewvia` — https://crewvia.bpms247.com |
| Staging folder (new) | `/home/veloraweb/crewvia-staging` (outside `public_html`) |
| Staging address (new) | https://crewvia-staging.bpms247.com |
| Staging database (new) | `veloraweb_crewvia_stg`, its own user `veloraweb_crewstg` with rights on that database **only** |
| Password file (new) | `/home/veloraweb/.htpasswd-crewvia-staging` |
| Deletion date | **2026-11-14** |

> The address is a subdomain of bpms247.com: adding it needs one DNS record in the bpms247.com zone (Cloudflare). If
> you prefer not to touch that zone, use another domain you hold; only the address changes below.

---

## A. Creating the copy

### A1. Production: register the deletion date (machine: server1, file: production config)

```bash
nano /home/veloraweb/public_html/crewvia/config.php
```
Before the final `];`, add:
```php
    'staging_watch' => ['path' => '/home/veloraweb/crewvia-staging', 'delete_by' => '2026-11-14'],
```
Then check production still reads its config:
```bash
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php -r 'require "/home/veloraweb/public_html/crewvia/app/bootstrap.php"; echo $config["staging_watch"]["delete_by"], PHP_EOL;'
```
It should print `2026-11-14`.

### A2. cPanel (veloraweb account): the database

In cPanel → **MySQL Databases**:

1. Create the database `crewvia_stg`; cPanel names it `veloraweb_crewvia_stg`.
2. Create the user `crewstg` (`veloraweb_crewstg`) with a password you generate. Keep it in your password manager.
3. Add **that user** to **that database only**, with ALL PRIVILEGES.

Do not give the production user any rights on it, and do not give this user any rights on production. That way a
mistake in the staging config cannot reach production's data.

### A3. The code (machine: server1)

```bash
sudo -u veloraweb git clone https://github.com/fleurysolution/crewvia.git /home/veloraweb/crewvia-staging
sudo -u veloraweb git -C /home/veloraweb/crewvia-staging log -1 --format=%h
```
The second command must show the same commit as production (`git -C /home/veloraweb/public_html/crewvia log -1 --format=%h`).

### A4. Staging config (machine: server1, file: staging config — **not** production's)

First display production's encryption key, which the copy needs to read the documents. Copy the line; do not paste
it anywhere else.
```bash
grep encryption_key /home/veloraweb/public_html/crewvia/config.php
```
Then create the staging config:
```bash
sudo -u veloraweb nano /home/veloraweb/crewvia-staging/config.php
```
Content (fill in the two placeholders):
```php
<?php return [
    'db_host'  => 'localhost',
    'db_name'  => 'veloraweb_crewvia_stg',
    'db_user'  => 'veloraweb_crewstg',
    'db_pass'  => 'THE PASSWORD FROM A2',
    'app_url'  => 'https://crewvia-staging.bpms247.com',
    'app_name' => 'Crewvia (staging copy)',
    'debug'    => false,
    'encryption_key' => 'THE VALUE FROM PRODUCTION',
    // Staging sends nothing. outbound_disabled cuts email, texts, calls and phone notifications in the code itself,
    // whatever the screens or the database say. No email_from, fs_hub_secret, adp_api, recruiting_webhooks,
    // google_client_id or staging_watch. Do not add them.
    'outbound_disabled'      => true,
    'email_delivery_enabled' => false,
];
```
Check that the file points at the staging database:
```bash
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php -r 'require "/home/veloraweb/crewvia-staging/app/bootstrap.php"; echo val("SELECT DATABASE()"), PHP_EOL;'
```
It must print `veloraweb_crewvia_stg`. **If it prints production's database name, stop.**

### A5. Cut every way out, before any data arrives (machine: server1, staging folder)

The password and noindex. In the staging `.htaccess`, `Require all granted` must be **replaced**: added next to a
password, it would let everyone in.
```bash
cd /home/veloraweb/crewvia-staging/public
sudo -u veloraweb sed -i 's#^Require all granted$#AuthType Basic\nAuthName "Crewvia staging"\nAuthUserFile /home/veloraweb/.htpasswd-crewvia-staging\nRequire valid-user\nHeader always set X-Robots-Tag "noindex, nofollow"#' .htaccess
grep -n "Require\|Auth\|X-Robots" .htaccess
```
You must see `Require valid-user`, and **no** `Require all granted`. If the site answers with an error 500 once it is up (A6), the server lacks the headers module: delete the `Header always set …` line and keep the rest.

Create the password (you choose it; keep it in your password manager):
```bash
$(command -v htpasswd || echo /usr/local/apache/bin/htpasswd) -c /home/veloraweb/.htpasswd-crewvia-staging firmin
chown veloraweb:veloraweb /home/veloraweb/.htpasswd-crewvia-staging && chmod 640 /home/veloraweb/.htpasswd-crewvia-staging
```

**No cron line for the staging folder.** Nothing to add to the crontab.

### A6. The address (cPanel and Cloudflare)

1. cPanel → **Domains** → Create: `crewvia-staging.bpms247.com`, document root
   `/home/veloraweb/crewvia-staging/public`.
2. Cloudflare, zone bpms247.com: a record `crewvia-staging`, the same as the one for `crewvia`.
3. Wait for SSL (AutoSSL / Cloudflare).
4. Open https://crewvia-staging.bpms247.com/careers in a private window. **The browser must ask for the password
   before showing anything.** If the careers page shows without it, stop and go back to A5.

### A7. The data: a fresh backup of production, restored into the copy (machine: server1)

A fresh backup of production (it changes nothing in production):
```bash
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php /home/veloraweb/public_html/crewvia/install/backup.php
```
Restore it **from the staging folder**, into the staging database (the command names it twice):
```bash
cd /home/veloraweb/crewvia-staging && sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php install/restore.php --live --confirm=veloraweb_crewvia_stg --skip-safety-backup --dir=/home/veloraweb/crewvia-backups latest
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php /home/veloraweb/crewvia-staging/install/upgrade.php
```
`restore.php` checks the backup against its fingerprints, then compares every table with the backup's manifest, and
ends with exit status 0 when everything matches.

### A8. Remove the credentials for texts, phones and connections from the copy (machine: server1, staging only)

```bash
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php -r 'require "/home/veloraweb/crewvia-staging/app/bootstrap.php"; if (val("SELECT DATABASE()") !== "veloraweb_crewvia_stg") { exit("NOT STAGING - stopped\n"); } foreach (["provider_credentials", "push_subscriptions", "push_keys", "integration_tokens"] as $t) { try { q("DELETE FROM $t"); echo $t, " emptied\n"; } catch (Throwable $e) { echo $t, ": ", $e->getMessage(), "\n"; } } foreach (["candidate_mail_test" => "1", "office_mail_test" => "1", "office_notice_test" => "1"] as $k => $v) { q("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$k, $v]); } echo "test mode and rehearsal on\n";'
```
The command refuses to run if it is not connected to the staging database.

### A9. Prove it is a copy (machine: server1)

The same counts on both sides, one right after the other:
```bash
for D in /home/veloraweb/public_html/crewvia /home/veloraweb/crewvia-staging; do sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php -r 'require $argv[1] . "/app/bootstrap.php"; echo str_pad(val("SELECT DATABASE()"), 28); foreach (["candidates","applications","vacancies","placements","users","clock_ins","timesheets","billing_events"] as $t) { echo " ", $t, "=", val("SELECT COUNT(*) FROM $t"); } echo PHP_EOL;' "$D"; done
```
The two lines must show the same numbers. A difference of one or two candidates or applications is possible if
somebody applied in production between the backup and this command. Anything else: stop.

### A10. Final checks

1. In production, Administration → **System health**: `staging_copy` shows a warning, "a staging copy exists at …:
   delete it by 2026-11-14".
2. In staging (after the password), **Email delivery**: test mode on, rehearsal on. "Send now" must not send: email is
   not switched on. Even if somebody changes the settings in staging, `outbound_disabled` in its config stops
   every email, text and phone notification in the code.
3. In staging, **Administration → Integrations / Twilio**: no credentials.

---

## B. During the films

- Only on https://crewvia-staging.bpms247.com, with the test accounts (solutionsfleury+label@gmail.com).
- The emails go nowhere (staging cannot send). For the "email received" clip, film Email delivery or the requisition's
  trail. For an email in a real inbox, film it in production during Part 1 (rehearsal).

---

## C. Teardown — by 14 November 2026 at the latest (read before section A)

Run in this order, on **server1**.

1. **The folder.** Check the path first, then remove it.
```bash
ls -d /home/veloraweb/crewvia-staging && sudo -u veloraweb git -C /home/veloraweb/crewvia-staging remote get-url origin
```
It must print the staging folder and the crewvia repository. Only then:
```bash
rm -rf --one-file-system /home/veloraweb/crewvia-staging
```
**Never** run `rm` on a path containing `public_html`.

2. **The password file:**
```bash
rm -f /home/veloraweb/.htpasswd-crewvia-staging
```
3. **cPanel → MySQL Databases:** delete the database `veloraweb_crewvia_stg`, then the user `veloraweb_crewstg`.
4. **cPanel → Domains:** delete `crewvia-staging.bpms247.com`. **Cloudflare:** delete the `crewvia-staging` record.
5. **Check that nothing is left:**
```bash
ls -d /home/veloraweb/crewvia-staging /home/veloraweb/.htpasswd-crewvia-staging 2>&1
```
Both must answer "No such file or directory". In production, **System health** → `staging_copy` must read "no staging
copy at /home/veloraweb/crewvia-staging".

6. Production config: the `staging_watch` line can stay (it then reads "no staging copy"), or be removed.

The fresh backup taken in A7 stays among production's backups, like the others. It is production's own, not a copy
outside its place.

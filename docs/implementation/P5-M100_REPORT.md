# P5-M100 — Verifiable billing, and approved people waiting for their next step

## What RSS is billed for

Crewvia is billed **per person paid in the calendar month**. A person counts when, in that month, they have at least one of:

- a day validated by a supervisor (validated clock-in, or approved attendance day);
- a line in a pay cycle (approved or paid weekly timesheet, or payroll adjustment).

Each person counts once, whatever the number of sites or days. Someone assigned who never worked costs nothing, and so do candidates and office staff. The price is $10 a person with a $2,500 monthly minimum (server config `billing_rate_per_person`, `billing_monthly_minimum`).

## The ledger (`billing_events`)

- **Append-only.** Each source record is written once (`UNIQUE(source_table, source_id)`), oldest first, under a named lock.
- **Chained.** Each line carries the SHA-256 of the line before (`prev_hash`) and its own hash over every field. A line removed or changed breaks the chain at that line.
- **Guarded by the database.** Triggers refuse `UPDATE` and `DELETE` on `billing_events` and `billing_closings` (`SQLSTATE 45000`), for every database account, root included. Proof from the test run, as `root@localhost`:

  ```
  DELETE FROM billing_events WHERE id = …
  -> SQLSTATE[45000]: 1644 Billing events cannot be deleted
  UPDATE billing_events SET candidate_id = … WHERE id = …
  -> SQLSTATE[45000]: 1644 Billing events cannot be changed
  ```

- **Checked.** `bl_verify()` recomputes the whole chain, then checks every sealed month against the ledger: its last line must still exist and its figures and fingerprint must still match.

### The limit

A database administrator (Fleury Solutions, the host) can drop a trigger or `TRUNCATE` the table, since `TRUNCATE` does not fire triggers. That is caught, not prevented:

- the chain check finds a changed line, at that line;
- the sealed-month check finds lines cut from the end, or an emptied ledger;
- Fleury keeps its own copy of each month's fingerprint (read through the hub endpoint).

The tests drop the trigger, change a line, cut the tail and empty the table, and the check finds each case. RSS has no database access.

## The monthly seal (`billing_closings`)

On the 1st, `install/billing-close.php` (daily cron) seals every finished month that is not sealed yet. A sealed month stores:

- people;
- records;
- the last chain number;
- a fingerprint: SHA-256 of `month|people|records|last_chain_no|last_line_hash`;
- the time of sealing (UTC).

Once sealed, a month is never recomputed, and it is never sealed twice. A day validated late for a sealed month is written to the ledger, shown on the statement as "validated after the month was sealed", and never added to the sealed count.

## Screens

- **Administration → Crewvia subscription** (`/crewvia-billing`, owner only):
  - this month so far, with the amount at this count;
  - the chain check;
  - sealed months, with amount and fingerprint;
  - a "Seal now" button for last month, if the job has not run.
- **Statement** (`/crewvia-billing?month=YYYY-MM`):
  - one line per person, with projects, days validated, pay-cycle lines, first and last day;
  - Download CSV, or Print / save as PDF. The print carries the Fleury Solutions letterhead.
- **Approved, next step** (`/follow-up`, recruiters and HR, linked from Hiring):
  - covers people a hiring manager approved who are not deployed and not declined;
  - counters per requisition: approved, deployed, waiting, and no contact since approval (7, 14, 30 or 60 days);
  - the list of people ready for a call;
  - counters and names only: no alert, and nothing that points at the client.

## Hub endpoints (contract v1.1, section 7)

`GET /api/billing-count?month=YYYY-MM` and `GET /api/revenue?month=YYYY-MM`.

**Request**

- Headers: `X-FS-Product: crewvia`, `X-FS-Timestamp`, `X-FS-Signature: sha256=hex(HMAC-SHA256(secret, timestamp + "." + raw query))`.
- The secret is `FS_HUB_SECRET_CREWVIA` in the server environment. `.env.example` shows its form, and the value is never in the repository.

**Errors**

- 401 `bad_signature` or `stale_timestamp` (over 300 s);
- 404 `unknown_product`;
- 400 `bad_request`;
- 500 `server_error` (no secret configured).

The signature is checked first: a caller without the secret only ever gets `401 bad_signature`, whatever else is wrong (method, product, timestamp, month). The other codes go to an authenticated caller only, as the contract now requires of everyone.

**`billing-count`** returns `product, client, month, billable_people, event_count, ledger_hash, sealed_at`. It carries no names and no personal data. On an open month, `ledger_hash` and `sealed_at` are `null`.

**`revenue`**

- `monthly_recurring` is the subscription.
- `one_time` is `0.00`: one-time work is in the hub's own quotes.
- `checksum` = SHA-256 of `product|month|currency|monthly_recurring|one_time|active_subscriptions|generated_at`, with the amounts written with two decimals (`2500.00`).

## Files

| Area | Files |
|---|---|
| Migration | `install/upgrade.php` (tables and triggers); rollback `install/rollback/p5-m100.sql` |
| Logic | `app/billing-ledger.php` |
| Hub endpoints | `app/pages/api-billing.php` (early route in `public/index.php`) |
| Screens | `app/pages/crewvia-billing.php`, `app/views/crewvia-billing.php`, `app/pages/follow-up.php`, `app/views/follow-up.php`; link in `app/views/hiring.php`; menu in `app/navigation.php` |
| Cron job | `install/billing-close.php` |
| Configuration | `.env.example`; `.env` ignored |
| Translations | FR and ES strings in `app/lang/messages.tsv` |
| Tests | `tests/billing_ledger_db.php` (22 checks), `tests/billing_http.py` (24 checks); `access_matrix_http.py` updated |

## Deploy

```
cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h && php install/upgrade.php
```

1. Put `FS_HUB_SECRET_CREWVIA=<secret>` in the server environment, or in the config as `fs_hub_secret_crewvia`. Use the same value as the hub.
2. Add the cron line: `5 0 * * * php /home/veloraweb/public_html/crewvia/install/billing-close.php`.
3. If `upgrade.php` warns that the triggers were not created (no TRIGGER privilege), grant it and run it again.

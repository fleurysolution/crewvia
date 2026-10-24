# P5-M108 — The billing close reports its own outcome

## Why

`install/billing-close.php` seals each month on the 1st: October on 1 November 2026. It reported nothing by itself: one
line in the operations log, and one line for cron. The veloraweb crontab has `MAILTO=""`, so that line goes nowhere.
Even with a MAILTO, cron's own mail leaves from the server's hostname, unsigned, a path Gmail has already refused on
this server.

A close that did not run looked exactly like a close that ran and found nothing to seal.

## What

The job now mails its outcome through Crewvia's own mailer (`mail_queue`, then `mail_deliver` at once), the
DKIM-signed path proven to reach Gmail. The address is the server config's `billing_report_to`: Fleury Solutions',
never RSS's.

**When it mails:**

- on any seal;
- on any failure;
- on the 1st of every month, always, even with nothing to seal;
- on demand, with `--report`.

Other days stay silent.

**What it says:**

- OK or FAILED, and if FAILED, what failed;
- for each month sealed: billable people, records, amount, fingerprint (`ledger_hash`) and the UTC time of sealing;
- the state of every month that was due before the run (sealed or NOT sealed), and of last month;
- the records written in the run, the chain check, and this month so far.

**The failure test.** `--arm-failure-test` makes the next run fail on purpose, after writing the records and before
sealing anything. That run mails FAILED with each month's state, exits 1 and disarms itself.

**Safety:** it cannot fail silently. An error at any step is caught, recorded in the operations log as "failed", and
mailed.

## Configuration

In production's `config.php`:

```php
    'billing_report_to' => 'solutionsfleury+billing@gmail.com',
```

By rule, the address in `billing_report_to` is never rewritten by test mode or a rehearsal, whatever it is. The report is Fleury's own instrumentation, not a client's mail.
Without the key, the job says "No report sent: set billing_report_to in the server config".

## Proof on the server (server1), after deploying

```bash
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php /home/veloraweb/public_html/crewvia/install/billing-close.php --arm-failure-test
sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php /home/veloraweb/public_html/crewvia/install/billing-close.php
grep "solutionsfleury+billing" /var/log/exim_mainlog | tail -3
```

The second command prints FAILED and "Report to …: 1 sent". In the exim log, look for the `=>` line, not a `**`
refusal. Then check the inbox.

## Tests

`tests/billing_close_db.php`, 9 checks:

- the arm;
- the deliberate failure: exit 1, nothing sealed, the FAILED mail with "State of 2024-03: NOT sealed";
- the arm is spent;
- the next run seals and mails OK with people, records and the fingerprint;
- an ordinary day stays silent (the 1st always reports);
- `--report`;
- the report address is never rewritten, even during a rehearsal and whatever its spelling.

# P5-M47 — Candidates' emails in test mode until the agency validates its flows; replies to the recruiting lead

The owner, on 15 Oct 2026, after 34 pre-screened applicants were moved to Screening (which queues
their portal invitation): nothing may reach real candidates yet. Every email to them goes to the
agency's own inbox, `solutionsfleury+<first name>@gmail.com`, so the whole flow can be tested on
real records; their real addresses stay on file, and switching one setting later sends to the real
people. Candidate emails come from the recruiting lead, Paco Avila (`pa@rsinc.com`).

At that moment no email had gone out: the 34 invitations were in the queue, and delivery
(`install/deliver-emails.php`) had never been run on the server.

## What changes

- **Test mode** (on from the first upgrade with P5-M47; an administrator turns it off on Email
  delivery): every email to a candidate or a worker, which is anybody who is not office staff, is
  delivered to the test inbox instead. The tag is the first name in plain letters, with the last
  name's initial when two candidates share it (`andrewg`, `andrews`). The subject starts with
  `[TEST for <full name>]`. The body is exactly what the candidate would get, links included, so
  the tester can activate the portal and go through it as the candidate.
- **Office staff** (administrators, recruiters, logistics, payroll, supervisors, clients) always
  get their own emails.
- **Replies**: candidate emails carry `Reply-To: pa@rsinc.com` (changeable); a campaign keeps the
  recruiter who sent it. The technical sender stays the server's `email_from`, because the server
  may send for its own domain and not for rsinc.com. Sending *as* pa@rsinc.com needs rsinc.com to
  authorise the server (SPF/DKIM) first, otherwise the mail lands in spam.
- **Every path is covered**: portal invitations (`install/deliver-emails.php`), account emails
  (`install/deliver-account-mail.php`), campaigns and notices (`cm_mail`, also used by
  `deliver-mail.php` and `deliver-campaigns.php`).
- **Email delivery** (administrators): the mode, the test inbox, the reply address, and, for each
  queued invitation, where it will really be delivered.

## Changes

- `app/mail-routing.php` (new).
- `app/campaigns.php` (`cm_mail`), `install/deliver-emails.php`, `install/deliver-account-mail.php`.
- `app/pages/email-delivery.php`, `app/views/email-delivery.php`.
- `install/upgrade.php`: the three settings, set once (never turned back on once turned off);
  `install/rollback/p5-m47.sql` (read it: rolling back sends to real addresses again).
- `tests/campaigns_db.php`, `tests/travel_details_db.php`: these check delivery to the real
  recipient, so their fixture turns test mode off.
- `app/lang/messages.tsv`: 16 FR/ES strings.

## Tests

- `tests/mail_routing_db.php` (9): routing for a candidate, two Andrews, staff untouched, Reply-To
  (and a campaign's own kept), the captured message (address, marked subject, untouched body,
  Reply-To), test mode off, rollback, upgrade switching it on, an upgrade never switching it back on.
- `tests/mail_routing_http.py` (7): the page shows the mode and where queued invitations go; a
  test inbox without `{first}` and a bad reply address are refused; off and on again; only an
  administrator opens it.

Measured on the harness after the walkthrough chain and P5-M42 to M46: 9 + 7 PASS, no server
error. Mutation: test mode ignored (caught). Full suite: 3686 PASS, 0 FAIL (3670 before, plus the 16
new checks).

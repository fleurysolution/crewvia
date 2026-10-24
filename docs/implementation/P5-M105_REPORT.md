# P5-M105 — The recruiter completes a requisition

## Why

M103 sends a requisition to recruiting and tells each recruiter "complete it and publish it". But a requisition could
not be edited after creation: only its hiring managers and checklist could be changed. Walking through Jerry's loop
for the demonstration video found it. The recruiter had no screen to add the detail candidates read.

## What

- **Edit, on each requisition's row** (Requisitions). It opens a dialog above the page with the requisition's form:
  role, discipline, how many needed, start, shift, years of experience, degree, description and must-haves.
- **When the recruiter arrives from the email** (a requested requisition), the dialog opens by itself.
- **Saved, the row and the careers page show the new detail.** The change is recorded in the activity log ("edited
  requisition").
- **The agreed rates are not on this form.** They stay on the line of the scope of work and in the administrator's
  override, as when the requisition was raised.
- **How many needed stays capped** by what the agreement's line still allows.
- **An edit without a role or description is refused**, and nothing changes.
- Small fix: a row whose emails have not left yet reads "emails to N recruiters waiting to send" (it used to read
  "emailed to 0 of N").

The dialog is a native `<dialog>` above the page, so it is never clipped by the table, which scrolls sideways. A first
version as a pop-up panel was clipped, and closed when the page scrolled. It was replaced after being checked on screen.

## P5-M105b — Rehearsal: a run-through on the live workspace that reaches no real person

"Send to recruiting" emails every active recruiter and administrator, so filming the loop on the live workspace would
have emailed RSS's real staff, Jerry included. Test mode diverted only candidates' emails.

- **Rehearsal** (Email delivery, only with test mode on): every email, to candidates **and** office staff, goes to
  the test inbox, marked "[TEST for <name>]". The relay, the From and the headers are the real ones; only the
  recipient is rewritten.
- **An address that already is the test inbox** (solutionsfleury+anything@gmail.com) is delivered as it is.
- **Optionally, the in-app notices too.** A database trigger (`notifications_rehearsal`) marks every notice for
  someone whose address is not the test inbox as **held**, whichever part of Crewvia writes it. A held notice is
  never shown, counted or pushed to a phone; every reader of notices skips `held = 1`.
- **No task for a real person.** During a rehearsal, the "to onboard" task created by an approval goes to nobody
  real: it stays unassigned unless the project's HR is a test account.
- **What it kept from real people.** Email delivery lists, for the rehearsal going on or the last one, the notices
  held back and the emails sent to the test inbox instead of their recipient. So "did anything real get caught in
  that window" has an answer.
- **It cannot be forgotten.** A banner on every page for administrators says the rehearsal is on, and it turns red
  after 24 hours: "the office receives none of its emails".
- **System health** (and the hourly health check) warns while a rehearsal is on, and fails after 24 hours. It also
  checks the mail queue: an email waiting 15 minutes warns, and one waiting an hour fails ("is install/deliver-mail.php
  on cron?").
- **Migration:** `notifications.held` (default 0) and the trigger. Without the TRIGGER privilege the upgrade warns,
  and notices are not held.
- **Off by default.** Switching off test mode switches the rehearsal off.

## Tests

`tests/requisition_loop_http.py` gains:

- the Edit dialog opens by itself for the recruiter;
- the recruiter completes description, shift, how many, experience, must-haves and start;
- an empty edit is refused;
- the completed description shows on the careers page once published.

Checked by hand on a local test copy: the dialog opens centred, and saving updates the row.

## Deploy

```bash
sudo -u veloraweb bash -c 'cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h' && sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php /home/veloraweb/public_html/crewvia/install/upgrade.php
```

Migration: `notifications.held` and its trigger (M105b).

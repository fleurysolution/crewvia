# P5-M103 — Jerry's loop: a requisition sent to recruiting, emailed, published by a recruiter

## Why

Jerry (RSS) wants to see his own loop work, step by step:

1. he creates a requisition;
2. it goes to the recruiter, with proof that it left and arrived;
3. the recruiter takes it in hand and creates the job offer;
4. the offer is published (Indeed);
5. every application lands with the recruiter;
6. the recruiter sends a selection to Jerry;
7. Jerry validates, and the blocking steps follow.

Before this milestone:

- a requisition was public as soon as it was created, so a test could not be created without publishing a false job
  offer;
- no email went to anyone, so step 2 had nothing to show;
- the recruiter had no gesture that showed the request was taken in hand.

## What

- **Send to recruiting.**
  - The requisition form has two buttons. "Send to recruiting" creates the requisition as **requested**:
    - it is not on the careers page, in the website feed, the Indeed feed, the sitemap or campaigns;
    - its link takes no application (404, "not available").
  - Every recruiter (and administrator, except the sender) gets the notice and an **email** linking to the
    requisition.
  - "Create and publish" publishes at once, as before.
- **Publish.**
  - On a requested requisition's row, the recruiter's button. It is the take-in-hand gesture: the requisition becomes
    public for 30 days.
  - Whoever requested it is emailed: "<recruiter> published your requisition <title>".
  - The email's link opens the requisition's project, with the row highlighted.
- **The trail on the row.** For example: "Requested by Jerry, 21 Oct 2026 10:40 · emailed to 3 recruiters, sent 10:41
  · published by Erica, 21 Oct 2026 11:05". The amber "Requested · not published" status shows until a recruiter
  publishes it.
- **Review emails.**
  - Sending an applicant for review emails the manager.
  - The manager's decision emails the recruiter.
- **Sent emails** (Email delivery, administrator). The office emails, with what each was about, its recipient, its
  status (sent, waiting, failed), when it was queued and when it was sent.
- **Send now, and a stopped queue made visible** (P5-M103b). Email delivery says how many emails are waiting and
  has a "Send now" button that sends them at once, without waiting for the 5-minute delivery job. An email waiting
  15 minutes or more turns the line red: "the delivery job may be stopped". A stopped job no longer looks like an
  empty queue, and the demonstration does not wait 5 minutes.
- **Migration.** The `vacancies` table gets `posting_status` (VARCHAR, default `published`, so existing requisitions
  stay public), `requested_by`, `requested_at`, `published_by` and `published_at`. Rollback:
  `install/rollback/p5-m103.sql`.

## Indeed: what exists and what does not

There is **no "publish to Indeed" button**. Direct posting needs Indeed's ATS partner approval: that decision is
Indeed's, takes weeks and is not guaranteed.

What exists today:

- **Posting by hand.** The recruiter posts the job on Indeed with the requisition's Crewvia link as the application
  address. The test above proves that an applicant through that link lands on that requisition. Whether RSS's Indeed
  account allows an external application link must be checked on their account.
- **Importing applicants.** Indeed's applicant export is brought in through Imports and attached to the requisition.
  About 1,600 applicants were imported this way in production.
- **`/job-feed`.** An Indeed XML feed exists but stays empty until `hiring_organization_name` is configured, and Indeed
  must accept the feed.

## Tests

`tests/requisition_loop_http.py`, 18 checks:

- requested, then nowhere public;
- the recruiters are emailed, the emails leave (mail capture), and the row shows "sent";
- the recruiter's link opens the right project and the right row;
- Publish makes it public and emails the owner, and the row shows the whole trail;
- Create and publish still works;
- an applicant through the public link lands on that requisition, on Candidates;
- the review email to the manager and the decision email to the recruiter;
- the Sent emails list.

`client_contacts_http.py`: the requisition row has an id now (its regex was adjusted).

## Deploy

```bash
sudo -u veloraweb bash -c 'cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h' && sudo -u veloraweb /opt/cpanel/ea-php82/root/usr/bin/php /home/veloraweb/public_html/crewvia/install/upgrade.php
```

The emails leave through `install/deliver-mail.php`, run by cron every 5 minutes (docs/OPERATIONS.md).

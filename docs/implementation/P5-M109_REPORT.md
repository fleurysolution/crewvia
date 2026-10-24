# P5-M109 — What walking the recruiting loop on production found

On 24 Oct 2026, the recruiting loop was walked on production, on the demonstration workspace (project Plant 3), with
the test accounts Jerry (demo) and Recruiter (demo) and Rehearsal on. The steps: Jerry raises a requisition and sends
it to recruiting; the recruiter completes it, publishes it and takes its link; a test applicant applies through the
link; the recruiter sends them for review; Jerry approves.

Every step worked. Rehearsal held back 5 notices and diverted 5 emails meant for real people. The walk found what the
automated checks do not see:

| # | Seen | Now |
|---|---|---|
| 6, 14 | An email "queued 10:57, sent 11:57"; an applicant "applied 1 hour ago" minutes after applying. MySQL's clock ran an hour apart from the application's (Eastern). | Every connection sets the database's time zone to the application's current offset, so `NOW()` and PHP's `date()` agree, daylight saving included. |
| 7 | "$2,100,345" broke across two lines on the Executive overview. | A sum never breaks; it shrinks to fit its column. |
| 10 | The recruiter saw what the client is billed ("billed $53.20"), that is the agency's margin. | Shown to the owner (administrator) only. Firmin's decision. |
| 4, 9 | The edit dialog opened for Jerry right after he sent the requisition, and again after the recruiter saved. | It opens by itself only from the recruiter's link (`open=edit`, in the email and the notice). |
| 8 | Opening a notification did not mark it read; the bell kept its count. | A notice opened from Notifications is marked read, then opens its page. The page's subtitle no longer talks about "background mobile push". |
| 12 | The applicant's page showed two next steps ("Next: Contacted", and the "Send for review" card). | One: while "Send for review" is there, it is the next step. |
| 13 | A recommendation typed before the checklist was answered was lost on the refusal. | "Answer the checklist first: N questions left", with a link, and Send for review disabled until it is answered. |
| 11 | An application through the requisition's link showed source "RSS". | Source "Apply link". |
| 5 | "Sent emails" listed jp@rssinc.com as "Sent" during a rehearsal, without saying it went to the test inbox. | The row says "to the test inbox (rehearsal)". |
| 3 | Marking a hiring manager returned to the whole team list, the search lost. | The search is kept. |

Also added (asked through the hub): a QR code of the careers page's own address at the bottom of `/careers`, so a
worker can show their phone to a colleague.

Not changed:

- **#1, the temporary password.** It is shown once, at the top of the page, on the person's own row after "Add".
  That is by design, and "Reset password" shows a new one.
- **#2, a first-sign-in link by email for new staff.** A small feature, to decide; not done here.

## Real requisitions on a demonstration project

Production's demonstration project "Plant 1" (#6) also carries RSS's live requisitions 23–26, with 431 real
applications (182, 85, 162, 2). Two real people are placed on demonstration projects. `install/demo-flush.php` would
have removed all of it with the demonstration.

- **The flush refuses** when its plan contains records of people who are not in the demonstration: an application,
  placement or other row of a real candidate. It lists the counts and removes nothing. The guard stays.
- **`install/demo-separate.php`** moves real requisitions from a demonstration project to a real one:
  - their applications;
  - their scope lines (copied when a demo requisition shares the line);
  - the real people placed on that project;
  - every project-level row tied to them.
  - It refuses a source that is not a demonstration project, a target that is one, a requisition that is not on the
    source, and a wrong `--expect-applications`.
  - Without `--execute` it does the whole move and every check below, then rolls it back: it changes nothing.
  - A moved row that names a scope line the demonstration keeps (a requisition, a placement, a row of an
    application) is pointed at the copy. Production's first `--execute` rolled itself back on exactly that: two real
    placements still named a shared line, so a flush would still have taken them.
  - With `--execute` it runs in one transaction. Each count is checked against what was announced. Afterwards it reads
    the database again and checks that a flush would take none of the moved records. If any check fails, it rolls back.

The order on production, each step after Firmin's word:

1. backup;
2. restore drill;
3. separate, dry run;
4. separate, `--execute`;
5. flush, dry run.

`tests/demo_separate_db.php` (9 checks) replays this on the test database.

## Tests

`tests/requisition_loop_http.py` gains:

- the requester's page does not open the dialog;
- the recruiter does not see the bill rate, and the owner does;
- a notice followed is marked read;
- the application source is "Apply link".

`tests/careers_feed_http.py` gains the careers QR.

## Deploy

```bash
sudo -u veloraweb bash -c 'cd /home/veloraweb/public_html/crewvia && git pull --ff-only origin main && git log -1 --format=%h'
```

No migration.

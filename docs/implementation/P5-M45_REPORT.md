# P5-M45 — A portal from the moment somebody applies; the candidate says when they can work

The owner's flow: "a candidate can apply by scanning the QR code of the job poster, on the website
or posted elsewhere, it appears where it must, and a candidate portal is created for that person
that lets them declare themselves active or not." Measured before this module:

- the public application (`/apply`, what the requisition's QR code opens) created the candidate and
  the application, but no portal: a portal came only when a recruiter moved the application to
  Screening or Offered (an invitation email, P4), or by hand;
- nowhere could a candidate say whether they were available (`employee_profiles.available_from`
  existed, unused);
- the application page showed the title and the description, nothing a person decides on.

## What changes

1. **The application page** (read on a phone, mostly): the facts first (pay an hour, per diem, when
   it starts, openings; the pay of the scope line when the requisition has none of its own), then
   the form in plain parts (about you, your CV, the questions, when you can start, consent).
2. **When can you start?** Available now / available from a date / not available for now, on the
   application. Kept on the candidate's record with the day it was said; a date that has come
   reads "available now"; every change is in the record's history.
3. **A portal on the spot.** Right after applying, the page offers "Create my portal": the same
   activation as an invitation (they choose their password), valid 7 days, without an email since
   they are on the page. Somebody whose email already has an account is asked to sign in instead;
   nothing is merged; no second portal.
4. **In the portal** (Jobs for you): "My availability", changed whenever it changes, and "Your
   interviews" (date, time, length, how, where; P5-M44), so the date reaches them without a call.
5. **For the recruiter**: Hiring shows the availability in the applicant list and on the
   application ("Available from 3 Nov 2026 · said on 14 Oct 2026", or "not said yet").

The questions a requisition received from a job board (P5-M43) are now asked on the public page
too, so somebody applying directly is scored on the same terms as somebody from Indeed.

Caught by the full suite on the way: the link offered on the page is an invitation, and an
unused invitation used to stop the activation email sent when the recruiter moves the application
to Screening (`workforce_invite_candidate`). Only an invitation that was emailed counts now, so
somebody who leaves the portal for later still gets the email (the link on the page keeps working).

## Changes

- `app/availability.php` (new): choices, record, words and tone, the portal offer.
- `app/intake.php`: availability on application; the portal offer.
- `app/invitations.php`: only an emailed invitation stops a new activation email.
- `app/pages/apply.php`, `app/views/apply.php`: the facts, the form in parts, availability.
- `app/views/application_received.php`: the portal offer.
- `app/pages/my-jobs.php`, `app/views/my-jobs.php`: availability, interviews.
- `app/hiring.php`, `app/pages/hiring.php`, `app/views/hiring.php`: availability shown.
- `install/upgrade.php`: `candidates.availability`, `available_from`, `availability_at`;
  `install/rollback/p5-m45.sql`.
- `app/lang/messages.tsv`: 24 FR/ES strings.

## Tests

- `tests/availability_http.py` (14): the page's facts and question; applying with a start date;
  the portal offered, activated and linked to the record; the portal shows and changes the
  availability; Hiring shows it in the list and on the application; a bad date refused; an
  interview appears in the portal; applying again with the same email asks to sign in; somebody
  who leaves the portal for later still gets the activation email at Screening.
- `tests/availability_db.php` (5): the words around a date that has come, a passed date stored as
  available, rollback (history kept), upgrade, silent repeat.

Measured: chain + Indeed import + add applicant 95, then board scores 8, own scoring 7 + 8,
reviews 25 + 4, availability 14 + 5. Mutations: no portal offered (caught); the old invitation rule (caught:
no activation email at Screening). Seen on a 375 px
phone viewport: the page, no horizontal scroll.

Full suite on P5-M44, M45 and M46 together: 3670 PASS, 0 FAIL (3613 before, plus the 57 new checks).

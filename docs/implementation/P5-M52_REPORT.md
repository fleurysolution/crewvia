# P5-M52 — Candidates are told of their interview and their offer

Measured on production, 15 Oct 2026: an applicant was moved to Offered and heard nothing. The
offer was in their portal, but nothing told them to look; an interview scheduled in Hiring rang
only the interviewer's bell.

## What changes

- `candidate_tell()` (`app/notices.php`): for a candidate who has a portal, rings their bell in the
  portal and queues an email (encrypted `mail_outbox`, purpose "candidate notice", delivered by
  `install/deliver-mail.php`): "Hello <first name>", the message, the link to their portal, signed with
  the agency's name. Candidate emails follow the test-mode routing of P5-M47 (test inbox, Reply-To).
  Somebody without a portal is not sent this notice; their portal invitation reaches them instead.
- **Offer** (`hw_move`, `app/hiring.php`): "You have been selected for <job>. Your offer is in your
  portal: read it and accept it there." Sent when the offer reaches the candidate, so an offer
  that waits for validation is announced once validated. The separate bell in
  `hiring_offer_settled` is gone (it would have rung twice).
- **Interview** (`interview_schedule`, `app/reviews.php`): "Your interview for <job>: <date>,
  <minutes> minutes, <how> (<where>)", with the link to "My jobs".

No table changes, so no migration and no rollback.

## Tests

`tests/candidate_tell_http.py` (4): the candidate has a portal; an interview rings their bell and
queues the email with the date, length, how, where and the portal link; an offer does the same
(selected, the offer in the portal, the link, signed by the agency); somebody without a portal is
not sent this notice.

Mutation: `candidate_tell()` made to do nothing (caught: "An interview rings their bell…").
Full suite: 3719 PASS, 0 FAIL (3715 before, plus the 4 new checks).

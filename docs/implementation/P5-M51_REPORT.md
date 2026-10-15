# P5-M51 — The do-not-rehire register holds the person wherever they turn up

The owner, 15 Oct 2026: "can this system record a candidate who was hired, worked, maybe made a
mistake, and this candidate is flagged do not use or do not hire … is there a way to control that a
candidate has been blacklisted so as not to be used?"

## What existed

The register was already there (P2/P4): on a candidate's record ("Rehire" section), four standings
(Fine to call, Check before calling, No rehire, DO NOT USE), a reason required for a block, who and
when; separation in HR records can set it too. Campaigns, the worker's "Jobs for you" and the
candidate list honoured it.

Measured gaps:
1. It held only the record it was set on. The same person applying again (a job poster's QR code,
   a job board import, an email added by hand) came in as a new record, unflagged.
2. In Hiring, "add from the talent pool" and the pool's matches checked only "No rehire", not
   "DO NOT USE".
3. Nothing stopped moving a blocked applicant forward: Screening, the manager's review, an
   interview, a portal invitation (one or in bulk).
4. Hiring did not show it at all.

## What changes

- **Recognised by email or phone** (`app/rehire.php`): an application is held when its record, or
  any other record with the same email, or the same phone (compared on its last ten digits, so
  "+1 555 010 0002" and "(555) 010-0002" match), is on the register. Identity is still never merged.
- **Shown**: a red "DO NOT USE" / "No rehire" tag on the applicant in Hiring's list; on the
  application a red banner with the reason, who decided and when, how the person was recognised
  (this record, same email, same phone) and a link to the record that carries it. The forms that
  move the person forward are not offered.
- **Refused**: moving to Screening, Interview or Offered; passing to a manager; scheduling an
  interview; a portal invitation, alone or in bulk (central guard in the invitation itself); adding
  from the talent pool; both "No rehire" and "DO NOT USE". Rejecting stays possible.
- **Lifted** on the record that carries it (Fine to call), which frees every application it held.
- Every application in Hiring links to its record's register ("Do-not-rehire register").

No table changes, so no migration and no rollback.

## Tests

`tests/rehire_http.py` (11): DO NOT USE set with a reason; the red tag and the banner (and no
forward forms); Screening, the manager, a bulk invitation and the talent pool refused; the same
person applying again on the public page (same email) held and said so, and not movable; added by
hand with the same phone written differently, held; rejecting allowed; lifted, freed.

Mutation: the refusal disabled (caught: "Moving them forward is refused"). Seen in the browser on
the test server: the banner. Full suite: 3715 PASS, 0 FAIL (3704 before, plus the 11 new checks).

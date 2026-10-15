# P5-M53 — The offer letter, the candidate's answer, the office's countersignature

The owner, 15 Oct 2026, after testing an offer in production: the offer was there, but not what
comes with an offer (the job, its description, the conditions); the candidate could not decline it;
and signing it made them "Hired" at once. It must go back to the office, which reviews it and
countersigns it (one signature), and only then is the person hired; they are then told, and their
candidate portal becomes their employee portal.

## What changes

- **The offer letter** (portal, `app/offers.php` `offer_letter()`): the agency, "Offer of
  employment", the position and site, the terms (pay, per diem, guaranteed hours, start, place,
  lodging and travel), the recruiter's note, the job description of the requisition, and the
  agency's **general conditions**, written once in Settings ("General conditions of every offer").
  "Print or save as PDF" prints the letter alone. A start date already passed reads "Starts:
  immediately".
- **Signing** (`offer_sign()`): what is signed is the whole letter, conditions included (hash kept).
  The application stays at Offered; the office is told (":name signed the offer for :job: review it
  and countersign it"); the candidate reads that the office is reviewing and countersigning it.
- **Declining** (`offer_decline()`): a box to confirm, an optional reason; the application becomes
  Withdrawn with "Offer declined by the candidate. <reason>", and the office is told.
- **Countersigning** (Hiring, `offer_countersign()`): the list tags the applicant "Signed: to
  countersign"; the application shows what was signed and one form (a box "I have reviewed the
  signed offer and countersign it for <agency>", the name). Countersigned: Hired, the assignment and
  its onboarding start (as the candidate's signature used to do), the candidate is told in their
  portal and by email (P5-M52), and the header reads "Employee portal" instead of "Candidate portal".
  To refuse, the office rejects the application with the reason.
- Offers issued through the contract workflow (Contracts & signatures) keep their own steps; their
  acceptance is recorded as countersigned by that workflow.

## Data

`offer_acceptances` gains `countersigned_by`, `countersigned_at`, `countersigner_name`
(`install/upgrade.php`, reversed by `install/rollback/p5-m53.sql`). Offers accepted before this step
are marked countersigned when they were signed ("Hired on signature (before P5-M53)"): those people
were hired then and stay hired. The general conditions are the `offer_conditions` setting.

## Tests

`tests/offer_flow_http.py` (17): the conditions saved in Settings; the whole letter in the portal
(agency, position, terms, the job, the conditions, print); accept and decline offered; declining
needs the box, then Withdrawn with the reason and the office told; signing does not hire (Offered,
signed, no countersignature, no assignment); what is signed includes the conditions; the candidate
reads that the office countersigns, nothing left to sign; the office told; Hiring's tag and the
signed text with the form; countersigning needs the box; one countersignature hires (Hired, by name,
the assignment); the candidate told by email; the employee portal.

Updated: `tests/http_tests.py` and `tests/walkthrough_http.py`, where the candidate's signature used
to hire; the office now countersigns there before the checks that follow.

Mutation: the candidate's signature made to hire at once (caught: "Signing does not hire").

Full suite: 3737 PASS, 0 FAIL (3719 before, plus the 17 new checks and one in http_tests.py).

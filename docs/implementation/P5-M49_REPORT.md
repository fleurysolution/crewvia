# P5-M49 — The candidate's portal: choosing is saving, "Candidate portal", the agency's name

Seen on 15 Oct 2026, when the owner activated Tyler Staab's test portal (P5-M47 test mode) and
went through it as the candidate:

- In "My availability" "Available now" was chosen, then "I'm interested" pressed on another job:
  that button belongs to another form, so the availability was never saved. Hiring said
  "Availability not said yet". Choosing an answer is what a person expects to be enough.
- The account menu said "Worker portal" for somebody who is still a candidate.
- The header said "Crewvia" (the platform) to a candidate who applied to RSS Inc.

## What changes

- **My availability saves as soon as it is chosen**: "Available now" or "Not available for now"
  on the click, "Available from a date" as soon as the date is entered (the date alone picks that
  answer). The Save button stays for a browser without JavaScript. The page then says what
  recruiting sees and since when.
- **"Candidate portal"** in the account menu for a portal account never placed; "Worker portal"
  once there is a placement (`account_role_label()`).
- **Candidates, workers and clients see the agency's name** (Settings, "Agency name", P5-M48) in
  the header and the browser tab; office staff keep the platform's name.

## Also noted from the same test

Pressing "I'm interested" on Industrial Maintenance Tech in Tyler's test portal created a real
application for him on that job ("Interested, from the worker portal"). The real Tyler never asked
for it: to be withdrawn ("No longer interested" in his portal, or by the office in Hiring).

## Tests

- `tests/availability_http.py`: a candidate not placed sees "Candidate portal", not "Worker
  portal", and the availability form saves on choice (`data-autosave`).
- In the browser on the test server, as a test candidate: one click on "Not available for now"
  saved it ("Recruiting sees: Not available for now (said on 15 Oct 2026)"); entering 2 Nov 2026
  in the date saved "Available from 2 Nov 2026", the date answer chosen by itself.

Full suite: 3694 PASS, 0 FAIL (3693 before, plus the new check).

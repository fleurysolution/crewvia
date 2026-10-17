# P5-M83 — the recruiter's flow: call, send for review, the owner decides

RSS, 17 Oct 2026, walking a candidate through Crewvia:
- "We need a flow to push it to Jerry, or the supervisor, for review and
  approval."
- "If you have been contacted, you go to a different list."
- "The contact thing is just huge… collapse those, they're optional."

## Send for review

The person's page has **Next step: Send for review**:
- **For**: one of their applications, or another open requisition. An
  application is made for it.
- **Who decides**: the owner by default, or a supervisor or another
  recruiter.
- **Fit**: 1 to 5.
- **Your recommendation**.

It becomes a manager review (P5-M44):
- it is in the decider's "Waiting on your decision" (/reviews), with a
  notification;
- the application moves to Screening, and the person to In review.

While it is pending, the card says who has it, since when, and the fit.

**A recruiter can no longer move someone past Contacted without a review:**
- Candidates' quick move to Screening, Offered, Accepted or Placed sends them
  to the person's page instead;
- an application moved to Screening, Interview or Offered needs a validated
  review.

The owner moves anyone directly, as before.

## Candidates in steps

Candidates has tabs, each with its count:
- **To contact**;
- **Contacted**;
- **In review**;
- **All**.

Recording a contact moves a person from To contact to Contacted, so they leave
the list to call. A recruiter opens Candidates on To contact.

## A lighter contact card

The person's contact card puts first:
- **Call** and **Email**;
- recording the contact (how it went, what was said).

**Send a text message** and **Contact preferences** fold under a line each.
The preferences line shows "restricted" when the person asked not to be
called or texted. The driving records link stays, small.

## Tests

`tests/client_contacts_http.py` covers:
- a recruiter's move to Screening sent to the person's page;
- the person's page with Send for review and the folded text and
  preferences;
- the review sent to the owner, the person In review, and the review on the
  owner's Waiting on your decision;
- a recruiter's Candidates opening on To contact.

Checked in the local preview:
- the contact card's order;
- "Choose the requisition" when the only application was rejected;
- "Sent for review to Owner", then "In review, with Owner since 17 Oct 2026,
  fit 4/5".

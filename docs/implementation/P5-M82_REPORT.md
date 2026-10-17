# P5-M82 — Candidates by requisition, and a message to the people ticked

RSS, 17 Oct 2026:
- "I'm the recruiter for Main Street: I need to find all the people that
  applied for it… first come, first served."
- "…and also the candidates in the database that match that requisition."
- "Select them in bulk and send them a message."

## Candidates → Applied to

- **Applied to (a requisition)** lists only the people who applied to it:
  through its link or QR code, a job board, or a referral.
  - First applied first. "Newest first" turns the order around.
  - Each line says when the person applied, and where from.
- **Matching in the database**, on the same requisition, lists the people
  already in the database who could fill it:
  - same trade, and in the site's state when the site has one;
  - not already applied;
  - not on the do-not-rehire register.

## A message to the people ticked

- Each line has a box, and the header has "select all".
- Ticking people shows **Email them** or **Text them**. Either opens the
  campaign (P5-M29) for exactly those people, applicants included, previewed
  before anything is sent.
- The campaign's rules stay:
  - unsubscribed people, STOP replies, the register and switched-off accounts
    are never written to;
  - an administrator approves above 50 people;
  - delivery is paced.

## Tests

`tests/client_contacts_http.py` covers:
- applicants in the order they applied, with their source;
- newest first;
- the database matching the requisition without its applicants;
- a campaign for exactly the two people ticked.

Checked in the local preview:
- the applicants of a requisition: 9, 10 and 11 Oct, from Indeed and a
  referral;
- the matching tab;
- the selection bar;
- the campaign for the person chosen.

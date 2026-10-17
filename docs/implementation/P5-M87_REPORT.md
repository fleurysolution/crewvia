# P5-M87 — the hiring manager assigns to the job at approval; the owner's own sends stay in the flow; the person's page trimmed

RSS, 17 Oct 2026:
- "The recruiter should not be able to add to the strike coverage."
- "The hiring manager, after his review, should be able to click assign to
  this job, at the same time he sends to HR… but not if the requisition is
  already full."
- "Even if the owner is the one pushing it, the flow still has to be
  consistent."
- "What they applied for, just a little box… I don't want any of this."

## Assign to this job, at approval

The decision on Waiting on your decision has **Assign to this job**, ticked
by default, with how full the requisition is ("2 of 10 placed so far").
- Approved and assigned: the person gets an offered placement on the
  requisition's job, at its rates, from its start date. HR has them.
- **A full requisition** (its placements reach the number asked) offers no
  assigning. The box is disabled with "the requisition is full", and the
  server refuses it too.
- **A recruiter can no longer place anyone** from the person's page: the
  button is only for the owner, and the server refuses others.

## The owner's own sends

The owner, or a hiring manager, who sends an applicant for review stays in
the review pool:
- on a requisition they manage, the review comes to them, on their Waiting
  on your decision;
- without hiring managers, it goes to the sending owner first.

The same review, decision, HR and travel steps follow as for anybody else.

## The person's page

The big "What they applied for" and "On the job" blocks are gone from the
middle of the page. They are at the foot, in one closed section,
**Applications and assignment: details**, which holds the moves, the offer
and the placement. The top keeps the two small boxes. A link to the
assignment opens that section.

## Reopening a closed requisition

This stays as it was: only the owner can reopen one, and it is logged.

## Tests

`tests/client_contacts_http.py` covers:
- a recruiter refused when placing someone;
- the owner's send going to the owner's review;
- the decision offering the assigning, with the fill;
- approval with assigning giving an offered placement;
- a full requisition disabling the assigning.

## P5-M88 — hiring managers marked on Team

"Add a button on the team that gives them hiring manager… so that when you
are creating a requisition, only the ones that have it appear."

- **Team** has a **Hiring manager** button on each staff member, whatever
  their role: full access, recruiting, HR, project coordinator, or a
  supervisor acting as HR on site.
- **A requisition offers only them** as hiring managers, side by side rather
  than one long column. With nobody marked yet, it offers the people with
  full access.
- The upgrade marks those already named on a requisition.

Data: `users.hiring_manager`. Reversed by `install/rollback/p5-m88.sql`.

Also fixed: the checklist message used "question(s)". It now says "1
question" or "2 questions", as the app's rule asks.

Tests: Team marking a hiring manager; a requisition offering only the
hiring managers, side by side.

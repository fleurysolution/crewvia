# P5-M85 — the managers decide; HR first, then the project coordinator; Flight arrivals

RSS, 17 Oct 2026, with the owner on the phone:
- "Send for review only to the upper managers… anybody on that level can
  review and approve."
- "It goes to HR, they do the background check and drug screen, then
  flights and hotels… but sometimes both at the same time: an expedited
  checkbox."
- "Just a page that says flight arrivals… they'll send a person to pick them
  up."

## The hiring managers of each requisition decide

"With 600 people, every manager would get 600 notifications… whenever a
requisition is created, indicate who is the hiring manager. There could be
multiple."

- **The hiring managers are named on the requisition.**
  - At creation: Hiring managers, ticked.
  - Afterwards: from the requisition's line, under "QR code & more".
- **A review goes only to them, and only they are told.** It is attached to
  the one with the fewest reviews waiting, and any of them decides.
- **A requisition without hiring managers** sends the review to one
  manager: the least busy with Full access. Not to everybody.
- **Waiting on your decision** and the Activity count show what is yours:
  passed to you, or on a requisition you manage. An administrator can still
  see everything ("all").

## HR first, then the project coordinator

1. The manager approves, with or without **Expedited** ("book the flight
   and hotel now, while HR does the background check and drug test").
2. HR gets its onboarding task (P5-M84).
3. **Not expedited**: when HR marks its task **done** (background check and
   drug test clear), the project coordinator gets "Book the flight and hotel
   for :name", due the next day, with a notification.
4. **Expedited**: both tasks start together.

The coordinator is the Project coordinator desk member with the fewest open
tasks.

## Flight arrivals

**Flight arrivals** (`/arrivals`) is the project coordinator's first screen.
It shows the inbound flights of the next 7, 14 or 30 days, for this project
or all, by day. For each one:
- the time;
- the person, with their phone and project;
- the flight and where it comes from;
- the airport of arrival;
- the pickup ("to arrange" when nobody is set);
- the hotel.

**Status:**
- **Expected**;
- **Landed, not signed in yet**;
- **Arrived**, once the person has signed their W-4 or I-9. Signing is done
  in person, in front of HR or the supervisor, so a signature is the proof
  of arrival.

## Data

`hiring_handoffs` links each approval to its HR and travel tasks; `vacancy_managers` holds each requisition's hiring managers. Reversed
by `install/rollback/p5-m85.sql`.

## Tests

`tests/client_contacts_http.py` covers:
- a requisition naming its hiring manager;
- a review going to that manager only, and only them told;
- the review on their Waiting on your decision;
- no travel task while HR works;
- HR done giving the project coordinator the flight and hotel;
- an expedited approval starting both;
- Flight arrivals listing a flight with its pickup to arrange.

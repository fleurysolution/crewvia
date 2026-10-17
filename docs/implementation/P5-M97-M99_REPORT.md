# P5-M97 to M99 — Send home; the airport given on applying; a shorter menu

## P5-M97 — Send home

"A send home button, for termination, that automatically cancels the
applicant or the employee, ADP and all other related items, including the
booking of their return flight by the project coordinator."

**Send home** is on the person's page in Hiring (once approved) and on their
assignment page (`/placements/ID`), while the assignment runs. The form asks:

- **Why** — the separation reasons: assignment completed, resigned,
  terminated, no show, laid off, other. **Terminated** is offered to the
  owner, managers and HR only.
- **Last day** — today, earlier, or within 30 days.
- **Hire again?** — fine to call, check before calling, or no rehire.
- **What happened** — required unless the assignment simply ended, or when
  the answer is no rehire.
- **Book their flight home** — ticked by default when they had travelled or
  were on site.

One click then:

1. **The assignment ends**: completed if they had travelled or were on site,
   cancelled if they never started. It is dated on the last day.
2. **The hotel**: checked out on the last day; later bookings are cancelled.
   They come off the bus runs after that day.
3. **What stays open is cancelled**:
   - an inbound flight not yet flown;
   - open background or drug screenings;
   - the HR onboarding and travel tasks.
4. **The separation is recorded**, with the reason, the rehire decision and
   what happened. "No rehire" puts them on the register; "Check before
   calling" marks them for review. A termination locks their account.
5. **The people who finish it get a task each**:
   - **the project coordinator**: "Book the return flight for <name>", with a
     leg home already started from the site to the airport they gave;
     **Travel** now has a **Needs a flight home** list with its booking form
     (direction home);
   - **payroll**: "End <name>'s employment in ADP", with the last day and the
     reason;
   - **the supervisor** (or the project manager): "Get back the equipment and
     vehicle of <name>", when anything is still out.
6. **Their supervisor is told.**

**ADP.** ADP has a termination event in its API, as it has one for hiring.
Until RSS's ADP credentials are on the server, ending the employment in ADP
is payroll's task, as the hire file is today.

## P5-M98 — the airport they fly from, from the application

The application form (the requisition's link and QR code) asks **The airport
you fly from**: code, name and city, for example "IAH - Houston Bush
Intercontinental, Houston TX". It is kept on the person's record
(`candidates.home_airport`), and Travel uses it:

- for the flight in, until they fill their travel details in the portal;
- for the flight home that Send home starts.

The upgrade copies the airport of everyone who already filled their travel
details.

## P5-M99 — a shorter menu

"The left menu is too crowded."

- **The owner's and managers' daily screens drop from 12 to 8**: Executive
  overview, Activity, Approvals, Projects, Hiring, Candidate reviews, Team,
  Careers page. The project dashboard, Hours, Billing and Settings move under
  More, or into Bookmarks.
- **The supervisor's drop from 10 to 6**: My team, Activity, Candidate
  reviews, My attendance, Lateness, My tasks.
- **Bookmarks** only appear once there is one. Until then the menu shows just
  "+ Bookmark this screen".
- **Recent** no longer repeats a daily screen or a bookmark, and shows three
  at most.

## Migration

`candidates.home_airport`, filled from the travel details already given.
Rollback: `install/rollback/p5-m97.sql`. Separations, tasks and travel legs
that were created stay.

## Tests

- `client_contacts_http`:
  - Send home is offered on the assignment and in Hiring;
  - a reason without what happened is refused;
  - after sending home: the assignment is completed and dated, a flight home
    to their airport waits to be booked, the coordinator and payroll get
    their tasks, the separation is recorded, screenings are cancelled,
    Travel lists the flight home;
  - sending home twice is refused.
- `availability_http`: the application form asks for the airport, and it is
  kept on the record.
- `menus_http`: the new daily menus.

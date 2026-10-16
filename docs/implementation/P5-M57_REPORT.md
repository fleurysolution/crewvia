# P5-M57 — Team: the staff and the form to add somebody first

The owner, 15 Oct 2026, asked to add three test accounts (supervisor, project manager, recruiter)
and found no form: Team listed all 1,099 accounts - the employees' and clients' portal accounts first,
since they sorted ahead of the staff - and "Add somebody" sat under them, out of reach. Each portal
account carried a desk menu that refuses any change; the add form offered the worker and client desks,
which it then refused; the action buttons stacked; "Switch off" and "never" were not translated. The
form had been pointed to without being checked on the screen.

## What changes

- **The form first**: "Add somebody to the staff" on top, open on the Staff tab, with only the staff
  desks (Full access, Recruiting, Hotels & Travel, Payroll & Billing, Supervisor) and a title
  ("Project manager, Site supervisor"). The header's "Add somebody" opens it from any tab.
- **Three tabs, counted**: Staff (the default), Client portals, Worker portals; a search by name or
  email; 100 to a page, Previous and Next.
- A portal account shows "Worker portal" or "Client portal" in words, not a desk menu that would
  refuse the change.
- The actions sit on one line; the form's fields line up; the words are translated.

No table changes, so no migration and no rollback.

## Tests

`tests/people_http.py` (8): the form on top, open, before any list; only the staff desks; three
counted tabs, the staff first; the staff tab without the portal accounts; search finds a portal
account on its tab; a portal account without a desk menu; adding somebody shows the temporary
password once; shown right away (the page reopens on them), with the staff, marked "temp password".

`tests/review_fixes_http.py`: the worker account switched off is looked for on its tab.

Clicked in the browser on the test server: the header button opens the form and puts the cursor in
Name; typed and added a person (the temporary password shown); the Worker portals tab; the fields
aligned and the buttons on one line (measured).

Mutation: the Staff tab listing every account again (caught: "The staff tab lists the staff, not the
portal accounts").

The first full run failed on "listed with the staff": with more than 100 staff the new person fell on page 2; the page now reopens on them.

Full suite: 3766 PASS, 0 FAIL (3758 before, plus the 8 new checks).

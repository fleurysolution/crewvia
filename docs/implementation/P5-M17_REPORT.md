# P5-M17 — the organization chart no longer grows with people × staff

The owner's request, 13 Oct 2026: fix `/organization`, found while
building P5-M15. The load test ("600 page requests by 40 people at once")
had failed three times in 36 full runs. Each time, the only requests
dropped were `/organization`, which the Windows test server cut off
(ConnectionResetError 10054).

## What was found

- **Every person got a full form.** The chart drew a reassign form for
  every person, each with a `<select>` of every active staff member who
  could carry a crew. The page therefore grew with people × staff.
- **Measured** on a 30-person project:

  | Extra staff who could carry a crew | Page size |
  |---|---|
  | none | 100,379 bytes |
  | +20 | 153,779 bytes |
  | +200 | 637,739 bytes |

  That is about 2.7 KB for every extra staff member, on only 30 people.
  The load test's project has 1,000 people.
- **The queries were not the problem:** a handful per page.
- **Saving a reassignment** (handled by Operations) sent the user to
  Operations instead of back to the chart.

## What was built

- **The chart draws the supervisor list for one person at a time.**
  - Everybody else gets a link: **Assign** (no supervisor yet) or
    **Reassign**.
  - The link opens the chart with that person's form in place
    (`?assign=<placement>`, anchored at the person), with Cancel beside
    Save.
  - It works without JavaScript.
- **Measured on the same 30 people:** 58,407 bytes, unchanged with 20 or
  200 more staff.
- **Saving** still goes through Operations, so the rules stay in one
  place. A save from the chart comes back to the chart at that person; a
  save from Operations still returns to Operations.
- **Two cases checked:** a person who is not on the job opens no form, and
  Logistics and payroll read the chart without the links, as before.
- **Unchanged:** no data, no table, no new strings ("Assign", "Reassign"
  and "Cancel" were already translated).

## Tests

- **`tests/organization_db.php`:** a recruiter, Logistics, two
  supervisors, and 30 people (20 on two crews, 10 with nobody).
- **`tests/organization_http.py`:**
  - 30 links (10 Assign, 20 Reassign) and no supervisor list;
  - 20 more staff add nothing to the page;
  - asked for, exactly one form opens, for that person, listing every
    possible supervisor;
  - saving goes through Operations and comes back;
  - nine left unassigned;
  - a save from Operations returns there;
  - nobody outside the job opens a form;
  - Logistics sees no links.
- **`tests/organization_probe.php`:** reads the assignments and adds staff.
- **Mutation:** with every person's form drawn again (the old behaviour),
  the test failed at "The chart lists all 30 people, each with a link to
  assign or reassign them". Restored afterwards.
- **The load test:** the cause is removed, but its passing on one run does
  not prove the drops are gone. Whether `/organization` drops again will
  show over the next full runs.

# P5-M89 and M90 — congratulations at the end of onboarding; every scope edit kept

## P5-M89 — the congratulations

"When onboarding is done, they receive a congratulations email, and
everything is in their portal."

When the person's W-4 and I-9 are both accepted (the I-9's employer section
included), they receive **"Welcome aboard: your onboarding is complete"**,
by email and in their portal. The message names the project, the start date,
and what the portal shows. It goes once per assignment. The upgrade marks the
people already on site as told.

**ID copies.** The copies photographed on the phone at the signing are
locked once the I-9 is completed: from then on, only an administrator or an
authorized reader opens them. This was already in place. It was checked, and
nothing was changed.

## P5-M90 — every edit of a scope line kept

"You can edit it, but there's a timestamp to know who edited it, what
changed, and why. For accountability."

- **Edit** on a line of the scope of work stays the way to change it, for
  example to raise 10 to 30 when the client asks for more.
- **Why it changes** is required whenever something changes.
- Each change is kept: who, when, every field from old to new value
  ("quantity: 10 -> 30"), and why.
- Under the scope, **N changes to the scope** lists them.

When every trade is already on requisitions, Requisitions says to edit the
line on the scope.

Data: `placements.welcome_sent_at` (`install/rollback/p5-m89.sql`) and
`scope_change_orders` (`install/rollback/p5-m90.sql`).

## Tests

- `tests/adp_db.php`:
  - the congratulations going once the forms are accepted;
  - only once;
  - not for someone whose forms are not accepted.
- `tests/client_contacts_http.py`:
  - a changed line refused without a reason;
  - the edit kept with from what to what, why and who;
  - the scope listing its changes.
- `tests/http_tests.py`: the quantity correction gives its reason.

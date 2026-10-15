# P5-M48 — The agency candidates see (RSS Inc.); "candidate portal"; the activation page on its own

Seen in the first test-mode invitations (15 Oct 2026, P5-M47): the subject read "Crewvia: activate
your employee portal for …" and the body "Your application … with Crewvia has moved forward".
The candidate applied to RSS Inc., not to Crewvia, and is not an employee yet.

The only name setting was the platform's ("Platform name", `brand_name`: header, browser tab,
installed app). Setting it to RSS Inc. would have renamed the whole interface, so the agency gets
its own setting instead.

## What changes

- **Settings → Agency name, as candidates see it** (`agency_name`): the employer named in the
  emails to candidates and on the job posters. Left empty, the platform name is used, as before.
- `agency_name()` (bootstrap) reads it, then the platform name, then the configured app name.
- The portal invitation (`app/invitations.php`) names the agency and says **candidate portal**:
  "RSS Inc.: activate your candidate portal for Mechanical Engineer (Immediate Travel)".
- The job poster (P5-M46) shows the agency name.
- `app/views/_layout.php`, `app/pages/activate.php`, `app/views/activate.php`: the activation page.

- **The portal activation page stands on its own.** The owner opened a test invitation in a
  browser where Owner Number 1 was signed in: "Welcome, Tyler Staab" appeared inside the
  administrator's desk (menu, notifications), and activating would have left the owner signed in,
  not Tyler. Now the page never shows a desk (`$publicPage` in the layout); when somebody is
  signed in it says so ("You are signed in as … Activating this portal signs you out of that
  account."); activating signs whoever was there out and the candidate in, straight to their
  portal (Jobs for you), instead of back to the sign-in page. The text names the agency.

After deploying: Settings → Agency name → "RSS Inc." (or Claude sets it, as asked).

## Tests

Added to the P5-M47 tests (`tests/mail_routing_http.py` also opens an activation link while the
administrator is signed in: the page has no office menu and warns; activating lands in the
candidate's portal and the administrator is no longer signed in):
- `tests/mail_routing_db.php`: the invitation queued for a candidate starts "RSS Inc.: activate
  your candidate portal" and says "with RSS Inc. has moved forward"; empty, the platform name
  stands in.
- `tests/mail_routing_http.py`: Settings saves the agency name, the job poster shows it, the
  platform keeps its own name.

Measured on the harness: 7 new checks PASS after the chain and P5-M42 to M47. Mutation: the layout
ignoring the public-page flag (caught: the office menu is back on the activation page). Full
suite: 3693 PASS, 0 FAIL (3686 before, plus the 7 new checks).

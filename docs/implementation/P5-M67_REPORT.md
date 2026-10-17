# P5-M67 — clocking in and out on the site; photos that fit; pay statement notice

The owner, 17 Oct 2026: can the worker sign in on the job site, clock in and
out if hourly? Will a geofence stop a clock-in away from the site, or a code
that cannot be photographed and sent to a colleague? Decided:
- **away from the site**: flag it to the supervisor rather than block, because
  GPS is often wrong inside plants;
- **the code**: the supervisor shows it on their own phone.

## Clock-in by the supervisor's code

- **The supervisor's code.** My team → **Show the clock-in code** (`/clock-qr`).
  - The code changes every 30 seconds: a signed token naming the supervisor,
    the project and a 30-second window.
  - The screen shows a countdown, and the list of who scanned today, with
    anything unusual in red.
- **The worker scans it** with their own phone, signed in. One button: **Clock
  in now**, or **Clock out now** when already in. The phone's position goes
  with it.
- **What does nothing:**
  - a code older than about 90 seconds;
  - a forged code;
  - a code for a project the worker is not on.

  A code clocks in only the account that scans it.
- **The site's position.** Project → site latitude, longitude and clock-in
  radius (300 m by default). **My position here** fills them from the phone,
  on the site.
- **Flagged to the supervisor** (kept, never blocked):
  - away from the site, with the distance, allowing for the phone's accuracy;
  - no position from the phone;
  - a clock-in or clock-out from the portal, without the code. That stays
    possible as a fallback.
- **Clock-out and hours.** The supervisor sees "out at 5:02 pm (8.50 h)". An
  hourly worker sees their hours in the portal.
- **Permissions-Policy.** It now allows geolocation for this site only:
  `geolocation=(self)`.

## Photos that fit the server

Leo's bank details failed twice with no trace. The likely cause: a phone
photo of a few MB above the server's upload limit, answered by a misleading
"up to 10 MB" message.
- **Smaller in the phone.** The bank proof and the I-9 copies are now made
  smaller in the phone before sending (1,600 px, JPEG).
- **The real limit.** A file above the server's limit says that limit.
- **Every refusal written down.** Each refused bank submission goes to the
  activity log, with why, the upload error and the size.

## Pay statements

When payroll approves a period, each person paid in it is told "Your pay
statement for the week ending … is ready", with a link to it.

## Data

Reversible (install/rollback/p5-m67.sql):
- `jobs.site_lat`, `jobs.site_lng`, `jobs.site_radius_m`;
- on `clock_ins`: the in and out method, position, accuracy, distance, flag,
  the supervisor of the code, `clocked_out_at`, `out_by`.

## Tests

`tests/clock_qr_http.py`, 14 checks:
- My team offers the code;
- the code screen and its feed;
- the policy header;
- the earlier portal clock-in flagged;
- these do nothing: a code 2 minutes old, a forged code, another project's
  code;
- clocked out 11 km away: flagged with the distance;
- the supervisor sees the flag and the hours;
- another worker 70 m away: nothing flagged;
- out with no position: flagged.

Full suite: 3,904 PASS, 0 failures.

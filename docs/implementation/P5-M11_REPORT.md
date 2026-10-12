# P5-M11 — the sign-in lock, the client portal switch, frozen weeks on Attendance

The owner's decisions, 12 Oct 2026, from the review:

- **Q1, the login lockout** (DEFERRED_SECURITY X6, BACKLOG Q1): count
  failed attempts only, keep the 15-minute lock, and add an administrator
  unlock.
- **Q2, the client portal:** behind a switch, off by default.
- **Frozen week** (left open by P5-M06b): the Attendance screen refuses a
  worker's hours in a week frozen for pay, as `/sync` already did.

## What was found before building

- **The sign-in limit counted every attempt:** 10 per email and 100 per
  address in 15 minutes, successful sign-ins included. Whoever signed in a
  few times and then mistyped was locked out. Nobody could unlock them;
  the only way out was to wait the 15 minutes.
- **The message never said why.** A locked sign-in read "That email and
  password do not match an account", even with the right password.
- **The client portal was open whenever a client account existed.** No
  setting kept it off. Client accounts could be made on Client access, and
  any account could be given the client desk on People.
- **Frozen weeks:** P5-M06b's `/sync` refused hours in a frozen week. The
  supervisor's and payroll's own entries and corrections already refused
  them too. The worker's own entry on `/attendance` did not.

## What was built

**The sign-in lock** (`app/security.php`, `app/pages/login.php`):

- **Only failures count.**
  - Every attempt is still counted first, atomically, as before, so
    guesses sent at the same instant cannot slip past the limit. The
    existing load test sends 20 at once and still sees at most 10 reach
    the password check.
  - A successful sign-in then clears its email's count and gives back its
    address's.
- **The lock.** Ten failures for one email in 15 minutes lock that email.
  The right password is refused too, until the 15 minutes have passed.
- **The message:** "Too many failed attempts. Try again in 15 minutes, or
  ask an administrator to unlock your sign-in." It reads the same whether
  or not the email has an account, so it does not tell which emails exist.
- **On People** (administrators only):
  - a locked account shows "sign-in locked until 3:45pm" and an **Unlock
    sign-in** button;
  - unlocking clears the count at once and goes in the activity log.
- **Unchanged:** the two-step code limit (6 per 5 minutes), recovery, and
  the per-address limit of 100.

**The client portal switch:**

- **Where:** a setting, `client_portal_enabled`, shown at the top of
  **Client access** with its state. Only an administrator switches it, and
  each switch goes in the activity log.
- **While it is off:**
  - **Signing in:** a client account cannot sign in, by password or Google,
    even with the right password. They read "Client access is switched off.
    Contact the agency." The refusal is logged and does not count as a
    failed attempt.
  - **Already signed in:** a client is put out at their next page.
  - **New access:** no client account is created, none is granted a
    client, and nobody is given the client desk on People.
  - **Removing access:** revoking still works.
- **The upgrade** puts it **OFF** where no client account signs in. Where
  one already does, it leaves it **ON**, so nobody loses access by an
  upgrade. It prints which, once.

**Frozen weeks** (`app/pages/attendance.php`): a worker entering hours for
a day in a week frozen for pay is refused, with "That week is already
approved for pay. Ask the office for a correction." A day in an open week
is taken as before. "Frozen" means the week's sheet is approved or paid,
or its pay period is closed (`attendance_week_frozen`).

**Data:** no new table.

- The switch is a row in `platform_settings`, made by a guarded step in
  `install/upgrade.php`.
- **Rollback:** `install/rollback/p5-m11.sql` deletes that row. Deploy the
  earlier code first: without the switch, client accounts sign in again
  whenever they exist.
- **Translations:** 14 new strings in French and Spanish.

## Tests

- **`tests/access_switches_db.php`:**
  - rollback and upgrade, OFF and announced where no client signs in;
  - a repeat silent;
  - left ON and announced where one does;
  - the fixture: an administrator, a recruiter, a payroll user, a client
    contact, and a worker on site with a frozen week.
- **`tests/access_switches_http.py`** covers:
  - **The lock:**
    - nine failures counted, then a success clears them;
    - successful sign-ins do not raise the address's count;
    - ten failures, then the right password refused with the new message;
    - an unknown email locked with the same words;
    - People showing the lock, and only an administrator unlocking;
    - the unlock logged, and the person signing in;
    - locked again, then open by itself after 15 minutes.
  - **The switch, off:**
    - a client refused with the right password, logged and not counted;
    - the switch shown off on Client access;
    - no creating, granting, or client desk on People;
    - only an administrator switches it.
  - **The switch, on:**
    - recorded in the log, and the client signs in to their portal;
    - switched off again, the client is put out at their next page.
  - **Frozen weeks:** the frozen-week entry refused with nothing written,
    and an open day taken.
  - **French**, and the switch left on for the suites after it.
- **`tests/access_switches_probe.php`:** reads the switch, the counts,
  attendance, activity, client accounts and grants. It can also move an
  email's window 16 minutes back.
- **Existing tests:**
  - `tests/http_tests.py`: after 11 invalid sign-ins it now expects the
    lock message rather than the generic one.
  - `tests/fixture.php`: the main fixture's client contact signs in, so the
    fixture switches the portal on.
- **CSRF sweep:** `/people` unlock and `/client-access` portal.
- **Mutation:** with the client guard removed from
  `security_begin_login()`, the test failed at "With the portal off, a
  client cannot sign in, even with the right password". Restored
  afterwards.

## Not done

- **Locking by address:** a lock is still per email, plus the 100 per
  address. No address is blocked for longer, and no alert is sent when
  many emails fail from one address.
- **A lock on two-step codes:** the two-step limit is unchanged and has no
  unlock button; it opens after 5 minutes.
- **Hours in a frozen week by supervisor approval:** the review button
  still approves a day already submitted. Entering and correcting already
  refused frozen weeks.

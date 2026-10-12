# P5-M06b — offline attendance: trusted phones, hours and gate marks without signal

Built to the architecture approved on 11 Oct 2026
(`P5-M06_MOBILE_ASSESSMENT.md`, 3.2 and the first item of 3.4):

- workers' own hours, and supervisors' gate marks for their own crew;
- a phone stays trusted for 14 days.

Push is P5-M06c.

## What existed, verified before building

- **Hours:** a worker enters hours per day on `/attendance` (one row per
  assignment and day; entering again replaces a day still waiting; an
  approved day is left alone), and the supervisor or payroll approves.
- **Gate marks:** made on the Structure screen, which is open to
  administrators only (one mark per person and day; marking again corrects
  it; "present" puts the person on site). **Supervisors had no gate screen.**
- **Sign-in:** 30 minutes idle, 12 hours at most. Every account has a
  sign-in version that a password change, a two-step change or "sign out
  everywhere" moves on.
- **The phone:** the P5-M06a service worker, which kept only the offline
  page.

## What was built

**Data** (migration `install/offline-sync.sql`; rollback
`install/rollback/p5-m06b.sql`):

- **`device_grants`:** a trusted phone.
  - Holds the token's hash, never the token.
  - A label (the browser's description).
  - The account's sign-in version when it was trusted.
  - The expiry, the last use, and when, by whom and why it ended.
- **`attendance_sync_log`:** everything a trusted phone sent.
  - Its entry id (unique per person), which phone, hours or gate, the
    assignment and day.
  - **When it was typed** (the phone's clock, never later than arrival)
    and **when it arrived**.
  - The answer, with its reason.
- **Nothing changed in `attendance_records` or `assignment_checkins`.** What
  arrives is written there as if typed on the screens: the source of hours
  stays *worker*.

**Trusting a phone** (`/devices`, *Trusted phones*):

- **Who:** a worker or a supervisor, signed in, on their own phone. The
  page says plainly that anyone holding the phone can send hours in their
  name until it is removed. Staff desks are refused.
- **The cookie:** a random 32-byte token in a cookie that is HttpOnly
  (scripts cannot read it), SameSite=Strict, Secure on HTTPS. Only its
  SHA-256 is stored. Trusting the same phone again replaces its grant.
- **It ends:**
  - after 14 days (`device_trust_days` in the config, 1 to 90);
  - when its owner or an administrator removes it;
  - when its owner signs out on that phone. The phone is then also told to
    clear what it kept (`Clear-Site-Data: "storage"`);
  - when the account's sign-in version moves (password change, two-step
    change, "sign out everywhere").
- **Who sees it:** each person sees their phones, with when they were
  trusted, last used and until when. An administrator sees every trusted
  phone and removes any.
- **Where it is linked from:** Attendance links workers and supervisors to
  it.

**`/sync`, what a trusted phone talks to** (`app/pages/sync.php`,
`app/offline-sync.php`):

- **The device cookie is the only way in.** A signed-in session does not
  open it, and the cookie opens no page.
- **Cross-site defence:**
  - the SameSite=Strict cookie;
  - a required `X-Crewvia-Sync` header, which a form from another site
    cannot send and no CORS rule here allows;
  - pushes in JSON only;
  - an `Origin`, when sent, must be this site.
- **Limits:** 200 entries and 256 KB a push.
- **`GET /sync/bootstrap`:** what the phone may keep.
  - For a worker: their own live assignments (project name and dates).
  - For a supervisor: their crew on live assignments (name, project),
    which the phone removes after 24 hours.
  - The screen's words in the owner's language. Nothing else.
- **`POST /sync/push`:** each entry answered on its own.

**The server decides.** The rules are those of the screens, stricter where
a phone could be out of date:

| Entry | Answer |
|---|---|
| Hours, a new day | **Saved**, waiting for approval as on the screen |
| Hours, a day still waiting | **Replaced** |
| Hours, an approved day | **Refused**: ask for a correction |
| Hours, a day rejected **after** the entry was typed | **Refused** as out of date |
| Hours, a day rejected before | **Replaced**, sent again for approval |
| Hours, a week frozen for pay | **Refused** |
| Hours on an ended assignment, someone else's, a future day, a day outside the assignment, beyond 0-24 | **Refused** |
| Gate, by a supervisor for their crew, today or up to 7 days back | **Saved** or **replaced**, with the time it was made; present puts the person on site |
| Gate, after a later mark by anyone (the gate screen included) | **Refused** as out of date; the later mark stands |
| Gate from a worker, hours from a supervisor, someone outside the crew, 8 days back | **Refused** |
| The same entry id again | The same answer, written once (also when two copies arrive at once) |

What was saved is also in the activity log, under its owner. Refusals come
back in the owner's language.

The frozen-week check is **stricter than `/attendance` today**: the screen
does not refuse a worker's entry in a frozen week. That is left as it is
here, and noted below.

**On the phone** (`public/offline-entry.html`,
`public/assets/offline-entry.js`, `public/assets/offline-sync.js`):

- **What the offline screen offers:**
  - workers: assignment, day and hours;
  - supervisors: today's crew with Present and Absent;
  - what is waiting, and the answers (kept 7 days);
  - Send now.
- **The screen is static,** kept by the service worker, and draws
  everything with `textContent`.
- **What is typed** goes into the phone's IndexedDB with its own id and
  the time it was typed.
- **It is sent:**
  - when the screen is opened online;
  - when the connection returns while it is open;
  - with Send now;
  - on Android, also in the background (Background Sync), even with
    Crewvia closed;
  - on iPhone, when the screen is next opened.
- **What the phone holds:** never anything about anyone else beyond a
  supervisor's crew for a day.
  - A phone no longer trusted drops what it was given, keeps only what its
    owner typed, and sends it once trusted again.
  - Signing out on it clears everything, unsent entries included.
- **The service worker** now keeps the offline attendance screen and its
  two scripts as well, still nothing signed in. It serves those kept files,
  and only those, from the phone. The offline page links to the screen in
  the three languages.

**Translations:** French and Spanish (47 strings).

## Tests

`tests/offline_sync_db.php` (5 checks):

- **Rollback:** it keeps every day of hours and every gate mark.
- **Upgrade:** it brings the tables back; silence on a repeat.
- **The fixture:**
  - two workers (one French-speaking) and a supervisor on one project;
  - a worker's ended assignment;
  - a week frozen for pay;
  - a supervisor with no crew, a recruiter, an administrator.

`tests/offline_sync_http.py` (57 checks):

- **Trusting:**
  - the cookie is HttpOnly, SameSite=Strict and set for the whole site;
  - only the hash is stored, and the token appears nowhere in the
    database;
  - trusted for 14 days, listed;
  - a recruiter refused.
- **What the phone keeps:**
  - only the worker's own live assignment, no crew;
  - the words, in French for the French worker;
  - the supervisor's crew for 24 hours; none for a supervisor without one.
- **Defences:**
  - refused without the header, not JSON, from another origin, over 200
    entries;
  - a signed-in session does not open `/sync`;
  - the device cookie opens no page.
- **Hours:**
  - saved as the worker's own, waiting;
  - the same entry again answered the same and written once;
  - the log's typed and arrived times;
  - replaced while waiting;
  - approved refused;
  - rejected after typing refused, typed after it sent again;
  - frozen week refused;
  - eight other refusals, none of them written;
  - a clock ahead held to the arrival time;
  - a refusal in French.
- **Gate:**
  - a supervisor's mark saved with its time, and the person on site;
  - outside the crew, 8 days back and hours from a supervisor refused;
  - a later screen mark stands over an earlier phone mark;
  - a later phone mark replaces it;
  - the activity log.
- **The end of a grant:**
  - removed by its owner, then trusted again;
  - nobody removes another's phone;
  - an administrator sees all and removes one;
  - a password change ends it, and so does expiry;
  - signing out on the phone ends it and clears its storage; signing out
    elsewhere clears nothing.
- **Screens:** Attendance links to it; the offline screen holds no data;
  French.

`tests/mobile_http.py` (24 checks, 3 more than P5-M06a): the service worker
keeps only the two static offline pages and `/assets/` files, each
reachable, and answers only reads (pages being opened, and the files it
keeps).

Also:

- **The access matrix:** `/devices` opened by every role without a PHP
  error, refused to clients.
- **The CSRF sweep:** trusting and removing a phone.

**In a real browser** (Chromium through Playwright, 375 × 812, on the test
server):

- **Worker:**
  - signed in and trusted the phone;
  - with the network cut, opened the offline screen and typed 7.5 hours:
    "No connection: kept on this phone", one entry waiting, nothing in the
    database;
  - network back, Send now: answered *saved*, and the day is in the
    database waiting for approval.
- **Supervisor:** offline, a crew of two; marked one present; sent once
  back online.
- **Sign-out** on the worker's phone left nothing in its storage, and the
  screen said the phone is no longer trusted.
- No script error.

This run found that the offline screen's scripts were not served without a
network, because the service worker only answered page loads. It now serves
the files it keeps.

### Results

Full isolated run: **2655 PASS, 0 FAIL** (browser step skipped). That is
65 checks more than P5-M06a's 2590: this step's 62, and 3 more in the
mobile test for the files the phone now keeps.

Mutation: I let an offline entry overwrite a day already approved for
pay. The suite failed on "An approved day is refused: ask for a
correction", after 2533 passes.

## Not done here

- **Push notifications:** P5-M06c.
- **Real phones:** not yet tried on an iPhone (iOS 16.4 or later, added to
  the Home Screen) and an Android phone. Due before this step is called
  done in use.
- **`/attendance` and frozen weeks:** the screen still takes a worker's
  hours in a week frozen for pay; only `/sync` refuses them. Making the
  screen refuse them too would change P1 behaviour, so it is left for the
  owner to decide.
- **Unsent entries at sign-out:** signing out on a phone wipes entries not
  yet sent. The offline screen shows what is waiting, but the sign-out
  button does not warn.
- **Clock-in and clock-out times, location, photos:** not proposed.

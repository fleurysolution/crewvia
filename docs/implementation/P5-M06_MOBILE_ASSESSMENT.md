# P5-M06 — mobile assessment, and the proposed architecture for offline attendance and push

Status: **architecture approved by the owner on 11 Oct 2026**, with these
decisions:

1. The web app (PWA), not native apps.
2. Offline scope: workers' own hours **and** supervisors' gate marks (3.2
   and 3.4's first item, together in P5-M06b). The crew's names stay on the
   supervisor's phone for 24 hours at most and are wiped at sign-out.
3. A trusted phone stays trusted for 14 days.
4. Push is built, switched off until the owner turns it on.

Built in order: P5-M06a, then P5-M06b, then P5-M06c, each delivered and
tested before the next.

## 1. What exists today, verified in the code

| Area | What is there | Where |
|---|---|---|
| Install to the home screen | A web app manifest (name from the agency's brand setting, icons 192, 512 and SVG, standalone display) linked from every page | `app/pages/app-manifest.php`, `app/views/_layout.php` |
| Service worker | Registered on secure pages. It **caches nothing on purpose**: its own comment says employee records, documents and signed-in pages are not cached, and that offline writes and push "require a separate design" | `public/service-worker.js` |
| Notifications | Crewvia's own notifications, polled every 30 seconds by an open page. A browser notification pops up only while a Crewvia tab is open and permission was given. Nothing reaches a phone that is locked or has Crewvia closed | `_layout.php`, `/notification-feed` |
| Attendance by the worker | The worker enters hours per day per assignment (0 to 24, within the assignment's dates). One row per assignment and day; entering again replaces it, unless the day is already approved. The supervisor or payroll approves or rejects; corrections after approval are kept with the reason | `app/pages/attendance.php`, `attendance_records`, `app/attendance-controls.php` |
| Presence at the gate | Whoever is at the gate marks who is on site today, one mark per person and day, apart from hours | `assignment_checkins`, `app/pages/structure.php` |
| Clock-in times, location, photos | **None.** Attendance is hours per day, not punches | — |
| Sessions | 30 minutes idle, 12 hours at most, then a new sign-in | `app/bootstrap.php` |
| Security headers | No framing (`X-Frame-Options: DENY`), a Content Security Policy that allows connections only to Crewvia itself, HSTS over HTTPS | `public/index.php` |
| Phone layout | Rules for screens under 720 px exist. In a phone-width screenshot taken during P5-M05, the header and the full menu filled the first screen, and the page itself started at its bottom edge | `_layout.php` |
| Native app | None, and no build tooling for one | — |

Small things found on the way, harmless today:

- `public/manifest.webmanifest` is an older static manifest that nothing
  links to. Pages use `/app-manifest`.
- The service worker is registered by a script placed after `</body>`.
  Browsers accept it.
- There is no offline page. Without a connection, the phone shows the
  browser's own error.

## 2. Native app or the web app (PWA)?

| | Web app (PWA), as now | Wrapped web app (Capacitor or similar) | Native apps (iOS and Android) |
|---|---|---|---|
| What workers do | Open the link, "Add to Home Screen" | Install from the stores | Install from the stores |
| Offline entry, synced later | Yes (IndexedDB and a service worker). Sent when the app is opened online. Android Chrome can also send it in the background; iOS cannot | Yes, with background sync on both | Yes |
| Push to a locked phone | Yes. Android: Chrome and others. iPhone: iOS 16.4 or later, **only once added to the Home Screen** | Yes | Yes |
| Location, camera | Available in the browser if ever wanted | Yes | Yes |
| Cost and upkeep | None beyond the server | Apple developer account (99 USD a year) and Google Play (25 USD once); signing keys; store reviews; a second release line | All of that, plus two code bases |
| One code base with what is built | Yes | Mostly | No |

**Recommendation: stay with the web app (PWA).**

- It is the standing rule for phones and tablets across the portfolio
  (PWA, not a native build service).
- Every attendance and notification need found in the code is met by it.
- What only native would add is background location, geofencing and
  reliable background sync on iPhone. Nothing in Crewvia asks for those,
  and background location would raise privacy and labour-law questions of
  its own.

**The one real limit** is the iPhone. A saved entry goes up when the worker
next opens Crewvia online, not on its own in the background. The design
below is built around that.

## 3. Proposed architecture

### 3.1 Before offline: the phone foundation (no sync, no push)

- **A menu that folds away under 720 px**, behind a "Menu" button, so the
  page is on the first screen. The menu's content and its order are kept
  as they are.
- **An offline page.** The service worker keeps only the static shell
  (stylesheets, icons, the offline page) and still never caches a
  signed-in page, a record or a document.
- **Tidying:** the unused static manifest removed; the registration script
  moved inside the page.

### 3.2 Offline attendance (worker's own hours)

**Scope of the first step:** a worker's own daily hours, the same thing
`/attendance` takes today. Gate check-ins by a supervisor would be a second
step (3.4), because they put the crew's names on the phone.

**Signing in a phone (the device grant).**

- Sessions end after 30 minutes idle, so a shift without signal would sign
  the worker out before anything is sent. Sync therefore cannot rely on
  the web session.
- After a normal sign-in, the worker may **trust this phone**. The server
  then creates a device grant:
  - a random token, stored only as a hash;
  - kept in an HttpOnly, Secure, SameSite=Strict cookie limited to the
    sync path, so page scripts cannot read it.
- **It does one thing:** send attendance, and read the worker's own live
  assignments to fill the form. It opens no page, record, document or pay.
- **Lifetime:** 14 days by default (a setting).
- **Ended by:** signing out on the phone, changing the password, the
  worker removing it under Account security, or an administrator.
  Account security lists each trusted phone with its last use.

**What the phone keeps.**

- The worker's own live assignments: project name and dates, nothing else.
- The entries waiting to be sent.
- Nothing about anyone else, no documents, no pay. All of it is wiped at
  sign-out and when the grant ends.

**Sending.**

- Each entry gets an id made on the phone, the time it was entered by the
  phone's clock, the assignment, the day and the hours.
- When the phone is online, the app opens or the browser fires background
  sync (Android), the queue is sent in order to one endpoint, `POST /sync`.
- `/sync` accepts only JSON with a custom header, and allows no
  cross-site calls; together with the SameSite cookie, that is its CSRF
  defence.

**The server decides; the phone never overwrites.** Every entry is checked
by the same rules as `/attendance`, and then:

| Situation found on arrival | Result shown to the worker |
|---|---|
| Nothing for that day | Saved as submitted, for approval as today |
| A submitted entry not yet reviewed | Replaced, as today |
| The day already approved | **Refused**: "already approved; ask for a correction" (the existing correction flow) |
| The day rejected **after** the phone entry was made | **Refused** as out of date; the worker sees the rejection and enters it again |
| The week frozen for payroll | **Refused**, as `/attendance` does today |
| The assignment ended, or the day outside its dates | **Refused** |
| The same entry id sent twice (a lost reply) | The first answer is returned again; nothing is written twice |

- Each arrival is kept in a sync log: entry id, phone, when it was entered
  and when it arrived, and the answer. Payroll can then see that a day
  was entered offline on Tuesday and arrived on Thursday.
- The approval, the week checks and payroll are unchanged.

### 3.3 Push notifications

**Standard web push**, with the agency's own key pair (VAPID):

- the public key goes in the page;
- the private key goes in the server's config, never in the repository
  (`config.example.php` carries `CHANGE_ME`);
- the key pair is made on the server by an install script.

**A push carries no content.**

- It only wakes the phone. The phone then fetches its new notifications
  from Crewvia, through the device grant, and shows them.
- Google, Apple and Mozilla, who carry the push, never see a name or a
  message.
- This also avoids encrypting message bodies on the server, so there is no
  third-party library to add (Crewvia has none today).

**Who gets pushes, and which:**

- each person turns push on, per phone;
- what is pushed is chosen by kind (for example a shift or assignment
  change, training due, a survey to answer, an approval waiting);
- everything stays in Crewvia's notification list as now.

**Off until the owner turns it on.**

- Push means Crewvia calling Google's, Apple's and Mozilla's push servers.
  That is an outside connection, and outside connections are kept off for
  now, like outbound email and the partners.
- So it ships switched off, with a setting, and sends nothing until that
  setting is turned on.
- Failed or expired subscriptions are dropped after repeated failures.

### 3.4 Later, if wanted

- **Gate check-ins offline for supervisors.** It needs the crew's names on
  the phone, for 24 hours at most, wiped at sign-out.
- **Clock-in and clock-out times** instead of hours per day. That would
  change how attendance and payroll work, so it is a decision of its own.
- **Location or a photo at clock-in.** Not proposed: privacy, consent and
  labour-law implications.

## 4. Order of work, once approved

| Step | Content | Needs approval of |
|---|---|---|
| P5-M06a | Phone foundation (3.1) | the layout change |
| P5-M06b | Offline attendance for workers (3.2) | the device grant and its lifetime; the conflict rules |
| P5-M06c | Push, off until turned on (3.3) | outside connections to the push services when switched on |
| later | 3.4 | each item separately |

Each step follows the usual way of working:

- a reversible migration;
- tests in the suite, including:
  - a phone that loses its connection mid-sync;
  - the same entry sent twice;
  - a day approved while the phone was offline;
  - a grant that is revoked, and one that has expired;
- the access matrix and the CSRF sweep extended to `/sync`;
- French and Spanish;
- a report and a mutation test.

## 5. What this assessment did not check

- **HTTPS on the production address.** It is required for the service
  worker, offline and push. The code sends HSTS over HTTPS, but whether
  the live site has a valid certificate was not checked from here.
- **Real phones.** The browser here only emulates a phone width. Before
  P5-M06b is called done, it should be tried on at least one iPhone, with
  Crewvia added to the Home Screen, and one Android phone.

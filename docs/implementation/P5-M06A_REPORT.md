# P5-M06a — the phone foundation

Approved on 11 Oct 2026 with the architecture in
`P5-M06_MOBILE_ASSESSMENT.md` (section 3.1). No offline entry and no push
yet: those are P5-M06b and P5-M06c.

## What changed

**The menu on a phone.**

- Under 720 px the menu now folds behind a **Menu** button next to the
  brand, so the page starts on the first screen.
- Opened, it is a vertical list with the current page's section open, as
  on a desktop. **Escape** closes it and returns to the button. The button
  says whether the menu is open (`aria-expanded`) and which element it
  controls.
- It folds only when scripts run (a `js` class set in the page head). If
  they do not, the menu stays open as before, so it can never be lost.
- Only for someone signed in. Desktop and tablet widths are unchanged.

**An offline page** (`public/offline.html`).

- Shown when Crewvia cannot be reached, in English, French and Spanish.
- No script, no form, no data. It is a static file, so it needs no
  sign-in.

**The service worker** (`public/service-worker.js`).

- It keeps only the offline page, the stylesheet and two icons, and only
  at install.
- It answers only pages being opened, and only reads (GET, navigate). A
  page comes from the server as before; only when the server cannot be
  reached does the offline page show.
- It never stores a response it fetched, so no signed-in page, record,
  document or pay is ever kept on the phone.
- A form sent offline fails visibly; it is not kept and replayed. That is
  P5-M06b's job, with its own rules.
- A new version clears the old caches.

**Tidying.**

- The unused static `public/manifest.webmanifest` is removed. Pages use
  `/app-manifest`.
- The service worker is now registered inside the page, after the menu
  script, instead of after `</body>`.

Files: `app/views/_layout.php`, `public/service-worker.js`,
`public/offline.html`, `public/manifest.webmanifest` (removed),
`app/lang/messages.tsv` (Menu / Menú), `tests/mobile_http.py`,
`tests/run_windows.py`. No database change, so no migration and no
rollback.

## Tests

`tests/mobile_http.py` (21 checks), in the suite:

- **The service worker:**
  - served as JavaScript;
  - keeps only the offline page and `/assets/` files, each of them
    reachable;
  - stores no fetched response;
  - answers only GET page loads;
  - clears old caches.
- **The offline page:** opens without signing in, in three languages,
  with no script, form or data.
- **The manifest:** the old static one is no longer served; `/app-manifest`
  installs standalone with 192 and 512 px icons.
- **The menu button:**
  - absent when signed out;
  - present, closed and tied to the menu for a signed-in worker;
  - the `js` mark set in the head;
  - folding only under 720 px and only with scripts;
  - every script inside the page;
  - "Menú" in Spanish.

**In a real browser** (Chromium through Playwright, at phone width, on the
test server):

- the service worker takes control;
- the cache holds exactly the four shell files;
- with the network cut, the attendance page shows the offline page, with
  no attendance data in it;
- back online, the page comes from the server;
- no script errors.

**In the app's browser pane** (which cannot run service workers, with this
version or the previous one):

- the Menu button opens the list and Escape closes it, focus back on the
  button;
- no sideways scroll at 375 px;
- at 1280 px the fixed sidebar is unchanged and the button hidden.

### Results

Full isolated run: **2590 PASS, 0 FAIL** (browser step skipped). That is
21 checks more than P5-M05's 2569, all of them this step's.

Mutation: I let the service worker keep a signed-in page (`/portal`) on
the phone. The suite failed on "It keeps only the offline page and static
files", after 2483 passes.

## Not done here

- Offline entry of hours and gate marks (P5-M06b), push (P5-M06c).
- Trying it on a real iPhone and Android phone. It is due before P5-M06b
  is called done.

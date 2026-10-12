# P5-M06c — push notifications, built and switched off

Built to the architecture approved on 11 Oct 2026
(`P5-M06_MOBILE_ASSESSMENT.md`, 3.3, decision 4: built, off until the owner
turns it on). **After this upgrade, push is off and nothing is sent.**

## What existed, verified before building

- **Crewvia's notifications:** written to `notifications` across the
  modules, and polled every 30 seconds by an open page. A browser
  notification appeared only while a Crewvia tab was open.
- **The service worker** (P5-M06a and b): no push handler.
- **Outside connections:** off for now (outbound email and partners stay
  disabled).

## What was built

**A push carries no content.**

- Crewvia sends an empty push, signed with the agency's key (VAPID,
  ES256). It says only that there is something new.
- The phone shows "New in Crewvia. Open it to read." in its owner's
  language (the words are kept on the phone when push is turned on), and
  tapping it opens the notifications page.
- Google, Apple, Mozilla and Microsoft see no name and no message, and no
  message body has to be encrypted on the server, so no third-party library
  was added.

**Data** (migration `install/push.sql`; rollback
`install/rollback/p5-m06c.sql`):

- **`push_keys`:** the agency's public key, and its private key sealed with
  the encryption key (`encrypted_private_key`, so the GAP-1002 rotation
  re-seals it with everything else).
- **`push_subscriptions`:** one phone (browser) for one person.
  - Its push service address and that address's hash.
  - The kinds chosen and the account's sign-in version.
  - The last answer, the failures in a row, and when and why it ended.
- **`push_deliveries`:** every push, with the push service's answer.
- **Settings:** `push_enabled` (off) and `push_cursor` (the last
  notification already pushed).

**Turning it on, later** (the order is shown on the page):

1. On the server: `php install/push-keys.php` makes the key pair once.
   - The private key is never printed or written to a file.
   - Running it again changes nothing, since a new pair would cut off every
     phone.
   - It does not turn push on.
2. An administrator turns push on under *Phones and notifications*.
   - Without the key pair, that is refused, with the command to run.
   - What was already waiting is not pushed.
3. For pushes within the minute, `php install/push-dispatch.php` in cron
   every minute. Otherwise they go each time the scheduled workflows run.

Turning it off stops everything at once. Subscriptions are kept for when
it is turned on again.

**Each person, per phone** (`/devices`, now *Phones and notifications* in
everyone's menu except clients'):

- Turn push on for this phone and choose for what:
  - tasks;
  - training;
  - surveys;
  - approvals and requests;
  - recognition and feedback;
  - everything else.

  The browser asks permission and subscribes with the agency's public key.
- Send yourself a test, turn it off for this phone, remove a phone.
- An administrator sees every phone with push on and removes any.
- The page says what to do on an iPhone: add Crewvia to the Home Screen
  first, iOS 16.4 or later.

**Sending** (`app/push.php`):

- **One push per phone,** for everything new since the last pass, only when
  something new is of a kind that phone chose. Never one push per
  notification.
- **Pushes go only to the push services' own addresses:**
  `fcm.googleapis.com`, Mozilla's, Apple's (`web.push.apple.com`,
  `*.push.apple.com`) and Microsoft's (`*.notify.windows.com`).
  - HTTPS only, on the default port, with no redirects followed.
  - Any other address is refused when subscribing, so Crewvia can never be
    pointed at another server. The test suite's stand-in on `127.0.0.1` is
    accepted only in debug.
- **A subscription ends when:**
  - the push service says the phone is gone (404, 410);
  - it has failed 5 times in a row;
  - the account's sign-in version moves (password change, "sign out
    everywhere"), checked before each push;
  - its owner signs out on that phone. That also clears the phone's storage,
    which drops the browser's subscription;
  - its owner or an administrator removes it.

**The service worker:**

- On a push, it shows the kept words, never anything the push carries.
- On a tap, it opens `/notifications`.
- The phone library gained a small `meta` store for those words.

**Translations:** French and Spanish (30 strings).

## Tests

`tests/push_db.php` (5 checks):

- rollback keeps every notification;
- upgrade brings the tables back with push off;
- silence on a repeat;
- the fixture.

`tests/push_http.py` (44 checks), against a stand-in push service started by
the test on 127.0.0.1:8094 that records every request:

- **Off by default:**
  - nothing offered;
  - a subscription refused;
  - the dispatcher sends nothing and calls nothing;
  - only an administrator may turn it on, and not without the key pair.
- **The key pair:**
  - a 65-byte P-256 public key;
  - the private key sealed and never printed;
  - a second run changes nothing;
  - making it does not turn push on.
- **On:** the backlog not pushed.
- **Subscribing:**
  - refused: a non-push address, a push service over http, another port, a
    private address, a look-alike host, no kind chosen, and none stored;
  - kinds kept;
  - an HttpOnly, SameSite=Strict cookie marks the phone;
  - the same phone subscribed again is updated, not doubled.
- **Sending:**
  - one push per phone for five notifications;
  - empty, with no name or message, a TTL;
  - the VAPID header verified against the stored key (ES256, audience the
    push service, at most 12 hours);
  - gone ends it, a failure is counted, every push logged;
  - nothing new sends nothing;
  - a kind not chosen sends nothing;
  - 5 failures end it;
  - a password change ends it before anything is sent.
- **The test push, removal, sign-out:**
  - a test reaches only one's own phone;
  - nobody removes another's;
  - an administrator sees and removes;
  - signing out ends it and clears the phone's storage.
- **The workflows** send the pushes too. **Off again:** nothing sent, and
  nobody can subscribe.
- **Translations:** French.

`tests/mobile_http.py` (26 checks, 2 more): the service worker's push
handler never reads what the push carries, and a tap opens the
notifications page.

Also:

- **The access matrix:** `/devices` for every role, refused to clients.
- **The CSRF sweep:** subscribing to push, and switching push on or off.

**In a real browser** (Chromium, headless, on the test server):

- a push was delivered to the service worker through the DevTools protocol,
  so no push service was involved and nothing left the machine;
- it carried the text "a name and a message that must not be shown";
- the phone showed "Crewvia — Du nouveau dans Crewvia. Ouvrez-le pour
  lire.", the French words kept on it, and nothing the push carried;
- no error.

Chromium's stripped-down headless shell refuses notification permission;
the full Chromium build does not.

### Results

Full isolated run: **2706 PASS, 0 FAIL** (browser step skipped). That is
51 checks more than P5-M06b's 2655: this step's 49, and 2 more in the
mobile test.

Mutation: I let any HTTPS address count as a push service, which would
let anyone point Crewvia's server at any address. The suite failed on
"Refused: an address that is not a push service", after 2588 passes.

## Not done here

- **Push is not turned on, and the key pair is not made on the server.**
  Both are the owner's decision: turning it on lets Crewvia call Google,
  Apple, Mozilla and Microsoft.
- **The cron line** for pushes within the minute is not installed; that is
  part of turning push on.
- **Real phones:** a real push through Google's or Apple's service, on a
  real iPhone (added to the Home Screen) and an Android phone, could not be
  tried from here without calling them.
- **Which notifications count as which kind** is decided by where they lead
  (`push_kind_of`). New screens fall under "everything else" until added.

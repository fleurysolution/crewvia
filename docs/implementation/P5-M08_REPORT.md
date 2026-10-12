# P5-M08 — Twilio for texts and calls from Crewvia, prepared and not live

The owner's request, 12 Oct 2026: set up and prepare a provider so texts
and calls can go from inside Crewvia. Twilio was chosen. It is a
documented provider, with a public API and signed webhooks.

**State after this upgrade:** Twilio is in **sandbox**, with no credentials.
Nothing is sent and nothing leaves Crewvia.

## What existed, verified before building

- **P5-M07:** the text panel; texts and calls through the staff member's
  own phone (`sms:`, `tel:`, Copy for Google Voice); the contact register
  with "texted" and "replied by text"; do not text, do not call.
- **P4-M04/M05:**
  - every provider call through `integration_request()`, with retries only
    where safe;
  - the integration audit trail, never holding a secret;
  - connectors that run in sandbox before live. Live needs a passed trial
    and who at RSS authorized it.

## What was built

**Data** (migration `install/twilio.sql`; rollback
`install/rollback/p5-m08.sql`):

- **`provider_credentials`:** a provider's public settings and its secret,
  sealed (`encrypted_secret`, re-sealed by the GAP-1002 key rotation).
- **`sms_messages`:** every text sent through Crewvia or received by it.
  - The person, the number, the words, sandbox or live.
  - Twilio's id and status, and the error code.
- **`voice_calls`:** every call placed through Crewvia.
  - Both numbers, sandbox or live, Twilio's id and status.
  - A one-hour token naming the call in Twilio's callback.
- **Two guarded additions:** "twilio" among the audit trail's providers,
  and "messaging" among the connector kinds.

**The connector "twilio"** (`app/twilio.php`), listed with ADP and the
applicant-tracking channels on Integrations:

- **Credentials:** entered by an administrator on Integrations. That means
  the account SID (`AC…`), the auth token, and the Twilio number and/or a
  messaging service (`MG…`).
  - They are checked for form.
  - The token is never shown back, and left empty keeps the one saved.
  - **Changing them puts Twilio back in sandbox,** to be checked again.
- **Sandbox (the default):**
  - "Send from Crewvia" and "Call through Crewvia" record what would have
    gone, marked sandbox. Nothing is sent, nothing dialled, and nothing is
    logged as a contact, since nobody was contacted.
  - Webhooks are verified and answered, and change nothing.
- **The sandbox trial:** "Check the account" reads the Twilio account once.
  It sends no text and places no call, and passes when Twilio says the
  account is active.
- **Live:** needs the credentials, the trial passed, and the reference of
  who at RSS authorized it. The connectors' rule applies unchanged.
- **Disabled:** nothing is offered, and webhooks are refused.

**Live texts:**

- One request to Twilio's Messages API: the person's number, the agency's
  number or messaging service, the words, and the address for status
  reports.
- **Never retried:** a send is not safe to repeat, so it is one attempt,
  even on a server error.
- A refusal is shown with Twilio's error code, and kept as failed.
- A text that went is logged in the contact register as *texted*, "Sent
  from Crewvia: …".
- The same rules as P5-M07: no number, someone who asked not to be texted,
  or more than 1600 characters is refused. From a placement, recruiters,
  Logistics and payroll; from a person's page, recruiters.

**What Twilio sends back** (`/twilio-webhook`, public):

- **Every call is checked against Twilio's signature.** It is an HMAC-SHA1
  of the address and the sorted parameters, keyed with the auth token, and
  computed over the agency's own public address (`app_url`), never the
  Host header a caller sends. Unsigned, signed with another token, or
  signed for another address is refused (403), and the refusal recorded.
- **Delivery status:** queued, sent, delivered or failed, updates the text.
- **A reply:**
  - matched to the person by number (the most recently contacted when
    several share one), kept, and logged as *replied by text*;
  - their recruiter is told;
  - the same reply delivered twice is kept once;
  - a number that is nobody on file is kept and attached to no one.
- **STOP** (and Twilio's other opt-out words) records "asked not to be
  texted", noted "Replied STOP by text (Twilio)". **START** lifts only what
  STOP recorded.
- **Calls:** Twilio first rings the staff member's own phone, from the
  account's number. When they answer, Crewvia tells Twilio to connect the
  person, showing the agency's number (TwiML `<Dial callerId>`). An
  unknown or expired call is hung up. The outcome is logged by hand, as for
  any call.

**Screens:**

- **The text panel** gains "Send from Crewvia" and "Call through Crewvia"
  once Twilio is set up and not disabled. In sandbox each says it will not
  send or dial.
- **Integrations:**
  - the Twilio card: state, the account's last four, the number;
  - the credentials form and Check the account;
  - the address to give Twilio for incoming messages;
  - the A2P 10DLC note;
  - the last 30 texts with their mode and status.
- **Translations:** French and Spanish (38 strings).

Also: the push-keys script's message said "Trusted phones"; it now says
"Phones and notifications".

## Before it can go live (the owner's part)

1. **A Twilio account and a number.** Texting US numbers from a business
   also needs **A2P 10DLC registration** (brand and campaign) in Twilio,
   which takes days and has fees.
2. **On Integrations:** enter the SID, token and number, then Check the
   account.
3. **In Twilio:** point the number's incoming messages to
   `https://crewvia.bpms247.com/twilio-webhook?kind=inbound`.
4. **Switch the connector to live,** with who at RSS authorized it.
5. **Staff who call through Crewvia** add their own phone number to their
   account.

## Tests

`tests/twilio_db.php` (6 checks):

- **Rollback:** it keeps the contact register and Twilio's audit lines.
- **Upgrade:** it brings the tables back, announced; silence on a repeat.
- **The start state:** nothing entered, sandbox.
- **The fixture:** an administrator, a recruiter with no phone yet,
  Logistics, a person with a US number on an assignment, one with no
  number.

`tests/twilio_http.py` (58 checks), against a stand-in Twilio started by the
test on 127.0.0.1:8094 that records every request. Crewvia's address is
replaced by it only in debug and only for 127.0.0.1 (`tw_api()`).

- **Not set up:** nothing offered, sending refused, Integrations shows it,
  a webhook refused.
- **Credentials:**
  - five malformed entries refused;
  - a recruiter cannot enter them;
  - the token sealed, opening with the encryption key, absent from the
    stored value and the page;
  - the last four and the number shown; the incoming address given; still
    sandbox.
- **Sandbox:**
  - the panel says it will not send;
  - a text recorded, nothing requested from Twilio, no contact logged;
  - a call needs the caller's own phone, then is recorded, nothing dialled;
  - a signed STOP in sandbox changes nothing.
- **Going live:**
  - refused before the check;
  - the check is one GET of the account with the SID and token, passing on
    "active";
  - live refused without the authorization, then live with it.
- **Live texts:**
  - the exact form sent (To, From, Body, StatusCallback);
  - Twilio's id and status kept, the contact logged;
  - Twilio's 21211 refusal shown and kept, one request;
  - a server error not retried;
  - 1601 characters refused, no number refused;
  - Logistics sends from a placement, not from a person's page.
- **Webhooks:**
  - unsigned, wrong token and wrong address refused, changing nothing;
  - delivered recorded;
  - a reply matched, logged and its recruiter told; a duplicate kept
    once; a stranger kept unattached;
  - STOP recorded and the panel withdrawn, a text refused; START lifting
    it.
- **Calls:**
  - the exact request (the caller's phone, the agency's number, the voice
    and status addresses with the call's token);
  - the TwiML connecting the person with the agency's caller id;
  - an unknown call hung up;
  - status recorded;
  - someone who asked not to be called gets no call button, and a call is
    refused.
- **Changed credentials:** the token kept when left empty, back in sandbox
  with the trial cleared.
- **Disabled:** nothing offered, webhooks refused.
- **Audit:** the audit trail holds the outcomes and never the token.
- **Translations:** French.

Also:

- **The access matrix:** Integrations stays administrators only.
- **The CSRF sweep:** sending, calling, saving the credentials, checking
  the account.

### Results

Full isolated run: **2879 PASS, 0 FAIL** (browser step skipped), with
P5-M09. That is 122 checks more than P5-M07's 2757: this module's 64 and
P5-M09's 58.

Mutation, on the module's own tests (the fast harness, not the whole
suite): I let a webhook through without checking Twilio's signature. The
tests failed on "An unsigned webhook is refused", after 40 passes.

## Not done here

- **Sending to many people at once:** each text goes to one person.
- **MMS (pictures)** and **call recording**.
- **Incoming calls:** someone calling the Twilio number back is not routed
  by Crewvia.
- **A real Twilio account:** none was used. Every request was checked
  against the stand-in, built from Twilio's documented API.

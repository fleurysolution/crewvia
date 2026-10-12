# P5-M07 — SMS, calling, candidate communications, travel providers

The plan: "SMS, calling, candidate comms, travel providers, per documented
requirements and provider access." The owner's requirement, given on
12 Oct 2026: use **Google Voice**, which is free until the agency wants to
upgrade.

## What existed, verified before building

| Area | Existed | Missing |
|---|---|---|
| Calling | `tel:` links on the person's page, the candidates list, the roster and the placement. A contact register (`candidate_calls`) with outcomes, who and when; "last contacted"; a new person becomes "contacted" | — |
| Email | `mailto:` links; "emailed" and "replied by email" in the same register; the Gmail inbox | — |
| Texting | none | texts, and any record of them |
| Wording | none | messages written once, filled from what Crewvia knows |
| Consent to be contacted | privacy-notice consent (P4-M06) | "do not text" and "do not call" |
| Internal messages | project channels between staff (`/comms`) | — |
| Travel | flights, drives and pickups typed in by Logistics (`travel`), hotels and rooms (`lodging`) | a provider |

## Google Voice: what it allows

**Google Voice has no public interface** through which another program
could send a text or place a call, and automating it would break Google's
terms. So Crewvia does not send anything itself, and no SMS or calling
provider is connected. Instead:

- **A call** stays a `tel:` link.
  - On an Android phone with the Google Voice app set to make calls, the
    call goes out from the Google Voice number.
  - On an iPhone, or with Voice not set to make calls, it goes out from the
    phone's own number.
- **A text** is written in Crewvia, then:
  - **Text** opens the phone's texting app with the number and message
    already in (`sms:`). On an iPhone that is always Messages, which sends
    from the phone's own number. On Android it is whatever app the phone
    uses for texts.
  - **Copy for Google Voice** copies the message and opens Google Voice
    (`voice.google.com`), where it is pasted and sent from the agency's
    Google Voice number, on any device.

The panel says this plainly under the buttons.

## What was built

**Data** (migration `install/messaging.sql`; rollback
`install/rollback/p5-m07.sql`):

- **`message_templates`:** a message kept once.
  - A name, a language (English, Spanish, French), where it is sent from
    (a person's page, or a placement), and the wording with placeholders.
  - **Six to start from:** following up, the start reminder, and travel
    and hotel, each in English and Spanish.
- **`contact_preferences`:** someone who asked not to be texted, or not to
  be called, since when and how they asked (for example "replied STOP").
- **`candidate_calls`** (the existing register):
  - two more outcomes, *texted* and *replied by text*;
  - the message a text was written from (`template_id`).

  Texts are contacts like calls and emails: one register, one "last
  contacted".

**Writing messages** (*Text messages*, recruiters and administrators):

- Placeholders:
  - about the person: `{first_name}`, `{name}`, `{sender}` (who is
    texting), `{agency}`;
  - from a placement only: `{project}`, `{start_date}`, `{hotel}`,
    `{hotel_address}`, `{room}`, `{check_in}`, `{travel}` (each inbound
    leg: carrier, reference, from and when, to and when), `{pickup}`.
- **Refused:** an unknown placeholder (with the list of known ones), an
  assignment detail in a message sent from a person's page, a name already
  used in that language, outside 5 to 1000 characters.

**The text panel** (`app/views/_text-panel.php`, `public/assets/messaging.js`):

- **Where:**
  - on the person's page (recruiters), with the recruiting messages;
  - on the placement (recruiters, Logistics, payroll), with the assignment
    messages first.
- **What is filled in:** the message is filled on the server from the
  person, the assignment, the hotel and the inbound travel, all **typed
  once elsewhere**. What is not on file yet stays visible in brackets
  (`[hotel]`) and is named above the buttons, so a text is never sent with
  a gap unnoticed.
- **Editing:** the text can be changed before it goes; a counter shows
  characters against 160-character parts.
- **The number:** made into a link as `+` and digits. Ten digits are taken
  as North American (+1); a number given with its country code keeps it.
- **Logging:** opening the text (Text) or copying it (Copy for Google
  Voice) logs it in the register as *texted*, with the message's name and
  the words actually sent, under whoever sent it.
  - The person's "last contacted" moves, and someone new becomes
    "contacted", exactly as a logged call does.
  - From a placement, only for that placement's person.

**Do not text, do not call** (on the person's page, recruiters):

- Recorded with how they asked, kept with since when, and noted in the
  person's history. Cleared the same way if they agree again.
- **Do not text:** the panel shows "Asked not to be texted" with the date
  and reason instead of the buttons, on the person's page and on every
  placement. A text to them is refused, and so is logging one by hand.
- **Do not call:** the Call button is replaced by "Asked not to be called"
  and the number is no longer a link, on the person's page and the
  placement. Logging a call to them is refused; logging an email still
  works.

**Translations:** French and Spanish (35 strings).

## Travel providers: not connected

There is no travel provider, no account with one, and no documented
requirement for one. A booking tool (an airline, hotel or travel-agency
interface) cannot be connected without one, so none was.

What travel gained here is the text: Logistics sends the itinerary, hotel
and pickup from the placement, filled from what they already typed, in
English or Spanish.

## Tests

`tests/messaging_db.php` (6 checks):

- **Rollback:** it keeps every contact; a text already logged stays, as a
  contact noted "Text: …".
- **Upgrade:** six messages, "no SMS or travel provider is connected";
  silence on a repeat, nothing added twice.
- **The fixture:**
  - a new person with a US number, on an assignment with a hotel and a
    flight;
  - one with a number abroad and neither;
  - one with no number;
  - a recruiter, Logistics, payroll.

`tests/messaging_http.py` (45 checks):

- **Numbers:** US, with a country code, too short.
- **Messages:**
  - refused: a two-letter name, an unknown placeholder, assignment details
    from a person's page, a name already used, 1001 characters;
  - a recruiter writes one;
  - Logistics cannot.
- **From the person's page:**
  - only the recruiting messages, filled (first name, sender, agency);
  - the exact `sms:` link, number and message encoded;
  - Copy for Google Voice and the iPhone note;
  - the register's two text outcomes;
  - opening the text logs *texted* with the message, and the person
    becomes contacted with a time;
  - the page shows it as the last contact.
- **From the placement:**
  - the assignment's four messages first, then the recruiting ones;
  - the travel text filled from the flight, the hotel's address and the
    pickup;
  - the Spanish reminder with project and date;
  - missing hotel and travel shown in brackets and named;
  - a number abroad kept with its country code;
  - Logistics' text logged;
  - refused without the placement, or for another placement's person.
- **No number:** no text offered, and a text refused.
- **Do not text:**
  - the reason required;
  - recorded with how and since when, and in the history;
  - no text offered on the person's page or the placement;
  - a text refused, and so is logging one by hand.
- **Do not call:**
  - the Call button and the number link gone from the page and the
    placement;
  - a call not logged, an email still logged;
  - cleared, text and call come back.
- **Access:**
  - Logistics does not record preferences;
  - payroll texts from a placement;
  - `/contacts` takes no GET.
- **Translations:** French and Spanish.

Also:

- **The access matrix:** *Text messages* refused to Logistics, payroll,
  supervisors, workers and clients.
- **The CSRF sweep:** logging a text, recording preferences, saving a
  message.

**In a real browser** (Chromium, 375 × 812, on the test server, as a
recruiter, with the Google Voice page blocked so nothing left the machine):

- picking a message rewrote the text and the `sms:` link;
- editing it by hand updated the link (`sms:+525512345678?&body=Hi%20Luis%2C%20edited%20by%20hand.`)
  and the counter (24 / 160);
- **Copy for Google Voice:** put the edited text on the clipboard, said so,
  logged one *texted* contact with the edited words, and opened
  `voice.google.com/u/0/messages`;
- no sideways scroll, no script error.

### Results

Full isolated run: **2757 PASS, 0 FAIL** (browser step skipped). That is
51 checks more than P5-M06c's 2706, all of them this module's.

Mutation: I let a text be logged for someone who asked not to be texted.
The suite failed on "A text to her is refused", after 2658 passes.

## Not done here

- **A provider** that sends texts or places calls from Crewvia itself
  (Twilio, Google Voice for Google Workspace, a carrier). It would need an
  account, its documentation and the owner's choice, and outside
  connections are off for now.
- **Replies:** texts received come back to the phone or Google Voice, not
  to Crewvia. They can be logged by hand as "They replied by text".
- **Group texts to a crew:** each person is texted on their own.
- **Travel booking providers:** see above.
- **Real phones:** the `sms:` and `tel:` links were not tried on a real
  iPhone and Android phone from here.

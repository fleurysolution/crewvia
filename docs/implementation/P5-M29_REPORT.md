# P5-M29 — a campaign by text message

The owner's flow, 14 Oct 2026: recruiters filter the database (welders, for
example), then send a bulk email or a bulk text. Whoever answers is called
and screened.

## Found before building

- **Campaign email** (P5-M18) did the email half:
  - filters by trade, skill, state, distance, availability and past work;
  - the list is fixed when the draft is made;
  - an administrator approves above 50 people;
  - delivery is 50 every 5 minutes.
- **Texting** through Twilio (P5-M08) sent one text at a time from a
  candidate's page, with the STOP register. Nothing sent a text to a list.

## What was built

**Send it as: Email | Text message.** Campaigns starts a text the same way
as an email. A text keeps:
- the same requisition and the same filters;
- the same review of who it goes to;
- the same approval above 50 people;
- the same delivery job (`install/deliver-campaigns.php`).

**Who a text goes to.** People with a mobile number Twilio can deliver to,
with its country code.

Never included:
- anyone who replied STOP;
- anyone on the do-not-rehire register;
- switched-off accounts;
- people already on the requisition.

STOP to texts and unsubscribing from email are separate: a person who stops
texts still gets campaign email, and the other way round.

**The text.**
- Its starting message is short: the job, the place, the start date, the pay,
  the per diem, and the link (portal or application page).
- Up to 480 characters, which is three text messages at most.
- Every text ends with "Reply STOP to stop these texts." Twilio's webhook
  records a STOP before the next run.
- The draft shows the sample as the first person reads it.
- There is no test send for a text. The sample is exactly what goes out.

**Delivery.**
- Texts have their own budget: 50 a run, at most 1,000 in any 24 hours.
- They have their own readiness: Twilio set up and not switched off.
- Email being held does not hold texts. The run's output says what happened
  to each.
- Someone who replied STOP after the list was drawn is skipped.
- In Twilio's sandbox each text is recorded on the person, marked
  "sandbox: recorded, not sent", and nothing leaves Crewvia.
- Live, it goes through Twilio once, and a failure is shown on the recipient.

**Data:** one reversible migration (install/rollback/p5-m29.sql):
- `email_campaigns.channel` (email or text);
- `email_campaign_recipients.phone`;
- the recipient's `email` may be empty for a text.

## For the owner

- **Twilio.** Texts go out live only once Twilio's credentials are entered on
  Integrations and its mode is switched to live. Until then they are recorded,
  not sent.
- **Replies.** A person who answers a text appears in their messages
  (Twilio's inbound webhook). The recruiter calls them from there.
- **US rules for bulk text.** Texts to people who did not agree to receive
  them can break US texting rules (TCPA, and carrier registration for
  business texting, A2P 10DLC). The STOP line and register are in place. Who
  may be texted is the agency's decision.

## Tests

- **`tests/campaign_texts_db.php`:**
  - rollback keeps the email campaigns;
  - upgrade, then silence on a repeat;
  - the fixture: Twilio in sandbox with test credentials, and five people in
    Cheyenne, WY.
- **`tests/campaign_texts_http.py`:**
  - email or text;
  - who a text reaches, against who the email reaches;
  - the 480-character limit;
  - the numbers fixed in international form;
  - the sample with the STOP line;
  - no test send;
  - delivery in sandbox, with a STOP reply honoured;
  - the campaign finished;
  - Twilio switched off, said on texts only.

Found while testing, and fixed:
- With email not switched on, the delivery run stopped before marking
  campaigns finished, so a text campaign stayed "Sending" after its last
  text. Finished campaigns are now marked on every run.

**Mutation:** with the STOP filter removed from the text audience, the test
fails.

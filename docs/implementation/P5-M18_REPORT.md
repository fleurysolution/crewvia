# P5-M18 — T7: campaign email to a filtered list

The owner's request, 13 Oct 2026 (BACKLOG T7):

- **R13:** "Select a filtered list and send one campaign email carrying
  the project, the rates and the per diem."
- **R14:** "First come, first served — they answer through their portal."

The owner's decisions:

- **Sender:** keep the configured `no-reply@bpms247.com`, with replies to
  the recruiter.
- **Audience:** everyone matching the filters, minus opt-outs.
- **Approval:** a recruiter sends to up to 50 people; above that, an
  administrator approves.
- **Pace:** 50 every 5 minutes, at most 500 a day.

## What was found before building

- **Email on the server:** already switched on (`email_delivery_enabled`
  true, `email_from` = `no-reply@bpms247.com`). A relay test on 13 Oct
  reached the Gmail inbox, not spam. No old messages were waiting in
  either queue.
- **The existing delivery scripts** only send account invitations and
  account messages. Nothing sent anything to a list.
- **Contact preferences** (P5-M07) recorded "no texts" and "no calls", but
  not "no email".
- **No agency postal address** was stored anywhere. Commercial email is
  expected to carry one, with a way to unsubscribe.
- **Where people answer:** "Jobs for you" (P5-M13) for people with an
  account, and the requisition's public application page for everyone
  else. Both land in Hiring.

## What was built

**Campaign email** (Recruiting, recruiters and administrators):

1. **Choose an open requisition** and the language (English or Spanish).
   The subject and message start from a template; the message can use:
   `{first_name}`, `{job}`, `{place}`, `{pay_rate}`, `{per_diem}`,
   `{starts}`, `{shift}`, `{link}`.
   - Rates come from the requisition, or else its scope line, as on the
     job board.
   - The client is never named.
2. **Filter the audience:**
   - trade, skill and state;
   - distance from the requisition's site (P5-M12);
   - marked available;
   - worked for the agency before.

   **"See who it goes to"** shows the count and the first 25 names.
   **Never included:** people who unsubscribed, people on the
   do-not-rehire register, people whose account is switched off, people
   with no valid email, and people already on the requisition.
3. **Create the draft.** Its recipients are fixed then: the list reviewed
   is the list emailed.
   - The draft shows the email as its first reader will get it.
   - **"Send me a test"** sends it to the recruiter only, marked [TEST].
4. **Send:**
   - **Up to 50 people:** it goes at once.
   - **Above 50:** the administrators are notified, and it waits for one
     of them to **Approve and send**.
   - Any draft, waiting or sending campaign can be cancelled; what was not
     yet sent is skipped.

**Each email:**

- is sent from the configured sender, with **Reply-To** set to the
  recruiter who wrote it;
- **links each person to where they answer:** "Jobs for you" in their
  portal if they have an account, otherwise the requisition's application
  page;
- ends with an **unsubscribe link** and the **agency's postal address**;
- carries `List-Unsubscribe` and `List-Unsubscribe-Post` headers, so mail
  programs offer one-click unsubscribe.

Each email sent is written on the person's history.

**Unsubscribing** (`/unsubscribe?t=…`, no sign-in; the link's own secret
token is the authentication):

- **Visiting the link changes nothing.** It shows a "Stop the emails"
  button, so a mail scanner opening the link does not unsubscribe anyone.
- **The button, or a mail program's one-click POST, records it:** "do not
  email" goes on the person, anything still queued for them is skipped,
  and it is written on their history.
- **Delivery re-checks every person before sending,** as a second guard.

**Delivery** (`install/deliver-campaigns.php`, in cron every 5 minutes):

- **Pace:** 50 a run, at most 500 in any 24 hours.
- **Held when:** email is off or the postal address is missing. The run
  says so.
- **Skipped people:** anyone who unsubscribed since, and everyone on a
  requisition that closed since.

**Before the first campaign:**

- **The postal address:** an administrator enters it once, on Campaign
  email.
- **The cron line:** add it (docs/OPERATIONS.md §2).

**Data:** `install/campaigns.sql` and a guarded step in
`install/upgrade.php`.

- **New tables:** `email_campaigns` and `email_campaign_recipients`.
- **New column:** `contact_preferences.no_email_since`.
- **New setting:** `campaign_postal_address`.
- **The first upgrade prints:** "Campaign email: …". A repeat is silent.
- **Rollback:** `install/rollback/p5-m18.sql`. Its header says how to keep
  a copy of the opt-outs first.

**Tests never send mail.** In debug only, a `mail_capture_path` platform
setting ending in `.jsonl` writes each message to that file instead of
sending it, as the Twilio stand-in does. Production runs without debug and
always uses the relay.

**Translations:** 55 new strings in French and Spanish. The email's own
footer is written in the campaign's language.

## Tests

- **`tests/campaigns_db.php`:**
  - rollback (text and call preferences kept), then upgrade announced,
    then a repeat silent;
  - **the fixture:**
    - a recruiter, an administrator, Logistics;
    - a chemical requisition in Katy, TX, plus an electrician requisition
      and one on a closed project;
    - one person per exclusion rule, plus Houston, Austin and Dallas;
    - 55 electricians;
    - mail captured to a file.
- **`tests/campaigns_http.py`:**
  - **The audience:**
    - not ready without the address;
    - the chemical people in Texas found, and every excluded person left
      out;
    - within 50 miles: Houston, not Austin or Dallas;
    - 55 electricians flagged for approval.
  - **The draft:**
    - recipients fixed and equal to the preview;
    - replies to the recruiter;
    - the sample showing pay, per diem, link and unsubscribe;
    - refused before the address, which only an administrator enters;
    - the test goes to the recruiter only.
  - **Sending up to 50:**
    - sent at once;
    - one email each from the run, with the sender, Reply-To and one-click
      headers;
    - Ana's link goes to her portal, and the foot carries the unsubscribe
      link and the address;
    - written on her history;
    - a second run sends nothing.
  - **Unsubscribing:**
    - the link shows a button and changes nothing;
    - the button records it, and she never appears in a list again;
    - a false token changes nothing.
  - **Above 50:**
    - approval asked, and the administrators told;
    - a recruiter cannot approve;
    - one-click unsubscribe works without a session;
    - approved, a run sends 50 with the unsubscribed person skipped;
    - at 500 in 24 hours the run waits, and finishes the next time.
  - **Other:**
    - a cancelled draft sends nothing;
    - a requisition closed since is not sent;
    - Logistics is refused;
    - Spanish.
- **`tests/campaigns_probe.php`:** reads campaigns, recipients, opt-outs,
  events and notifications; it can fill or clear a day's 500 sends and
  close a requisition.
- **Access matrix:** `/campaigns` is refused to Logistics, payroll,
  supervisors, workers and clients.
- **CSRF sweep:** create, send and approve.
- **Mutation:** with the unsubscribed no longer left out of the audience,
  the test failed at "never the unsubscribed, the do-not-rehire register…".
  Restored afterwards.

## Not done

- **Opens and clicks** are not tracked; there is no tracking pixel. What
  people do is seen in Hiring.
- **Replies** go to the recruiter's own mailbox. Crewvia does not read
  them.
- **SPF, DKIM and DMARC** for bpms247.com were not checked from here. The
  relay test reached the inbox, but a large campaign's reputation depends
  on them.
- **Attachments and HTML email:** plain text only.

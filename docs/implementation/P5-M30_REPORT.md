# P5-M30 — the worker's trip, from both ends

The owner's flow, 14 Oct 2026:
- **The candidate** enters their travel information: address and airport.
- **The coordinator** books the hotel and the flight, and pastes the ticket
  and the cost into Crewvia.
- **The candidate** receives the itinerary by email and in the portal, with
  the advice the operations VP or the project manager wrote: ID, arrive on
  time, baggage fees, a card and not cash.

## Found before building

- **The worker's details.** Travel showed the coordinator the person's city,
  and nothing else. No address, no airport, no name as on the ID. All of it
  came by phone.
- **The booking form:**
  - It had no departure time and nowhere for the ticket.
  - It offered "Rail" and "Agency vehicle", which the bookings table does not
    hold, so choosing either failed the save.
- **The worker** was told nothing when a trip was booked. The portal showed
  one line: carrier · from → to · a raw timestamp.
- **Advice for travellers** existed nowhere.
- **Email to workers.** The only email channels were the portal invitation
  and password recovery, each tied to its own purpose.
- **The Travel subtitle** said "1 legs booked", untranslated.

## What was built

**Your travel details (portal).** While a trip is ahead (offered, confirmed or
travelling), the worker is asked for:
- the name exactly as on their ID;
- their home address;
- the airport they fly from;
- an optional note.

The home page lists it under what waits on them. Some protections:
- The address is sealed with the workspace key.
- The page tells them never to enter their Social Security number.
- The coordinator is told: "Aaa Trip Worker entered their travel details:
  flies from AUS."

**Travel (the coordinator):**
- Each person to book shows the name as on the ID, the airport, the address
  and the note, or "Travel details not entered yet".
- "Book it" opens the full form:
  - how they travel (flight, drive, bus or train only);
  - airline, confirmation and cost;
  - from (their airport, filled in), leaves, to, arrives;
  - the ticket, pasted;
  - the pickup;
  - "Email the itinerary to them".

**Advice for travellers:**
- Written by the operations VP (an administrator). A coordinator cannot
  change it.
- "Start from the usual advice" gives four lines:
  - photo ID matching the ticket;
  - two hours early;
  - checked-bag fees;
  - a card, because cash is often refused.
- The VP edits or adds lines.
- A project's own advice replaces the agency's.

**Booked: the worker is told.**
- In the portal, with a notification. The trip card shows each leg in words:
  - "Coming to the job: Flight · United · confirmation QX7P2L";
  - "Leaves AUS, Sun 1 Nov 2026, 6:10 am, arrives IAH, …";
  - the pickup;
  - the ticket;
  - "Before you travel" and the advice.
- By email, with the same content. The email is sealed in a new queue
  (`mail_outbox`) and sent by `install/deliver-mail.php`, 50 a run.

**Data:** one reversible migration (install/rollback/p5-m30.sql):
- `travel_profiles`;
- `travel_advisories`;
- `mail_outbox`;
- `travel.ticket_details`.

The secret columns are named `encrypted_*`, so a key rotation
(install/rotate-key.php) re-encrypts them like every other.

## For the owner

- **Cron:** add `install/deliver-mail.php --quiet`, every 5 minutes
  (docs/OPERATIONS.md). Without it, itineraries reach the portal and wait in
  the email queue.
- **Language:** the itinerary email is written in the coordinator's language,
  not the worker's.
- **The project manager.** Supervisors (the PMs) do not open Travel, so the
  advice is the VP's to write for now. A PM card on My team is a later step.
- **The ticket.** The pasted ticket is stored as typed. Keep payment card
  numbers out of it.

## Tests

- **`tests/travel_details_db.php`:**
  - rollback keeps every booking;
  - upgrade, then silence on a repeat;
  - the secret columns are covered by key rotation;
  - the fixture: a coordinator and a worker with a portal and a confirmed
    assignment.
- **`tests/travel_details_http.py`:**
  - the details asked, refused without a street, saved;
  - the address stored encrypted;
  - the coordinator told;
  - Travel showing the details;
  - the form's modes;
  - advice refused to the coordinator, written by the VP;
  - the booking with ticket and cost;
  - the worker told in the portal and by email, sealed in the queue;
  - the delivery run sending it;
  - the portal's itinerary and advice.
- **Mutation:** with the itinerary send removed, the test fails.

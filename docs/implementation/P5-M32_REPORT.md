# P5-M32 — the room number, the clock-in, and the supervisor's validation

The owner's flow, 14 Oct 2026:
- **Room number:** the worker is told their hotel and enters the room number
  within 4 hours. Otherwise they cannot clock in.
- **Clock-in:** the worker clocks in at the site.
- **Validation:** the supervisor validates it by 6:30 for a 6:00 shift.

## Found before building

- **The hotel booking** refused to save without a room number. The
  coordinator had to invent one or wait for the hotel desk.
- **The worker** was not told their hotel when it was booked.
- **Clocking in.** Attendance kept a day's hours. There was no clock-in, no
  time, and nothing for a supervisor to validate.
- **Roll call** (P5-M26) was marked by hand only.

## What was built

**Booking the hotel (Hotels).** The room number is optional: the hotel gives
it at check-in. The worker is told at once, in a notification: "Your hotel:
Walk Inn, from 14 Oct 2026. When you check in, enter your room number in your
portal within 4 hours: you cannot clock in without it."

**The 4 hours** count from 15:00 on the check-in day, or from when the worker
was told, whichever is later. Someone told a week ahead is not late before
they could know their room.

**The portal → Today on (project):**
1. With a stay and no room number, the card asks for it first, with its
   deadline (red once passed). The home page lists it as waiting on them. A
   clock-in is refused until it is entered.
2. Once entered:
   - the stay is checked in;
   - the coordinator is told "Aaa Clock clock is in room 214 at Walk Inn";
   - "Clock in" appears, with the shift and its start.
3. Clocked in:
   - the time is kept;
   - the deadline to validate is 30 minutes after the shift starts, or 30
     minutes after a late clock-in;
   - the supervisor is told "… clocked in at 5:52 am. Validate by 6:30 am.";
   - the worker sees "Waiting for your supervisor", then Validated or "Not
     seen on site: call your supervisor".
   - A second clock-in for the same shift is refused. A night shift after
     midnight counts for the day it started.

**My team → clock-ins to validate.**
- Each clock-in of the supervisor's own crew shows:
  - the time;
  - minutes late, if any;
  - "validate by 6:30 am", or "was due by", in red;
  - Validate and Not here.
- After the deadline, a validation is still kept, and marked "validated late".
- Validate marks them present at roll call; Not here marks them absent and
  tells them to call.
- Another supervisor cannot decide it.
- Crew whose room number is overdue are listed: they cannot clock in.

**Data:** one reversible migration (install/rollback/p5-m32.sql):
`clock_ins`, and `lodging.room_told_at`.

## For the owner

- **On site.** The clock-in is from the worker's phone. It does not check
  where they are. The supervisor's validation is the check that they are on
  site.
- **Times** are Eastern time: Crewvia sets America/New_York in
  app/bootstrap.php. A plant in another time zone (Houston is Central) sees
  shift and deadline times one hour off. That is a decision for the owner
  before the first shift.
- **Late validations** are only shown, on My team. Nothing alerts the VP
  automatically yet.

## Tests

- **`tests/clock_in_db.php`:**
  - rollback keeps room numbers and roll call;
  - upgrade, then silence on a repeat;
  - the fixture.
- **`tests/clock_in_http.py`:**
  - booked without a room, the worker told about the 4 hours;
  - the room asked first, clock-in refused, a bad room refused;
  - the room saved, the stay checked in, the coordinator told;
  - the clock-in with its deadline rule, the supervisor told;
  - a second clock-in refused;
  - My team listing it, the overdue one, and the room overdue;
  - another supervisor refused;
  - validated in time: present, worker told;
  - decided twice refused;
  - a late validation marked late.

**Mutation:** with the room-number gate removed from the clock-in, the test
fails.

Found while testing, and fixed: the Hotels booking refused to save without
a room number (see above), and the M29 fixture now sets Twilio to sandbox
itself, as an earlier suite can leave it switched off.

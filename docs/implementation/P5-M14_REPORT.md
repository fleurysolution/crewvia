# P5-M14 — T19: lateness deductions, with the reason the worker sees

The owner's request, 12 Oct 2026 (BACKLOG T19, requirement R29:
"Lateness deductions, with the reason visible, so nobody can claim they
were paid short"), with these decisions:

- **Measure:** the arrival time against the shift start.
- **Deduction:** unpaid time. Nothing is docked within a grace period;
  past it, the lateness is rounded up to a step.
- **Billing:** each project chooses whether the docked time is still
  billed.
- **Who:** the supervisor records it, the worker can dispute it, and
  payroll settles the dispute.

## What was found before building

- **No arrival time anywhere:**
  - hours are one number per day (`attendance_records`);
  - the site roll call is present or absent (`assignment_checkins`);
  - a shift is free text (`assignment_details.shift_label`).
- **How hours reach pay:** a week's approved days are summed into the
  weekly sheet ("import attendance" on Hours). `week_money()` pays and
  bills `hours_worked`.
- **The pay rules** (P1-M03) read each day's approved hours again, for
  their daily lines.
- **No deduction of any kind for lateness.**

## What was built

**The rules per project** (Lateness, payroll):

- **Shift start, grace and rounding:**
  - the shift start;
  - the grace, 0 to 30 minutes (5 by default);
  - the step to round up to: 1, 5, 6, 10, 15 or 30 minutes (15 by
    default).
- **Billing:** whether the client is still billed for docked time. Off by
  default: time not worked is neither paid nor billed.
- **An assignment's own shift start** (night crews, staggered starts) wins
  over the project's. An arrival after midnight for an evening shift
  counts from the evening before.
- **A worked example** under the form says what these rules dock:
  "7 minutes late docks 15". The page says plainly that only time not
  worked is docked, never a fixed fine.

**Recording** (a supervisor for their own crew, or payroll):

- **What is entered:** the day, the arrival time, and the reason the
  worker will read.
- **What Crewvia keeps:**
  - the minutes late and the minutes docked;
  - the rules in force at the time, so a later change of rules does not
    rewrite history.
- **It is refused for:**
  - a day outside the assignment, or in the future;
  - a week already frozen for pay (the office settles those as an
    adjustment);
  - an arrival not after the shift start;
  - a second record for the same day;
  - a missing reason;
  - someone outside the supervisor's crew.
- **The worker is notified at once**, in the app, and by push when push
  is on.

**The worker** (Lateness, in their menu):

- **What they see:** each late arrival, with the shift start, the arrival,
  the minutes late, the minutes unpaid, the reason and who recorded it.
- **Disputing:** they can dispute one with their own reason.
- **While disputed, nothing is docked.** Payroll is notified.

**Payroll settles** a dispute, or any recorded lateness:

- **upheld;**
- **reduced** to fewer minutes;
- **cancelled**, docking nothing.

The decision carries a note the worker reads, and the worker is notified.

**Into pay:**

- **Importing attendance** into the weekly sheet takes each day's docked
  time off that day's approved hours, never below zero. The sheet keeps
  what was docked (`late_docked_hours`) and what is still billed
  (`late_billed_hours`).
- **`week_money()`** pays the reduced hours and bills them, plus the
  billed lateness where the project asks for it.
- **The pay rules** take the docked time off each day too, so daily lines
  see it.
- **A week with an open dispute cannot be approved:** "Settle them on
  Lateness, re-import the hours, then approve."
- **Once approved, a week's lateness is frozen:** no dispute, no decision.

**Data:** `install/lateness.sql` and a guarded step in
`install/upgrade.php`.

- **New tables:** `lateness_policies` and `attendance_lateness`.
- **New columns:** `timesheets.late_docked_hours`,
  `timesheets.late_billed_hours` and `assignment_details.shift_start`.
- **The first upgrade prints:** "Lateness: late arrivals against the shift
  start…". A repeat is silent.
- **Rollback:** `install/rollback/p5-m14.sql`.

**Translations:** 66 new strings in French and Spanish.

These are rules recorded, not legal advice. State wage law still applies to
how time is docked and rounded.

## Tests

- **`tests/lateness_db.php`:**
  - rollback (every sheet and day kept), then upgrade announced, then a
    repeat silent;
  - minutes late, including after midnight;
  - grace and rounding (7 minutes → 15, 31 → 45, 16 with no grace → 30,
    exact minutes when the step is 1);
  - times read as typed;
  - the fixture: payroll, a supervisor, Logistics, two workers, last
    week's ten-hour days, and a frozen week.
- **`tests/lateness_http.py`:**
  - **Rules:** none recordable without a shift start; bad rules refused;
    rules saved; the example shown.
  - **Recording:** 7 minutes docks 15, with the worker told; within the
    grace nothing is docked; 31 minutes docks 45.
  - **Refused:** twice, not late, no reason, future day, frozen week,
    another crew, a worker recording, Logistics opening the page.
  - **A night shift:** 00:20 for 22:00 is 140 minutes late, 150 docked.
  - **The worker's page:** their own records only, with reason and minutes
    unpaid; the menu link.
  - **Disputes:** a dispute needs a reason; disputed means not docked;
    payroll told; not disputed twice nor by someone else; only payroll
    settles.
  - **Import and pay:**
    - 50 hours less 15 minutes, with pay $1,492.50 and bill $2,487.50 at
      30/50;
    - approval refused while the dispute is open;
    - a reduction must be smaller, then reduced to 30 with the worker told;
    - cancelled docks nothing;
    - re-imported at 49.25 hours;
    - billing docked time puts the bill back to $2,500.00 while pay stays
      $1,477.50;
    - approved once settled, then frozen.
  - **Spanish.**
- **`tests/lateness_probe.php`:** reads the lateness, the rules, the sheets
  with what `week_money()` pays and bills, and the notifications.
- **Access matrix:** `/lateness` is refused to Logistics and clients.
- **CSRF sweep:** record, dispute, decide and policy.
- **Mutation:** with the day's docked time no longer taken off, the test
  failed at "The import takes only the docked time off". Restored
  afterwards.
- **Pay rules:** the daily docking inside the pay rules is in the code but
  not exercised by a test, which would need a confirmed rule set. The
  existing pay-rules tests run in the full suite.

## Not done

- **Clock-in times:** no clock-in punch (T15) feeds this. The supervisor
  types the arrival time.
- **The roll call screen** does not link to recording a lateness yet.
- **Payslips** do not show a lateness line. The worker reads it on
  Lateness and in the notification, and the sheet carries the docked
  hours.
- **Hours entered short:** if a worker already entered fewer hours for a
  late day, the docking comes off again. Supervisors should approve the
  scheduled hours for a day where they record a lateness.

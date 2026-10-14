# P5-M27 — a plant's shifts and buses, and a markup that prices a line

The owner's request, 14 Oct 2026, for the client presentation:
- **Shifts:** each plant has a day shift (6 am to 6 pm) and a night shift
  (6 pm to 6 am), configurable.
- **Buses:** the bus leaves the hotels at 5.
- **Billing:** the client is billed at a margin over pay, and the owners may
  change it.

## Found before building

- **Shifts:** a plant had no shifts of its own.
  - A scope line carried a free-text shift; each assignment carried a label
    and a start time.
  - Lateness read that start time.
  - Nothing tied the label, the start, the end and the bus together.
- **The bill rate:** it was typed by hand on every line.

## What was built

**A plant's shifts** (`project_shifts`; `app/shifts.php`):
- **Defaults:** a new agreement starts with Day (06:00–18:00, bus 05:00) and
  Night (18:00–06:00, the next day, bus 17:00).
- **Editing:** an administrator changes their hours and bus time, adds another
  (a swing shift), or removes one, on the agreement under "Shifts and buses".
- **Projects made before shifts** have none, so nothing is changed behind
  anybody's back. "Add the usual day and night shifts" adds the two in one
  press.
- **Checked:** a time that is not an hour is refused, and so is a second shift
  with the same name.

**A worker's shift.** The organization chart picks the shift from the plant's
list. The assignment then takes its name and start time:
- the start time is what lateness already reads;
- roll call and clock-in will read it too.

A shift from another plant is refused. Removing a shift keeps its hours on the
assignments of whoever worked it; only the link goes.

**A markup that prices a line.**
- **How it works:** a scope line takes "Markup on pay (%)". With no bill rate
  typed, the bill rate is pay × (1 + markup). A bill rate typed by hand wins.
- **What the table shows:** each line's margin, so a 35% markup on $50 bills
  $67.50 and shows "25.9% margin".

**Data:** one reversible migration.
- **New:** `install/project-shifts.sql` (the table) and
  `assignment_details.shift_id`.
- **Rollback:** `install/rollback/p5-m27.sql`.

## Tests

- **`tests/plant_setup_http.py` and `tests/plant_setup_probe.php`:** 12 checks.
  - **Shifts:** the new agreement's two; the night one shown ending the next
    day; hours changed and a shift added; a bad time refused; the one-press
    defaults on an older project.
  - **Markup:** $67.50 and the 25.9% margin; a typed bill rate winning.
  - **A worker's shift:** picked on the organization chart, its name and its
    18:00 start on the assignment; another plant's shift refused.
- **Mutation:** with the defaults no longer given to a new agreement, the test
  failed at its first check. Restored afterwards.

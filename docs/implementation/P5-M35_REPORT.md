# P5-M35 — the demonstration, loaded and flushed

The owner's request, 14 Oct 2026, for the client meeting on Saturday 17 Oct:
- at least 1,000 employees across 3 plants (500, 300, 200);
- accounts named "Employee Number N", "Supervisor Number N", "VP Number N",
  "Owner Number N", at plus-addresses of one mailbox, all with the same
  password;
- in production, flushed afterwards.

## What was built

**`install/demo-load.php`.** It creates, in one transaction, in about two
seconds:

| | |
|---|---|
| Office | Owner Number 1–3 and VP Number 1–3 (administrators), Recruiter Number 1–4, Coordinator Number 1–2 (hotels and travel), Payroll Number 1–2, Manager Number 1–10 and Supervisor Number 1–65 (supervisors) |
| Client | Demo Gulf Fabrication, invented addresses |
| Plant 1, Baytown TX | 500 people: 100 chemical engineers ($50/h), 200 welders, 50 painters, 10 quality inspectors, 10 mechanical engineers, 130 CNC machinists |
| Plant 2, Port Arthur TX | 300: welders, CNC machinists, painters |
| Plant 3, Lake Charles LA | 200: welders, CNC machinists, painters |

Each plant has:
- Sectors 1, 2 and 3;
- a day shift (06:00–18:00, bus 05:00) and a night shift (18:00–06:00, bus
  17:00);
- billing 40% above pay and a $30 per diem;
- 50 hours guaranteed, paid as 40 + 10 overtime, with a day off the
  guarantee per unexcused absence;
- a project manager;
- 10 hotels in all, a private room each.

There are also:
- **Employees.** Employee Number 1–1000, each on site with a portal account,
  a supervisor (about 15 each), a shift, a sector and a room. The last 10 of
  Plant 1 have just arrived, without a room number, to show the room and
  clock-in flow.
- **Recruiting.** An open requisition on each line of Plant 1, and Candidate
  Number 1–60 (welders and CNC operators near the plants) for the recruiting
  walk-through: filters, a bulk text, screening, the VP's validation.
- **Vans.** 65 vans of 12 to 15 seats.

Safety of the load:
- The password comes from the `DEMO_PASSWORD` environment variable (at least
  12 characters). It is never printed or written anywhere but as each
  account's hash.
- Nothing is emailed or texted by the load.
- It refuses a second load, and refuses if the mailbox's plus-addresses are
  already in use by real accounts.

**`install/demo-flush.php`.**
- Without `--execute` it lists what it would remove, table by table, and
  removes nothing.
- With `--execute` it removes in one transaction:
  - every demonstration record;
  - everything hanging off one: assignments, timesheets, clock-ins,
    notifications, applications, documents and their encrypted files.
- It finds these from the registry the load wrote (`demo_records`), through
  the key columns, until nothing new turns up. A row that belongs to nobody
  in the demonstration is never touched.
- The audit log and the ledger refuse deletes by design. The demonstration's
  lines there stay as the record, and the flush says how many.

**While a demonstration sits beside real work**, a notice from a
demonstration account never reaches a real one, nor the other way round.

## Proved here

On a copy of the test database:
1. Every table's row count was recorded.
2. The 1,000-person demonstration was loaded.
3. People signed in as owner, VP, recruiter, coordinator, payroll, manager,
   supervisor and employee, and worked: a room number, a clock-in, its
   validation.
4. The flush ran.

**Every table came back to its exact count**, except the audit log (kept)
and the sign-in counters (by address, not by account). With the flush
limited to one pass, the test fails on the rows left behind.

## For the owner: running the demonstration

**Recommended: a demonstration tenant**, for example
`crewvia-demo.bpms247.com`. It needs its own database, and is already covered
by the `*.bpms247.com` certificate. A flush there can never touch RSS's live
hiring. You create the subdomain and the database, then:

```
cd /home/veloraweb/public_html/crewvia && php install/upgrade.php
DEMO_PASSWORD='choose-one' php install/demo-load.php --confirm-demo --mailbox=solutionsfleury@gmail.com
```

**In production instead** (your earlier choice):
1. Take a backup first (`php install/backup.php`).
2. Run the same load.
3. Afterwards:

```
php install/demo-flush.php
php install/demo-flush.php --execute
```

Accounts sign in as `solutionsfleury+owner1@gmail.com`,
`solutionsfleury+employee12@gmail.com` and so on, with the password you
chose.

What production cannot undo: anything a real person does to demonstration
data, and any email sent during the demonstration (to your own mailbox).

## Tests

**`tests/demo_cycle_http.py`** (with `tests/demo_cycle_probe.php`):
- refusals;
- the plants, trades, office, sectors, shifts, vans, hotels and crews;
- pay and policy;
- rooms missing and requisitions;
- the refused second load;
- eight roles signing in;
- the room, clock-in and validation;
- the dry run;
- the flush back to the exact counts;
- accounts gone;
- a second flush with nothing to remove.

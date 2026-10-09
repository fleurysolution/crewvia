# Crewvia — action tracker

One line per deliverable, taken one at a time. The requirements they
answer are in [REQUIREMENTS.md](REQUIREMENTS.md); the IDs in the
**Answers** column point there.

**Status:** `done` · `next` · `open` · `decide` (waiting on a decision,
not on work) · `blocked`

Dates are the day the work landed on `crewvia.bpms247.com`.

---

## Done

| # | Deliverable | Answers | Landed |
|---|---|---|---|
| D1 | Project becomes an **agreement**: description, client order reference, site, dates, what it covers | M1 | 8 Oct |
| D2 | **Scope of work**: a line per trade with its own quantity, pay, bill, per diem, guarantee, strike guarantee, overtime | M2 M3 | 8 Oct |
| D3 | Rate chain **line → requisition → placement**, with no project-level fallback | M4 M5 | 8 Oct |
| D4 | Headcount derived from the order; an order cannot exceed what the agreement covers | M6 M7 | 8 Oct |
| D5 | Lifecycle gate reads the scope: described, written, priced, open to applicants | M3 | 8 Oct |
| D6 | Two honest figures: what the agreement commits to, and what the people actually placed cost | M4 | 8 Oct |
| D7 | Careers page quotes the trade's own rate and only the provisions actually agreed | M4 | 8 Oct |
| D8 | Candidates screen: encoding repaired, row actions legible, one footer | S3 S4 | 9 Oct |
| D9 | Approvals becomes a decision queue; chain building moved to Administration | — | 9 Oct |
| D10 | Search opens on **who is where** — site, trade, shift, hotel, supervisor — scoped by role | — | 9 Oct |
| D11 | Hotels hold a **block of rooms**; the board counts it down and refuses an overrun | — | 9 Oct |
| D12 | Project structure renamed **Deployment view**, filed under Deployment | — | 9 Oct |
| D13 | **Site roll call**: present / absent / not marked, kept apart from payroll hours | R26 (part) | 9 Oct |
| D14 | Candidate page opens on **contact**: call, email, record the outcome | — | 9 Oct |
| D15 | A contact can be an **email**, not only a call | — | 9 Oct |
| D16 | One stage vocabulary and one outcome vocabulary, in one place | S6 | 9 Oct |
| D17 | **Trades editable** without a schema change | R12 | 9 Oct |
| D18 | Executive overview shows each project's **trade split** | M3 | 9 Oct |
| D19 | Talent pool filters explained; "Mine" becomes "Assigned to me" | — | 9 Oct |
| D20 | Roster and hotels stop calling everybody an "Engineer" | M2 | 9 Oct |
| D21 | **Application screening no longer resets people to New** when a note is saved | S7 | 9 Oct |
| D22 | A decision note is kept without moving the stage; a settled application stays settled | — | 9 Oct |
| D23 | Requisition form says so when the agreement is fully ordered | — | 9 Oct |
| D24 | Résumés and Talent search get a purpose, an empty state and a person to click | S5 | 9 Oct |
| D25 | **Employee folders** stops answering 404 from the menu | — | 9 Oct |
| D26 | "Candidate ID" spin boxes replaced by lists of people | — | 9 Oct |
| D27 | Manning stops printing raw statuses and colouring them from the wrong table | S4 | 9 Oct |
| D28 | **Do-not-use register**: four states, mandatory reason, who and when, red everywhere, its own filter | R1 R2 R3 R4 R5 | 9 Oct |
| D29 | **Skills**: several per person, confirmed vs claimed, searchable, editable list | R9 R10 R11 R12 R21 | 9 Oct |
| D30 | Demonstration data: one agreement, four trades, ten people, one person on the register | R38 | 9 Oct |

---

## Next — the pool becomes a living database

| # | Deliverable | Answers | Status |
|---|---|---|---|
| T1 | **Worker checks in**: marks themselves available, with an available-from date, from their portal. Recruiters filter on it. | R15 R16 | next |
| T2 | **End-of-assignment rating** by the supervisor, with a note, and the work history that reads "five jobs, five completed" on the person | R6 R7 R8 | next |

These two go together: T2 is what makes the pool worth searching, T1 is
what stops the telephone.

---

## Then — dispatch

| # | Deliverable | Answers | Status |
|---|---|---|---|
| T3 | **Issue board**: a supervisor raises a need from a phone instead of telephoning | R32 R36 | open |
| T4 | **Delegate** an issue to a named person | R33 | open |
| T5 | **Notify** the person delegated to, in the portal and by email | R34 | open |
| T6 | Closing an issue is visible to everybody | R35 | open |

---

## Then — reaching the pool

| # | Deliverable | Answers | Status |
|---|---|---|---|
| T7 | **Campaign email** to a filtered list, carrying the project, the rates and the per diem | R13 R14 | open |
| T8 | **Location filter** — state, city, and distance from the site | R20 | open |
| T9 | Creating a requisition **surfaces the matching available candidates** | R19 | open |
| T10 | Candidate portal shows the jobs they qualify for, and lets them express interest | R18 | open |
| T11 | Candidate keeps their own résumé and skills current from the portal | R11 R17 | open |

---

## Then — onboarding without paper

| # | Deliverable | Answers | Status |
|---|---|---|---|
| T12 | Worker fills their **own** I-9, W-4, ADP and banking details | R22 | open |
| T13 | Identity documents reused on a later job: "has anything changed?" | R23 | open |
| T14 | A requested change to a sensitive field **waits for staff validation** | R24 | open |

---

## Then — hours, pay and billing

| # | Deliverable | Answers | Status |
|---|---|---|---|
| T15 | Worker **clocks in** from their phone; supervisor validates | R26 | open |
| T16 | Supervisor can validate manually, with a note, when the worker could not clock in | R26 | open |
| T17 | Pay and bill computed from validated hours | R25 R27 | open |
| T18 | **Per diem rules**, including a missed day | R28 | open |
| T19 | **Lateness deduction** with the reason visible to the worker | R29 | open |
| T20 | Weekly **hotel folio** reconciliation | R30 | open |
| T21 | Worker sees only their own hours, only while active; **access locked on termination** | R31 | open |

---

## Carried over from earlier work

| # | Deliverable | Answers | Status |
|---|---|---|---|
| T22 | Port **SOPs & Procedures** from BPMS247 | — | open |
| T23 | Port **Collaboration** from BPMS247 | — | open |
| T24 | **Cron to drain the outbound email queue** — mail is generated, nothing sends it on a schedule | — | open |
| T25 | Executive overview layout: better grouping above the project table | R40 | open |

---

## Decisions waiting on Fleury Solutions

| # | Question | Why it matters | Status |
|---|---|---|---|
| Q1 | **Login throttle**: 10 attempts per 15 minutes per email. Raise it, or add an administrator unlock? | It has already locked us out during testing. If Jerry mistypes his password a few times during the demonstration he is locked out for fifteen minutes, in front of everybody. | decide |
| Q2 | Does RSS ever need the **client-facing portal** enabled, or is C8 permanent? | It changes how client orders and invoices are designed. | decide |
| Q3 | At what point does RSS stop paying **ADP**? | Decides whether T17–T19 are a convenience or a replacement. | decide |

---

## Environment gaps, not product gaps

| # | Gap | Effect |
|---|---|---|
| E1 | **Playwright is not installed** on the build machine | The browser step of `tests/run_windows.py` cannot run. Everything after it is run by hand instead. |
| E2 | The multi-tenant isolation steps run only inside the official runner | They come after the Playwright step, so they are skipped with it. |

---

## How a change gets done here

1. Walk the screen and find what is actually wrong — run it, do not read it.
2. Change it.
3. Prove it by exercising the behaviour, including the refusal paths.
4. Full regression: `python tests/run_windows.py`, the warning sweep, the
   translation audit, the encoding scan.
5. Build the archive, verify it deploys on a blank database **and** on an
   existing one, and that the upgrade is silent the second time.
6. Say exactly what changed, file by file, with the SHA-256.

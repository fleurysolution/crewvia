# P5-M66 — the flow carries on after the signature

The owner, 17 Oct 2026, after Leo signed in person: "What happens now? Do the
next people get the notification: he signed, he passed the background check,
now find the hotel? Does the flow follow? Check, and fix it if it does not."

## What was there

The deployment gate (`deployment_blockers`): a placement cannot be confirmed,
travelling or on site until six conditions hold:
- the contract is signed;
- the qualifications are verified;
- the screening is cleared;
- the I-9 is completed by the employer and the W-4 reviewed;
- the onboarding instructions are real;
- every required onboarding task is approved.

The signature itself tells payroll (W-4) and the coordinator who witnessed it
(Section 2).

## What was missing

- **Nobody was told when the last condition cleared.** The office had to think
  of trying the roster.
- **Confirming on the roster told hotel and travel a sentence with no name:**
  "Worker ready for hotel and travel allocation: project …".
- **The person was told nothing:** not that they were cleared, nor that they
  were confirmed.

## What was built

`deployment_ready_notice()`: for each offered placement not yet announced, once
nothing stands in the way:
- the office (recruiting and administrators) is told **once**, by name: ":name
  is cleared for :project (start …): confirm them on the roster";
- the person is told their onboarding is complete;
- the moment is kept in `placements.ready_notified_at`.

It is called where conditions clear:
- a W-4 or I-9 accepted;
- a task approved;
- a screening cleared;
- bank details accepted;
- the office opening the roster, Onboarding or the person's forms. This
  catches what clears elsewhere: a contract, a qualification.

`deployment_confirmed_notice()`, on confirmation:
- hotel and travel are told ":name is confirmed for :project, starting …: book
  the hotel and the travel", with a note when the travel details are not in
  yet;
- the person is told their assignment is confirmed.

On the coordinator screen of a person, a **"Next steps"** card per assignment:
- the conditions still open, each linked to where it is cleared;
- or "Cleared: confirm them on the roster";
- or "Confirmed: hotel and travel were told".

## Data

`placements.ready_notified_at` (install/rollback/p5-m66.sql).

## Tests

`tests/deployment_ready_db.php`:
- not announced while a task waits;
- announced to the office, by name, when the last condition clears;
- a second pass tells nobody again;
- confirmed: hotel and travel are told who, where and when, and that the
  travel details are not in yet.

Full suite: 3,885 PASS, 0 failures (browser screenshots skipped).

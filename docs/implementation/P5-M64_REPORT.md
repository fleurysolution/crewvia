# P5-M64 — the W-4, Section 1 of the I-9 and the direct deposit, filled in the onboarding tasks

The owner, 16 Oct 2026, looking at Leo's portal: the tasks said "Complete
Form W-4 through the payroll provider", "the office completes Form I-9 with
you", "Enter your bank details … in My details". The employee should see the
forms themselves, fill them in there, with the sensitive numbers turning into
dots as they are typed and typed twice to catch a mistake.

Asked whether this reverses P5-M31 ("Crewvia never asks for an SSN"), the
owner chose: **the W-4 and Section 1 of the I-9 in Crewvia**, the SSN kept
encrypted.

## What the worker sees

**The onboarding task holds its form.** A requirement can now be tied to a
form (Onboarding → "Form filled in the task"): Form W-4, Form I-9 Section 1,
or Direct deposit. The upgrade ties the existing requirements by their title
(W-4, I-9, "direct deposit"). The task then shows, instead of a note box:

- **W-4 / I-9**: a button "Fill in my W-4 here" / "Fill in Section 1 of my
  I-9 here", then where the form is (signed on …, waiting, accepted, sent
  back with the reason) and "See what I signed".
- **Direct deposit**: the bank form itself (name on the account, bank,
  routing, account number twice, the proof), then the account in use by its
  last four.

The status reads in words: To do, Sent, waiting to be checked, Done, Sent
back to you.

**The W-4** (`/tax-forms?fill=w4`) follows the official form:
- Step 1: name, address, SSN (or "applied for"), filing status.
- Exemption: claimed for the year.
- Step 2(c): two jobs.
- Step 3: children × $2,200, other dependents × $500, other credits, with the
  total computed as they type.
- Step 4(a)(b)(c).
- Step 5: the statement, word for word ("Under penalties of perjury…"); they
  tick it and sign by typing their first and last name.

**Section 1 of the I-9** (`/tax-forms?fill=i9`) follows the 01/20/25 edition:
- name and other last names used, address, date of birth;
- SSN, optional unless the employer uses E-Verify;
- email and phone, optional;
- one of the four boxes, with the A-Number, I-94 or foreign passport number
  and country, and the date the authorization ends ("none" possible);
- the preparer or translator, with their statement;
- the statement, signed.

**Masked, typed twice.**
- The SSN, the account number and the document number are password fields:
  dots as they are typed, with a "Show" button to check.
- The second entry says at once "The two numbers match / differ", and the
  browser does not send the form until they match.
- The server checks again, plus:
  - an SSN must be one the SSA issues (never 000, 666 or 9xx, nor 00 or 0000);
  - a routing number must pass its checksum;
  - an A-Number has 7 to 9 digits, an I-94 number 11 characters.

**When something is wrong**, the form comes back with the message, filled in
again with what they typed, except the numbers, which are never kept or
shown back.

## What staff see

**Change requests → Forms signed in the portal.**
- Payroll sees the W-4s and the office sees the I-9s, each listed by name, date
  and "SSN ending 1234".
- "Open and decide" shows the form as signed, in full, with Print.
- The footer gives the signature (name, date and time, from the worker's own
  account), the address it came from, and whether the record still matches the
  hash of what was signed.
- **Accept** needs the box:
  - W-4: "I entered this W-4 in ADP";
  - I-9: "Section 1 is complete and legible; Section 2 is done with the person
    and their original documents, within three business days of the first day".
- **Send back** needs the reason. The worker is told, the task returns to them
  with the reason, and they fill the form again.
- Nobody decides a form they signed.

**The task follows the form** and closes by itself when the form is
accepted.
- Onboarding refuses to approve it by hand ("Closes with its form, on Change
  requests").
- The bank task works the same way: it closes when payroll accepts the details
  against the proof (P5-M63).
- A later assignment starts where the forms are: a W-4 or I-9 accepted, or bank
  details in use, close the new task as "Already on file".

**Employment** (I-9 & W-4) shows the forms filled in the portal, with a link.
- The employer's completion (Section 2) accepts an I-9 Section 1 signed in the
  portal in place of an uploaded one.
- The paper route stays possible: the worker can still upload a completed
  official form.

## Who opens what

| Form | In full | Masked (last four) |
|---|---|---|
| W-4 | payroll, administrators | the worker |
| I-9 Section 1 | the office (recruiting) while it waits; then only administrators and the readers they authorize, as identity documents (P5-M33) | the worker |

A recruiter never opens a W-4, and payroll never opens an I-9. Every
opening in full is written to `worker_tax_form_access`, as are the signature,
the acceptance and each sending back.

## Security

- **Encryption.** The whole form, with its numbers, is one JSON value sealed
  with the workspace key (`encrypted_payload`). It is rotated with the key like
  every `encrypted_*` column (GAP-1002).
- **Integrity.** The SHA-256 of what was signed is kept and checked at every
  opening. A form that does not match says so in red and must not be used.
- **No edits.** A signed form never changes: a correction is a new row, the old
  one stays, sent back or replaced.
- **Lists and screens.** Lists and the worker's screens never carry a full
  number. The test checks that the numbers cannot be read in the stored value.
- **Help texts.** The portal's help texts now say where Crewvia asks for the
  SSN, the W-4 and the I-9 in the portal, and never anywhere else, by text,
  phone or email. The portal page itself still has no SSN field (the
  P5-M31 test, adapted).
- **Employee folder.** The bank form on the employee folder is masked and typed
  twice as well. The payroll card no longer says "No bank account data is
  entered here. Use the approved ADP enrollment process."

## For the owner — to know

- **Electronic I-9.** Crewvia now keeps Section 1 electronically. The federal
  rules for electronic I-9s (8 CFR 274a.2(e)-(i)) ask for:
  - integrity controls;
  - an audit trail;
  - a signature attached to the record, with who signed it;
  - a printed copy on request;
  - retrieval by person.

  What is built covers each of these. Have your immigration counsel or your
  E-Verify provider confirm the process before the first real I-9.
- **Section 2 stays in person.** The office examines the original documents
  within three business days, on Employment.
- **Retention.** Keep an I-9 three years after hire, or one year after
  employment ends, whichever is later. Nothing deletes these forms. The
  rollback warns before dropping them.
- **W-4 amounts.** The Step 3 amounts ($2,200 a child, $500 another dependent)
  are constants in `app/tax-forms.php`. Check them against each year's W-4.
- **ADP.** Payroll still keys the W-4 into ADP. "I entered this W-4 in ADP" is
  what closes it.

## Data

Reversible (install/rollback/p5-m64.sql):
- `worker_tax_forms` (the encrypted form, hash, signature, status, review);
- `worker_tax_form_access`;
- `onboarding_requirements.form_kind`;
- `employment_clearance.w4_form_id` / `i9_form_id`.

## Tests

`tests/tax_forms_http.py`, after project_manager_http.py, on the
walkthrough fixture:
- the office ties the requirements to their forms;
- the tasks hold the forms;
- the fields are masked and typed twice;
- these send nothing, and the form comes back filled less the numbers:
  - an SSN mismatch;
  - an invalid SSN;
  - a wrong signature;
- signed: encrypted, nothing readable in storage, the hash kept;
- the task and the employment record follow the form;
- the worker sees the last four;
- a recruiter cannot open a W-4, and nobody approves the task by hand;
- payroll opens it in full (recorded);
- accepting needs the box, and sending back needs the reason; the worker is
  told and fills it again;
- accepted, the task closes;
- the I-9:
  - document number mismatch;
  - no status ticked;
  - signed;
  - payroll cannot open it, the office can while it waits, then no longer;
- direct deposit from the task: a mismatch sends nothing, then it waits, and
  payroll accepts it and the task closes;
- French.

The two older tests were adapted:
- http_tests: the task status is now in words;
- adp_registration: the new sentence about the SSN.

**Mutation tests.** Two mutations, each caught:
- the second SSN entry no longer checked: "Two SSNs that differ" fails;
- a recruiter allowed to open a W-4: "A recruiter cannot open a W-4" fails.

**Full suite.** `SKIP_BROWSER=1 python tests/run_windows.py`: 3,843 PASS,
0 failures (browser screenshots skipped).

## P5-M64b — after the first day in production (17 Oct 2026)

- **The portal broke on deploy.** The catch-up query compared two ENUM
  columns across tables whose collations differ in production. Fixed by
  comparing each form with its literal value (85cd492).
- **The browser filled saved sign-ins into the bank fields.** Chrome put the
  owner's email in "Routing number" and the saved password in "Account
  number": the masked fields were password fields, which browsers read as a
  sign-in form. The secrets are now text fields masked by style (`input.secret`,
  `-webkit-text-security`), with a password field only where the browser has
  no such style. The test checks that no page with these forms has a
  password field.
- **The consignes and the tasks.** The owner wants the forms filled by the
  employees on the platform, those already in onboarding included. The
  upgrade:
  - rewrites the consignes of the W-4, I-9 and direct deposit requirements
    that still sent the worker to ADP or to "My details" (a consigne the
    office wrote itself is left alone; each change is recorded in
    onboarding_requirement_events);
  - reopens, for people still in onboarding (offered, confirmed, travelling),
    the W-4 and I-9 tasks approved with no form signed here, and the direct
    deposit tasks approved with no bank details in use. The crew already on
    site is not asked again.

  Checked on the test database: rewritten 1, custom text kept, task
  reopened, the second upgrade silent.

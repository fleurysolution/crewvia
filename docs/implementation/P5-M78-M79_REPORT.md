# P5-M78 and M79 — "Advance" fixed, the do-not-rehire register by identity, new hires to ADP

## Fix: "Advance to the next stage" erased the project

The Advance button had no handler on the plant page. A click fell through to
the project form, which saved an empty form over the project:
- the description, the site, the dates, the order reference and the notes
  were emptied;
- the lodging, travel and transport boxes were cleared;
- the strike was stood down;
- the project stayed in Planning.

The fix:
- Advance now moves the stage when its signals are met.
- The project form carries its own action (`save_project`).
- An action the page does not know is refused, never saved as the project.

Projects whose Advance was clicked before this fix must have their
description and site typed again.

The stage card now shows only what is left to do. What is done folds under
"Done (n)".

## P5-M78 — the register follows the person by identity

"If it is do not hire, even if he changes his phone or email we should know,
and also why."

Once a W-4 or an I-9 is filled in, Crewvia keeps three things:
- the Social Security number, as a keyed fingerprint (HMAC, never the
  number);
- the date of birth;
- the name as written.

A new record is then:
- **held** when it has the same fingerprint as a blocked record ("same Social
  Security number"), or the same name and date of birth;
- **shown to the recruiter, not held**, when only the first and last name
  match a blocked person. Two people can share a name.

Every message gives the reason the person was put on the register. The lists
mark these records as they already marked a same email or phone.

After a key rotation, the fingerprints are rebuilt from the forms. Upgrade:
the forms already filled in give their identity. Reversed by
`install/rollback/p5-m78.sql`.

## P5-M79 — new hires to ADP, by file or by API

"Prepare it for them: one click and it goes to ADP; be ready whatever the
choice."

**ADP** (Pay and billing, for payroll) lists the people to create in ADP.
- **A person is ready** when, on an assignment confirmed or under way:
  - their W-4 and I-9 are accepted;
  - their direct deposit is verified (or they are paid by check);
  - they have no ADP employee ID yet.
- Otherwise the list says what is missing.

**The file:**
- **Download the new-hire file** builds the CSV from what was signed:
  - legal name, SSN, date of birth, address, email and phone;
  - hire date, job, work state, pay type and rate;
  - the W-4 elections;
  - the direct deposit.
- It is made on demand and never kept. Each download is logged, and the bank
  read is in the bank access history.
- Cells are protected against spreadsheet formulas.
- The column names can be matched to RSS's ADP import template.

**The API:**
- **Send to ADP** works once the credentials ADP gives (client ID, secret,
  certificate and key for mutual TLS) are set in the server configuration
  under `adp_api`. They are never in the repository.
- One click gets a token, sends `worker.hire`, then the direct deposit, and
  keeps ADP's reference (associateOID).
- **Test the connection** checks the credentials.
- The addresses are configurable, to match the ones API Central gives for
  RSS's product.

Payroll then records the ADP employee ID, which takes the person off the
list. The weekly hours stay on the ADP RUN file (Payroll export).

Not checked against ADP itself: there are no credentials yet. The calls and
payloads are tested with ADP's answers stood in. Reversed by
`install/rollback/p5-m79.sql`.

## Tests

- `tests/rehire_identity_db.php`:
  - the fingerprint is not the number;
  - same SSN: held, with the reason;
  - same name and date of birth: held;
  - same name only: shown, not held;
  - the lists flag the record;
  - somebody else is not held.
- `tests/adp_db.php`:
  - ready and missing;
  - the record;
  - the bank access logged;
  - the file and formula protection;
  - without credentials nothing is sent;
  - with stand-in answers: token, hire, deposit, certificate and reference.
- `tests/client_contacts_http.py`:
  - Advance keeps the project and moves it to Mobilising;
  - an unknown action is refused and nothing is saved.

# P5-M68 — the forms register, for the owner and an auditor

The owner, 17 Oct 2026: "Where do I see who signed and in front of whom? The
owner, or if we have to hand it to an auditor?"

**Forms register** (`/forms-register`, Hiring; administrators, and the readers
they authorize for identity documents). It has one row per W-4 and I-9
signed:
- who signed, when, in person and in front of whom (or "without a witness");
- who accepted it, and when;
- Section 2 of the I-9: by whom, when, and its due date;
- the E-Verify case and its result;
- the copies kept;
- how many times it was opened;
- a link to the form and its official PDF.

**In red**: a record that no longer matches what was signed, a form signed
without a witness, Section 2 late, Section 2 overdue.

**Filters**: by form, status, signing dates and name.

**CSV export** (logged as an action), with the same columns. No Social
Security number appears on the register or in the export.

**On each form**, for the same people: **who did what with it, and when**:
signed, opened, accepted, sent back.

For an external inspection (an I-9 audit, for instance), give the export and
the official PDFs, not an account.

## Tests

`tests/forms_register_http.py`:
- the owner sees every form with the witness;
- the I-9 with Section 2, its due date and E-Verify;
- no SSN on the register;
- the W-4 filter;
- the CSV;
- the access log on a form;
- a recruiter is refused.

Full suite: 3,911 PASS, 0 failures.

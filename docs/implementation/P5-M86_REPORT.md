# P5-M86 — the person's page in the recruiter's order; the recruiting checklist

RSS, 17 Oct 2026, going through a candidate's page:
- "Contact first, then the next step."
- "What they applied for and their assignment: two boxes, just that."
- "A recruiting checklist with the questions they need to ask… templated:
  these are the questions we ask forklift drivers, these for CDL… link a
  template to the requisition… once it's filled out and good, they move on."

## The person's page

From the top:
1. **The flow**: their steps, and what comes next.
2. **Applied for** and **Assignment**, two small boxes:
   - Applied for: each open application, as job · project · stage;
   - Assignment: the live placement, or "None for now".
3. **Contact**: call, email, how it went. The text message and the contact
   preferences are folded.
4. **Recruiting checklist**, for each open application whose requisition has
   one:
   - every question, marked Met or Not met, with a note;
   - its state: answered so far, all met, or not all met.
5. **Next step: Send for review** to the requisition's hiring managers.

The full sections (applications, assignment, paperwork, record…) stay below,
folded.

## Checklist templates

**Recruiting → Checklists** (`/checklists`): templates named by type of job
(Forklift driver, CDL driver…), with their questions one per line. Editing
a template keeps the answers to the questions kept as written. A question
taken out is retired, and its answers stay in the record.

The upgrade adds a first template, **Basic questions**:
- authorised to work in the US;
- can travel;
- available on the start date;
- drug test;
- background check;
- valid photo ID.

**A requisition links one or more templates**: when it is created, or from
its line under "QR code & more", next to its hiring managers.

## The recruiter's filter

- A recruiter sends an applicant for review only once every question is
  answered and met.
- Otherwise the page says what is missing:
  - "Finish the checklist first";
  - or "Not every question is met: this applicant does not go for review".
- The owner can still send anyone.

Data: `checklist_templates`, `checklist_items`, `vacancy_checklists`,
`checklist_answers`. Reversed by `install/rollback/p5-m86.sql`.

## Tests

`tests/client_contacts_http.py` covers:
- a template written once;
- the template linked to the requisition;
- the page in the recruiter's order;
- no review before the checklist is done;
- no review with a question not met;
- all met: the applicant goes for review, the note kept.

Checked in the local preview: the person's page in its new order, with the
Basic questions linked to a requisition ("0 of 6 answered").

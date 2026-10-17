# P5-M91 to M93 — applicants a click away; the flow checked by role; Candidates for recruiters, Hiring for HR

## P5-M91 — from a requisition to its applicants

On **Requisitions**, each line's title and its applications count open
**Candidates** filtered on that requisition (`/candidates?vacancy=ID`). It is
the same list as the **Applied to** filter: its applicants, first come first
served.

## P5-M92 — the flow, checked role by role

### The person's page (RSS meeting)

"The next step where it was at the top is good... the recruiting checklist
should be collapsible... as soon as I save it, I want this to go."

- **Next step (Send for review)** is back at the top of the page, under the
  journey. Once the person is sent, the same place shows "In review" or
  "Approved".
- The **Recruiting checklist** is a fold. It is open while nothing is
  answered, and its title shows where it stands.
- **Save the checklist** closes it and brings the page back up to the next
  step.
- A section reached by its link is no longer hidden under the top bar
  (CSS `?v=14`).

### The decision page (RSS meeting)

"We need contact information for that candidate... phone number, email is
most important... the questionnaire... should be linked."

**Candidate reviews** now shows:

- the person's phone and email at the top, as tap-to-call and mail links;
- the recruiter's **Recruiting checklist** answers: Met, Not met or Not
  answered, with the notes and the count met.

The page's subtitle and the hint under **Validate** now say what happens:
the approved person goes to HR for onboarding.

### Possible duplicate

On **Application screening** and **Hiring**, the red banner "Possible
existing candidate... Verify identity..." is replaced by a neutral line: the
tag **Possible duplicate**, then "Same name or contact as <name> · <stage>",
with a link to that person's page. The line is shown when another record has
the same name, email or phone. It is not a do-not-hire flag.

### Found while checking each role

- **HR could not sign in.** Its home was `/home`, and `/home` sends every
  role other than the recruiting, travel and payroll desks to its home: the
  page sent HR back to itself. HR now lands on **Onboarding**.
- **Tasks open their screen.** A new column, `project_tasks.link`, holds the
  screen where a task is done, and **My tasks** shows an **Open** button:
  - the onboarding task opens the person's forms (`/tax-forms?person=ID`);
  - the travel task opens **Travel**.

  The upgrade fills the link on the existing hand-over tasks.
- **A supervisor named HR (onboarding) of a project** got "Not your desk" on
  the forms. They can now open the forms of that project's people:
  - they can open the signature (the code) and photograph the documents;
  - they cannot open other people's forms or the office's screens (Change
    requests, the I-9 itself).

  I-9 Section 2 stays with the office.

## Migration

`install/upgrade.php` adds `project_tasks.link` and fills it on the existing
hand-over tasks. Rollback: `install/rollback/p5-m92.sql`.

## Tests

In `tests/client_contacts_http.py`:

- the requisition count links to its applicants;
- the page order is next step, summary, contact, checklist;
- the checklist is open when unanswered and folded once saved;
- HR lands on Onboarding, with no loop on `/home`;
- the onboarding task links to the person's forms;
- a supervisor named HR opens the forms of the project's people, not
  others', and not the office's screens;
- the decision page shows the checklist answers, the phone and the email.

In `tests/http_tests.py`: the duplicate notice reads "Possible duplicate".

## P5-M93 — Candidates is the recruiter's, Hiring is HR's

"I don't want candidates going into hiring until they get passed... Hiring
should only be people that have been selected by the manager."

### Hiring

**Hiring** lists only the people a hiring manager approved, and those
already offered or hired.

- **The requisition list** gives, per requisition: Approved, To onboard,
  Hired, and its Applicants as a link to Candidates.
- **The requisition page** has the tabs **To onboard**, **Hired**,
  **Withdrawn** and **All**. The Talent pool matches and Add an applicant
  links are gone from it.

### Candidates

The applicants stay on **Candidates**, the recruiter's page, from first
contact through the review.

- When filtered on a requisition, Candidates offers **Add an applicant**. The
  person added opens on their own page.
- The list now shows when each person can start, and **In review** or
  **Approved**.
- The person's page lists what they sent: CV, cover letter, diploma.

### Menus and links

- The recruiter's daily menu no longer has Hiring.
- HR's starts with Hiring.
- In the sidebar, Hiring moves from the Recruiting section to the Hiring
  section, beside Onboarding and the I-9.
- The recruiter's links now open Candidates: the decision notice (except an
  approval), the interview notice, the new-requisition notice, and the Today
  tiles.

### Careers page

The menu has a **Careers page** link, for the owner and the recruiters. It
opens the public job board, with every open job, in a new tab.

No migration.

### Tests updated

- `hiring_http`, `add_applicant_http`, `geo_http`, `bulk_view_http`,
  `availability_http`, `rehire_http`, `reviews_http`, `notices_http`,
  `my_jobs_http`, `offer_flow_http`, `menus_http`, `home_http` and
  `client_contacts_http` now check that Hiring holds the approved people
  only, and that applicants are worked on Candidates.

# P5-M91 to M96 — the recruiting flow by role; Hiring for HR, with the steps to the first day; background and drug sent and received

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

## P5-M94 — from approval to the first day, in Hiring

"The manager is supposed to follow all the steps... and jump in, because the
system is still tracking who is doing what... the next step is scheduling
the drug test, background check."

In **Hiring**, the page of an approved person shows **From approval to the
first day**: one line per step, with where it stands, who has it, and an
**Open** button.

| Step | What it shows | Opens |
| --- | --- | --- |
| Approved | by whom and when, and whether it was expedited | |
| Assigned to the job | the start date, or "Not assigned yet" | Roster |
| HR onboarding | the HR task: who has it, its status, its due date | Onboarding |
| Portal | signed in, or invited and not signed in yet | Onboarding |
| Drug test | to schedule, authorization sent, scheduled (date), done, cleared or not | Background & drug |
| Background check | the same | Background & drug |
| W-4 and I-9 | not filled in, to sign in person, accepted, or sent back | the person's forms |
| Flight and hotel | the flight (landing time) and the hotel, or "after HR" | Travel |
| On site | arrived, or not yet | |

- **Open** switches to the requisition's own project, so the screen shows
  this person.
- The Travel button shows only to those who can open Travel.
- Once the person is approved, the recruiter's tools are gone from the page:
  **Pass to the manager** and **Schedule an interview**.

Test: `client_contacts_http` checks the steps, the absence of the recruiter's
tools, and that a step opens its screen.

### Only what carries them to the first day

"The hiring team doesn't need to go back to the screening questions... the
data has to be stored, but the hiring menu doesn't need to see it."

On Hiring, an approved person's page keeps only:

- the steps above;
- the countersignature of a signed offer;
- the do-not-rehire warning;
- the text message panel;
- **Withdraw**, with a reason.

What is gone from that page:

- the manager review and the interviews;
- the screener questions and the screening checks;
- the application notes, the background card and the activity tab;
- the stage moves and the interest buttons.

The requisition's filter bar no longer has the interest filter or the
screening-score sort.

Nothing is deleted: the screening answers, the interviews and the reviews
stay on the person's page (Candidates) and on Candidate reviews.

## P5-M95 — To onboard stands out

"I want them to right away be able to see that these need to get done."

On the **Hiring** requisition list, the **To onboard** count is:

- **amber** while somebody waits to be onboarded;
- **red**, reading "N past due", once an HR onboarding task is past its due
  date.

The CSS moves to `?v=15`.

Tests (`client_contacts_http`): the count turns amber, then red once the HR
task's date has passed.

## P5-M96 — background check and drug test: sent, then received

"Two buttons. The first one: background check sent. The second one: receive
- pass, fail, or review... For the background check it's HireRight, and for
the drug test it's Quest Diagnostics."

In Hiring, on an approved person's progress, each line works in two steps.

| Line | Sent | Received |
| --- | --- | --- |
| Background check · HireRight | **Send to HireRight**: kept with who and when | **Pass**, **Fail** or **Needs review** |
| Drug test · Quest Diagnostics | **Send to Quest Diagnostics**: kept with who and when | **Pass**, **Fail** or **Needs review** |

- **The line's state.** It reads in turn: Not sent yet, then "Sent to
  HireRight · date", then Passed, Failed or Needs review.
- **Needs review stays open.** The final result is still recorded later.
- **A pass counts toward readiness.** It is the case's "cleared" status, so
  the deployment checks count it, and the office is told when it was the last
  condition.
- **Two guards.** A result cannot be recorded before the check is sent, and a
  check goes with the person's assignment: assign them to the job first.
- **Consent.** The provider collects the candidate's consent itself, by its
  own email and sign-in. A check sent from Hiring therefore needs no portal
  authorization. Background & drug keeps its portal authorization for the
  checks it issues itself.

### The APIs

HireRight (Connect API) and Quest Diagnostics both offer APIs. Nothing here
calls them yet: that waits for RSS's credentials and their documentation.
Once connected:

- **Send** places the order through the API;
- the provider emails the candidate for consent;
- the result comes back by itself onto the same line.

The keys will go in the server's configuration, never in the repository.

## Migration

`screening_cases` gains:

- the statuses `sent` and `needs_review`;
- `sent_at`, `sent_by`, `provider_ref` (the provider's order number, for the
  API).

Rollback: `install/rollback/p5-m96.sql`.

## Tests (`client_contacts_http`)

- both Send buttons are offered;
- no result before the check is sent;
- sent: the case is with HireRight, with who sent it;
- Needs review, then Pass;
- a failed drug test reads Failed.

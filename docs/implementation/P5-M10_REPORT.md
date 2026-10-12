# P5-M10 — a consistent shell and the recruiter's Hiring workspace

The owner's request, 12 Oct 2026: "ensure that the layout is consistent and
pro, specially for the recruiter; look at how the Indeed workplace for
recruiters looks and do better". The owner sent screenshots of Indeed's
employer screens (jobs list, candidates by stage, the candidate review,
Smart Sourcing, Hiring Insights) and chose:

- **Scope:** the shell plus the recruiter workspace.
- **Look:** keep Crewvia's navy.

## What was found before building

- **The recruiter's work was spread over pages that each knew one part:**
  - `/requisitions` holds the requisitions;
  - `/recruitment` shows applicants of the *working project* only, in one
    long list, with the move rules;
  - `/candidates` is the talent pool;
  - `/application-screening` holds the checks;
  - `/contacts` holds texts and calls.

  No screen started from the job. None showed one requisition's applicants
  by stage, or let a recruiter go from one applicant to the next.
- **The header** showed the name, role, language and sign-out as loose
  controls in a row.
- **The pages used inconsistent sizes:** large headings, tall inputs, and
  select boxes whose arrow disappeared on some pages.

## What was built

**Shell** (`public/assets/crewvia-ui.css`, loaded last by the layout; the
navy identity is kept):

- One type scale (22 px titles, 14 px text) and one height for controls
  (36 px). Select boxes keep their arrow.
- Cards, tables, tags and buttons share spacing and borders.
- **The header:**
  - a notification bell with the unread count;
  - an account menu (initials, name and role) holding Account, Account
    security, Phones and notifications (not for clients), the language
    and Sign out.

  The language picker keeps its own button (no script needed).
- A 232 px sidebar.
- On a phone, the folding menu of P5-M06a is unchanged.

**Hiring** (`/hiring`, recruiters and administrators; first entry of the
Recruiting group):

1. **Requisitions list.** Every open requisition, with:
   - its project, discipline and start date;
   - counts of all, new, reviewing, offered and hired applicants, each
     opening that tab;
   - openings and the last application.

   It filters by open, closed or all, and by project.
2. **One requisition's workspace:**
   - **Stage tabs with their counts:** Active, New, Screening, Interview,
     Offered, Hired, Rejected or withdrawn, All.
   - **Sort:** newest, oldest, best screening score, name.
   - **Interest filter:** interested, maybe, not interested, not marked.
   - **Search:** by name, place or degree.
   - **Each row shows:**
     - the screening score;
     - place and stage;
     - degree, years and top skills;
     - required answers given and checks passed, failed or pending;
     - when they applied and were last contacted, and the source;
     - **yes / maybe / no** buttons. A second click clears the mark, and
       who set it and when are kept.
3. **Split review.** Opening an applicant keeps the list on the left and
   shows on the right:
   - interest, previous and next, and "1 of 8";
   - move to a stage, with a note;
   - reject, with the reason kept;
   - the full profile;
   - the screener questions and answers;
   - the checks;
   - background (degree, skills, résumés);
   - the text panel of P5-M07.

   A second tab, *Activity & notes*, merges the application's events and
   the person's calls and texts in one timeline, with a note box.
4. **Talent pool matches** (Indeed's Smart Sourcing, from the agency's own
   people). It lists people who:
   - are in the requisition's discipline;
   - are not already on it;
   - are not on the do-not-rehire register;
   - have no deactivated account.

   Those with completed assignments come first. **Add to this job** makes
   an application from source *Talent pool*, with an event.
5. **Requisition tab:** the requisition's own details, and a link to edit
   it on `/requisitions`.

**Rules: the same as the recruitment page, enforced on the server:**

- A settled application (hired, rejected, withdrawn) cannot be moved.
- Nobody is hired from here: hiring is the person's acceptance in their
  portal.
- **An offer needs:**
  - every check passed;
  - every required question answered;
  - no missing verified document the project requires;
  - no contract under way and no contract-before-deployment policy;
  - its terms written in the note.
- Moving to Screening or Offered sends the portal invitation, as on
  `/recruitment`.
- Nobody is added twice, nobody on the do-not-rehire register is added,
  and nothing is added to a closed requisition.
- Return addresses stay inside `/hiring`.

**Data:** the migration is a guarded step in `install/upgrade.php`. It
adds three columns to `applications`:

- `interest` ENUM('yes','maybe','no') NULL;
- `interest_by`;
- `interest_at`.

The first upgrade prints "Hiring: one workspace per requisition - …" and a
repeat is silent. **Rollback:** `install/rollback/p5-m10.sql` drops the
three columns only; applications, stages, notes and checks are kept.

**Nothing is entered twice.** The workspace reads and writes the same
applications, events, checks, answers, résumés and texts as the other
pages. `/recruitment`, `/candidates` and `/requisitions` are unchanged and
see every change made here.

**Translations:** 85 new strings in French and Spanish
(`app/lang/messages.tsv`).

## Tests

- **`tests/hiring_db.php`:** rollback (applications kept), upgrade
  announced, columns back, a repeat silent. It then builds the fixture: a
  recruiter, an administrator, Logistics; a project with a required
  question; an open and a closed requisition; four applicants; four people
  in the pool (one past worker, one not to be rehired, one in another
  discipline).
- **`tests/hiring_http.py`** covers:
  - the list and its counts;
  - the tabs, the sort and the search;
  - interest set, filtered, replaced, cleared and refused when unknown;
  - every move rule above;
  - rejection with its reason;
  - notes and the activity timeline;
  - the split review with previous and next;
  - an application of another requisition refused;
  - the matches and their exclusions;
  - adding, refused twice, for the do-not-rehire register and for a
    closed requisition;
  - the return address;
  - access for Logistics and for an administrator;
  - the header's bell, account menu and language picker;
  - French and Spanish.
- **`tests/hiring_probe.php`** reads applications and events, sets
  checks, and adds or removes a required document.
- **Access matrix:** `/hiring` is refused to Logistics, payroll,
  supervisor, worker and client.
- **CSRF sweep:** `/hiring` interest, move and add.
- **Mutation:** with the do-not-rehire guard removed from `hw_add()`, the
  test failed at "Never someone on the do-not-rehire register". Restored
  afterwards.

## Not done

- **The other recruiter pages** (`/candidates`, `/recruitment`,
  `/requisitions`) take the new shell's sizes and header, but their own
  layouts were not redesigned. `/hiring` is the workspace that starts from
  the job.
- **Hiring Insights** (Indeed's charts) were not reproduced. The existing
  `/analytics` and `/reports` remain the place for figures.

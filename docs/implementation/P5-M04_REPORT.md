# P5-M04 — training plans, assessments, certification tracking, expirations, role-required training

## What existed, verified before building

| Asked for | Existed | Missing |
|---|---|---|
| Training plans | per-project briefings (`learning_content`): training, safety and legal texts, given to everyone on the project, done with one click (`learning_assignments`) | agency courses; plans that sequence them with due dates |
| Assessments | none | questions, a pass mark, scoring, attempts |
| Certification tracking | Qualifications: types, each worker's with issue and expiry dates, evidence, verification; required per project and per trade; a renewal alert 30 days before expiry | a link between passing a course and the certificate it gives |
| Expirations | for qualifications | for training (a course that has to be redone after N months) |
| Role-specific required training | qualifications per project and trade | training required by desk role, crew trade or project, assigned and tracked |

The project briefings are left as they are: they are per-project notices,
and courses are agency-wide. Certifications stay in Qualifications. A passed
course writes into them, so there is still one register, with its existing
verification, requirements and renewal alerts.

## What was built

**Data** (migration `install/training.sql`; rollback
`install/rollback/p5-m04.sql`):

- `training_courses`:
  - content, a resource link (HTTPS only), a pass mark;
  - how long a completion counts (empty: for good);
  - the qualification it certifies, if any.
- `training_questions`: two to six answers, and the right one.
- `training_plans` and `training_plan_courses`: courses in order, each due
  some days after the plan is given.
- `training_requirements`: a course required of everyone of a desk role,
  every worker of a trade (the trade on their assignment, whatever its
  case), or every worker on a project.
- `training_assignments`: who, which course, why (requirement, plan, by
  hand, renewal), by when; once done, the score and until when it counts.
  A renewal is a new row, so every completion is kept.
- `training_attempts`: every attempt, with its score and answers.

**Rules** (`app/training.php`):

- **No duplicate assignments.** Nothing is assigned twice: anyone with the
  course open, or done and still counting beyond the coming month, is left
  as they are. This holds by hand, through a plan, a requirement, or the
  sync.
- **Assessment:**
  - scored on the server, and every question must be answered;
  - the answer key never reaches the learner's page;
  - passed at the pass mark, and every attempt is kept;
  - a course without questions is confirmed as read and understood.
- **Passing:**
  - completes the assignment, counting for the course's months;
  - for a worker, writes the certificate into their qualifications:
    verified, with the same expiry, the course as evidence, and a line in
    the qualification's history;
  - a qualification already verified that runs as long or longer is never
    replaced.
- **Requirements** assign at once to everyone they cover, due within their
  days. The scheduled workflows (`install/run-workflows.php`) run the same
  sync every pass, so new hires and trade changes are picked up.
- **Renewal:** a completion running out within the month gets a renewal,
  due the day it runs out. Until then the matrix shows it as expiring, not
  missing.
- **Notifications:** everyone given training is told inside Crewvia,
  nowhere else.

**Screens:**

- **Training** (recruiters, administrators):
  - counts by state;
  - courses: settings, the assessment with each question's right answer,
    assigning by hand, and who has it;
  - plans: courses in order, and giving the plan to people;
  - required training: add, stop;
  - the compliance matrix: each person against each requirement (to do,
    overdue, done, expiring, expired), filtered by scope and state, with
    "Assign what is required now".
- **My training** (everyone, workers included): what to do and by when,
  what is done and until when; the course with its material, and its
  assessment or confirmation; the attempts made.
- French and Spanish. As a status, "Due" reads "To do": in French and
  Spanish "Due" is a due *date*.

## Tests

`tests/training_db.php` (5 checks):

- rollback, which keeps every qualification, and upgrade;
- silence on a repeat;
- the fixture: a recruiter, two payroll clerks, logistics; two projects;
  two electricians and a welder on one, a rigger on the other; a
  qualification type; the welder already holding it, verified, until 2035.

`tests/training_http.py` (53 checks):

- **Courses:**
  - refused: a two-letter title, an http link, 0 months, a pass mark of
    101, a qualification that does not exist;
  - set up for 12 months at 75%, certifying the qualification.
- **Questions:**
  - refused: one answer, the same answer twice, a right answer not among
    them;
  - three set.
- **Assigning by hand:**
  - two workers, due in a week, each told;
  - assigning again makes nothing new.
- **Taking it:**
  - the worker sees it, with the content and the three questions, and
    never which answer is right;
  - a question unanswered is refused;
  - all wrong scores 0, two of three scores 67% (under 75): not passed,
    the course still open, every attempt kept;
  - all right: passed at 100%, counting 12 months;
  - the qualification is written verified, with the same expiry, the
    course as evidence and a history line;
  - a passed course is not handed in again; another worker can neither
    hand it in nor open it.
- **The certificate kept:** the welder, holding it until 2035, passes the
  course and keeps their own card and its date.
- **Without questions:** confirmation required; then done, for good,
  certifying nothing for a member of staff.
- **Plans:**
  - refused: 0 days, a course already in the plan, giving it to nobody;
  - the order kept; each course due its days from today;
  - someone who already passed a course is given only what they lack.
- **Requirements:**
  - refused: a trade requirement without a trade, a desk role that does
    not exist;
  - payroll: the other clerk is given it at once, the one who did it is
    left alone, logistics is not;
  - the electrician trade, whatever its case, leaves out the welder;
  - a project: its worker is given it.
- **Compliance:**
  - done and to do per person; the welder not listed against the trade
    requirement;
  - overdue, and expiring, with the counts agreeing;
  - the sync gives a renewal due the day the completion runs out, while
    the matrix still shows "expiring" and the worker sees it to do;
  - filtered by state; a stopped requirement leaves the matrix.
- **The workflows:** a new requirement assigns at once, and the scheduled
  workflows run the same sync without assigning twice.
- **Access:** payroll and workers do not open Training.
- **Translations:** French and Spanish.

Also:

- the access matrix: 20 checks on the two pages; Training is refused to
  logistics, payroll, supervisors, workers and clients, and My training to
  clients;
- the CSRF sweep: 5 forms.

### Results

Full isolated run: **2442 PASS, 0 FAIL** (browser step skipped). That is
58 checks more than P5-M03's 2384, all of them this module's.

Mutation: I let a passed course overwrite any qualification of its type.
The suite failed on "A worker who already holds the certificate for
longer keeps it as it was", after 2326 passes.

## Not done here

- Supervisors seeing their crew's compliance: they take their own
  training under My training; the matrix is for the HR desk.
- Question kinds other than single choice, timed tests, a limit on
  attempts.
- Training cost and hours, external providers, attendance at classroom
  sessions.

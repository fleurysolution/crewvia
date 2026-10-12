# P5-M05 — awards, recognition, configurable rewards, feedback, engagement surveys

## What existed, verified before building

| Asked for | Existed | Missing |
|---|---|---|
| Awards and recognition | Recognition in HR records (P2-M06): award, commendation, safety, years of service, recorded by a recruiter or the person's supervisor, on the worker's record, the worker told | award programmes given every month, quarter or year; nominations; choosing a winner; recognition between colleagues |
| Rewards | none; the payroll adjustment (correction, back pay, reimbursement, recovery) is the only way money reaches a worker outside the week | points, a catalogue of rewards set by the agency, asking for one, deciding it, handing it over |
| Feedback | performance reviews (P2-M02), goals and development (P2-M03), end-of-assignment reviews, disciplinary cases (P2-M06) — all formal, by the HR desk or a supervisor | feedback between colleagues at any time, and asking for it |
| Engagement surveys | none | surveys, anonymity, results |

Nothing from Phase 2 is duplicated or replaced:

- **One recognition register.** An award won by a worker is written into
  `recognitions` through the existing `recognition_add()`, so it appears in
  their HR record and history and they are told, exactly as if a recruiter
  had recorded it.
- **Reviews, goals and cases stay where they are.** Feedback says on the
  screen that it is not a performance review and that a concern does not
  open an HR case.
- **No second entry for money or leave.** A cash reward is paid by payroll
  as a payroll adjustment of a new kind, *reward*, in an open pay period.
  It is never typed in by hand. A time-off reward, once given, adds its
  days to the worker's allowance for that kind of leave and that year,
  inside `leave_balance()`. Time off requests, approvals and payslips read
  it from there.

## What was built

**Data** (migration `install/engagement.sql`; rollback
`install/rollback/p5-m05.sql`):

- `kudos`: a thank-you, public (on the wall) or private, and the points it
  brought.
- `award_programs` and `award_nominations`:
  - an award is given every month, quarter or year, or once per person;
  - it is for workers, staff or everyone, and nominated by anyone or by
    staff desks only;
  - the points it carries;
  - one nomination per nominator, nominee and period; the line written
    into recognitions when a worker wins.
- `reward_points`: every point earned or spent (award, thanks, grant,
  reward, given back). A balance is the sum, never a stored figure.
- `reward_catalog` and `reward_redemptions`:
  - kinds: gift card, merchandise, experience, time off, cash, other;
  - each with a cost in points, who it is for, and optionally how many are
    left;
  - what a request is worth is copied when it is made.
- `feedback_requests`, `feedback_request_people`, `feedback_entries`.
- `surveys`, `survey_questions`, `survey_invitations`, `survey_responses`,
  `survey_answers`.
- `payroll_adjustments.kind` gains `reward`.

Links into older modules (recognitions, payroll adjustments, leave types)
are ids checked by the application, not foreign keys. That way the rollback
tests of those modules are not blocked, the lesson of P5-M03.

**Rules** (`app/engagement.php`, `app/surveys.php`):

- **Colleagues.**
  - For a desk, a colleague is anyone signed in except clients.
  - For a worker or a supervisor, it is the people on the same live
    projects: their crew and its supervisors.
  - That is who they thank, nominate and give feedback to, and whose
    public thanks they see. A worker on another project is neither offered
    nor accepted.
- **Thanks.**
  - Points per thank-you and a daily limit are set by recruiters (0 and
    10 until set).
  - Points come only with the first thanks from the same person in a
    calendar month, so two people cannot trade thanks for points.
  - A private thank-you is seen only by the two people (and
    administrators).
- **Awards.**
  - Nominations are for the current period, once per nominator and
    nominee.
  - A recruiter or administrator chooses the winner, never the nominee
    themself.
  - In a period that closes the others ("not selected") and stops new
    nominations. An award given once per person is not given to them
    again.
  - A worker's win goes into their HR record. A member of staff, who has
    no HR record in Crewvia, is told. The award's points are added once,
    and every nominator of the winner is told.
- **Rewards.**
  - **Asking for a reward** checks who it is for, whether any is left, and
    the balance. The points are held at once.
  - **Deciding it:** cancelling or declining (with the reason) gives the
    points and the item back. Nobody decides their own request.
  - **Cash** is for workers only. It is paid by payroll, from the open pay
    period's own screen, once; the manual adjustment form neither offers
    nor accepts the kind. A locked or submitted period refuses it.
  - **Time off** is for workers only, on a kind of leave with a yearly
    allowance. It counts toward the allowance once it is given, not when
    it is approved.
  - **Points granted by hand** need a reason, and never go to yourself.
- **Feedback.**
  - **Given** to a colleague as praise, a suggestion or a concern, shared
    with the person or not.
  - **Not shared:** read only by the HR desk, a worker's supervisor, and
    whoever asked for it (unless they asked about themself). Never by the
    person it is about.
  - **Asked for:** anyone asks colleagues about themself. The HR desk asks
    about anyone, and a supervisor about their crew. Each person answers
    once.
  - Reading everything about a person is logged.
- **Surveys.**
  - **Questions:** a scale of 1 to 5, would-recommend from 0 to 10 (the
    eNPS: the share at 9-10 less the share at 0-6), one choice, free text.
  - **Audience:** everyone, staff, workers, a desk role, or a project's
    crew and supervisors, asked at the moment it is opened.
  - **While open:** it cannot be changed. Reminders go to those who have
    not answered, naming nobody to the sender. It closes on its date (the
    scheduled workflows) or by hand.
  - **An anonymous survey:**
    - answers carry no name, a random id and no time;
    - the invitation keeps only the day of the answer;
    - results show only from the survey's minimum (3 to 50, 5 by
      default), and it cannot be opened for fewer people than that;
    - a group (staff, supervisors, workers) is broken out only when it
      reaches the minimum too, and only when more than one does.
  - **A named survey** says so to whoever answers, and its written answers
    carry the name.

**Screens:**

- **Recognition** (everyone except clients):
  - say thanks, and the wall;
  - awards: nominate, my nominations, the winners;
  - rewards: my points and their history, the catalogue, my requests;
  - for recruiters and administrators: the thanks settings, granting
    points, awards and the nominations to decide, the catalogue, and the
    rewards asked for (approve, decline, hand over).
- **Feedback** (everyone except clients): give, ask, what is asked of me,
  received, given, my requests and their answers, and, for the HR desk and
  supervisors, reading everything about a person.
- **Surveys** (everyone except clients): the surveys sent to me, answering
  them; for recruiters and administrators, writing, opening, reminding,
  closing and the results.
- **Pay periods** (payroll): *Cash rewards to pay* on an open period.
- French and Spanish throughout (258 strings).

## Tests

`tests/engagement_db.php` (7 checks):

- **Rollback:** it keeps every recognition, and turns a reward already paid
  into a correction of the same amount whose reason starts "Reward:"; the
  payroll kind goes away.
- **Upgrade:** it brings it all back; silence on a repeat.
- **The fixture:** two recruiters, payroll, logistics, a supervisor; a crew
  of three on one project under that supervisor; a worker on another
  project; a leave type allowing 2 days.

`tests/engagement_http.py` (120 checks):

- **Settings:** refused out of range; a worker cannot change them.
- **Thanks:**
  - crew and supervisor offered, another project neither offered nor
    accepted, nor yourself;
  - 10 points and a notification; none for a second thanks that month;
  - the daily limit;
  - the wall: public for the crew and the desk, private only for the one
    thanked, nothing for another project.
- **Awards:**
  - refused: a short name, too many points, a worker creating one, a worker
    nominating where staff nominate, a short reason, a supervisor for a
    workers' award, the same nomination twice;
  - the winner chosen:
    - both their nominations won and the other not selected;
    - written into recognitions through the existing function, with the
      candidate's history line;
    - 50 points once; winner and nominators told; seen in the worker's HR
      records;
  - the month then closed to nominations and to a second winner;
  - a staff winner told, with no HR line;
  - nobody decides their own award;
  - a once-per-person award not given again;
  - the winners listed for everyone.
- **Rewards:**
  - refused: cash for everyone, time off on leave with no allowance, a
    0-point reward;
  - staff are neither offered nor allowed a workers' reward;
  - points held on request, and the last item gone, so nobody else can ask
    for it;
  - too few points refused;
  - granting: refused to yourself or for 0, then given with its reason;
  - the cash amount copied when asked for;
  - cancelling gives the points and the item back; someone else cannot
    cancel;
  - approved and told; cash not handed over outside payroll;
  - declining needs a reason and gives the points back;
  - a floating day: not counted while approved; given, the allowance goes
    from 2 days to 3;
  - nobody decides their own request.
- **Payroll:**
  - the open period lists the cash reward, and the manual form does not
    offer the kind;
  - typing it in by hand is refused, and a recruiter cannot pay it;
  - paid: one adjustment of kind *reward*, 75 for the worker, and the
    request given with that adjustment;
  - paying again is refused; the period lists it as a reward;
  - a locked period refuses one.
- **Feedback:**
  - refused: too short, another project, yourself;
  - shared and not shared: the person reads only the shared one and is
    told only of it; the supervisor and the HR desk read both; a crewmate
    and payroll cannot;
  - asked for about yourself: both told; a worker cannot ask about a
    crewmate; an answer not shared stays hidden even from the one who
    asked about themself; once only; someone not asked cannot open it;
  - a supervisor asks about their crew and reads the answer not shared;
    the worker does not.
- **Surveys:**
  - refused: a minimum of 2, closing today, a project not chosen, a choice
    question with one choice or the same twice; an anonymous survey for one
    person;
  - opened for the crew and supervisor, each told; then not changed;
  - found by the worker, told it is anonymous; someone not asked cannot
    open it;
  - answering: a required question left out, a scale of 6 refused; once
    only;
  - at 1 answer of a minimum of 3: the counts only;
  - the reminder goes only to the one who has not answered;
  - at 4 answers:
    - the scale averages 3.75, would-recommend scores 25, the choices
      counted;
    - written answers without names;
    - no group broken out with one supervisor;
    - no name and random ids in the database, the invitation with the day
      only;
  - a named survey: shown at one answer, with the name;
  - the scheduled workflows close a survey past its date; closed by hand,
    it takes no more answers;
  - two groups that both reach the minimum are broken out: staff 4,
    workers 2, supervisors (none) not.
- **Access:** logistics does not write surveys, a worker does not read
  results, a supervisor does not decide rewards, payroll does not run
  awards.
- **Translations:** Recognition and Feedback in French, Surveys in Spanish.

Also:

- **The access matrix:** the three pages opened by every role without a
  PHP error, and refused to clients.
- **The CSRF sweep:** 7 forms (thanks, reward, decision, feedback, survey,
  answer, paying a cash reward).

### Results

Full isolated run: **2569 PASS, 0 FAIL** (browser step skipped). That is
127 checks more than P5-M04's 2442, all of them this module's.

Mutation: I let an anonymous survey show its results below its minimum.
The suite failed on "With one answer of a minimum of three, nothing is
shown: the counts only", after 2460 passes.

The three pages were also looked at in a browser on the test server: no
PHP error, and no horizontal scroll at phone width.

## Not done here

- Points expiring, a budget of points per manager, and approval chains for
  large rewards.
- Gift cards bought or sent by a provider: a reward is handed over by the
  agency and recorded as given.
- Award and survey results sent outside Crewvia: notifications stay inside
  it, as everywhere else.
- Survey questions beyond single choice: several choices, a matrix, a
  question shown only after another answer.
- Rewards for clients' people: clients take no part.

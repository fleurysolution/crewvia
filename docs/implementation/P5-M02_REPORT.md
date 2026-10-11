# P5-M02 — board, dependencies, subtasks, workload, capacity, optional sprints

## What existed, verified before building

| Area | State before |
|---|---|
| Tasks | P5-M01: tasks with assignee, priority, deadline, milestone, status and history; a list view; My tasks |
| Estimates, subtasks, dependencies, sprints | none |
| Capacity | none for tasks |
| Time off | Phase 2's `time_off_requests`: each worker's requests with dates and a status, approved or not |

## What was built

**Data** (migration `install/project-planning.sql` plus guarded columns in
`install/upgrade.php`; rollback `install/rollback/p5-m02.sql`):

- On `project_tasks`:
  - `parent_id`, a subtask of a task of the same project;
  - `estimate_hours`;
  - `sprint_id`.
- `project_task_dependencies`: a task waits for another task of the same
  project.
- `project_sprints`: name, goal, dates, and a status (planned, active,
  closed). At closing it records how many open tasks went back to the
  backlog.
- `user_capacity`: hours a week a person gives to tasks. No row means 40.

Time off is read from Phase 2's approved `time_off_requests`, not entered
a second time.

**Rules** (all in `pt_update()`, so the list, the board, My tasks and
Workload apply the same ones):

- **Subtasks:**
  - one level deep, under an open task of the same project;
  - a task with subtasks cannot itself become a subtask;
  - a parent cannot be done or cancelled while a subtask is open;
  - a new subtask takes its parent's milestone and sprint unless told
    otherwise.
- **Dependencies:**
  - same project only; a task cannot wait for itself or for the same task
    twice;
  - a loop is refused, directly or through other tasks;
  - a task cannot start or finish while anything it waits for is open.
    Being marked blocked is still allowed.
  - Only the project's managers add or remove one.
- **Estimates:** more than 0 and at most 999 hours.
- **Sprints:**
  - at most 90 days, ending on or after they start;
  - one active at a time per project;
  - closing returns its open tasks to the backlog, with a line in each
    task's history, and records how many went back;
  - a closed sprint takes no more tasks.
- **History:** every new field change is a line with the old and new
  value, e.g. "Estimate: 2 h → 3 h", "Sprint: Sprint 1 → –", "Waits for:
  – → Order the scaffold".
- **Progress counts the work itself.** A task with live subtasks is
  counted through them, not as one more task beside them. That applies to
  the project, its milestones, its sprints, the projects list and the
  workload. P5-M01's figures are unchanged where there are no subtasks.

**Screens:**

- **Project board:**
  - To do, In progress, Blocked and Done columns;
  - each card shows its priority, assignee, estimate and deadline, its
    subtasks done, and whether it waits for another task;
  - the moves are buttons (Start, Mark blocked with the reason, Done,
    Reopen) that work without JavaScript, offered to the task's assignee
    and to the project's managers;
  - filters by sprint (all, backlog, a sprint), person and milestone;
  - the sprints table, with tasks and hours done, and plan, start and close
    actions.

  The board's counts take three queries for the whole project, not one per
  card.
- **Project tasks:**
  - estimate, parent and sprint on create and edit;
  - on a task: its subtasks, what it waits for (managers add and remove
    these), and what waits for it.
- **Workload:**
  - per person, the open hours of their tasks in each of the next four
    weeks, against their capacity that week less approved time off;
  - an overdue task counts in this week; tasks dated later, undated or
    without an estimate are shown apart;
  - anyone over capacity is flagged, with a suggestion: their biggest
    estimated task that week, to the person on the same project with the
    most room who could take it without going over;
  - "Reassign" applies it, as a recorded change with the reason;
  - administrators and recruiters set each person's hours a week.

  The desks see every project; a supervisor sees the projects they
  supervise a crew on and cannot set capacities.
- French and Spanish. In Spanish "Block" is *Bloque*, the noun (a block of
  rooms), so the board says "Mark blocked" instead.

## Tests

`tests/project_planning_db.php` (5 checks):

- rollback, which keeps every task, and upgrade;
- silence on a repeat;
- the fixture: two projects; two recruiters, payroll, a supervisor with a
  crew; a worker on the crew with two approved days off next week and a
  refused request that week.

`tests/project_planning_http.py` (57 checks):

- **Subtasks:**
  - created under a task, and inheriting its milestone;
  - refused: a sub-subtask, a parent on another project, a task with
    subtasks becoming one;
  - the parent can be neither done nor cancelled while they are open;
  - the parent shows them;
  - progress counts the two subtasks, not their parent as a third.
- **Estimates:** created with one; 0, words and 1,000 refused; a change
  recorded with old and new values, "Estimate: 2 h → 3 h".
- **Dependencies:**
  - added and recorded;
  - refused: a task waiting for itself, the same one twice, a loop, a task
    of another project, payroll adding one;
  - the worker can neither start nor finish while the task waited for is
    open, then starts once it is done;
  - removal recorded.
- **The board:**
  - four columns with their counts; a waiting card and a parent card say
    so;
  - a move is a recorded status change; blocking needs the reason and
    shows it;
  - someone neither managing nor assigned cannot move a card;
  - a worker does not open the board.
- **Sprints:**
  - refused: dates in the wrong order, more than 90 days;
  - two planned, one started, the second refused while the first is
    active;
  - tasks put into it, recorded; the board by sprint, and the backlog;
  - closing returns the open task to the backlog, recorded with why;
  - the closed sprint keeps "1 done, 1 back to the backlog";
  - a closed sprint takes no task.
- **Workload:**
  - capacity over 80 refused; a recruiter sets 20 h;
  - next week the worker has 36 h planned (6 + 30) against 24 h. That is
    40 less two approved days off; the refused request counts for nothing;
  - the recruiter has 3 h against 20 h;
  - the worker is flagged; the suggestion moves their biggest task to
    someone on the project with room;
  - "Reassign" applies it with the reason, and the flag clears;
  - a recruiter sees every project; a supervisor sees their own and cannot
    set capacities; payroll is refused.
- **Translations:** French and Spanish.

Also:

- the access matrix: 20 checks on the two new pages. Workers and clients
  are refused both; payroll and hotels are refused Workload;
- the CSRF sweep: 6 new forms (board move, sprint save, sprint close,
  capacity, reassign, dependency).

P5-M01's 61 checks pass unchanged on the extended code.

### Results

Full isolated run: **2318 PASS, 0 FAIL** (browser step skipped). That is
62 checks more than GAP-1002's 2256, all of them this module's.

Mutation: I let a task start whatever it waits for. The suite failed on
"The worker cannot start a task while what it waits for is open", after
2191 passes.

## Not done here

- Dragging cards with the mouse. The moves are buttons, which also work
  from a keyboard and a phone; dragging can be added on top later.
- Time off for staff accounts: Phase 2 records time off only for crew, so
  a staff member's capacity is their weekly hours, unless set lower.
- Weekends and holidays inside a sprint, story points, burndown charts.

# P5-M01 — project tasks, milestones, task history and project progress

## What existed, verified before building

| Area | State before |
|---|---|
| Projects | `jobs`: client, site, dates, status, the order lines (`job_order_lines`), departments, crews (`placements`) and their supervisors (`assignment_details`) |
| Tasks | none. The only "tasks" were the fixed checklists of onboarding and offboarding (`onboarding_tasks`, `offboarding_tasks`), tied to one person's hiring or leaving, not to the work of a project |
| Milestones, project progress | none |

## What was built

**Data** (migration `install/project-tasks.sql`, rollback
`install/rollback/p5-m01.sql`):

- `project_milestones`: a project's milestones, with title, description,
  due date, and cancellation.
- `project_tasks`:
  - title, description, priority (low, normal, high, urgent) and deadline;
  - assignee, and optionally one of the same project's milestones;
  - status: to do, in progress, blocked (with what it is waiting for), done
    (with when), cancelled.
- `project_task_events`: the task's history, one row per change with the
  old and new value, who and when, plus notes. The application only ever
  adds to it.

Nothing is keyed twice. A task points at the project, the milestone and
the person that already exist, and a worker is reached through the worker
account and the placement that already exist.

**Who does what** (`app/project-tasks.php`):

| | Sees the tasks | Creates, changes, cancels | Moves their own task, writes notes |
|---|---|---|---|
| Administrator, recruiter | every project | yes | yes |
| Supervisor | projects they supervise a crew on | on those projects | yes |
| Payroll, hotels | yes | no | yes |
| Worker | only their own, under My tasks | no | yes, but cannot cancel or reopen a cancelled task |
| Client | no | no | no |

- **Who a task can go to:** an active member of staff, or a worker placed
  on that same project. Not a worker on another project, and not an
  account that is switched off.
- **The milestone** must be the project's own and not cancelled.
- **Blocked** needs what the task is waiting for. **Done** records when
  it was finished, and reopening clears it.
- **Locking:** every change takes a row lock on the task. Two people saving
  at once are applied one after the other, each recorded.
- **Notifications** are internal only. The assignee is told of an
  assignment; whoever created the task is told when it is blocked or done.
  Nothing leaves Crewvia.
- **Audit log:** every creation, change and milestone action is recorded,
  with the old and new values.

**Progress:**

- **A project:** done tasks over live tasks (cancelled ones left out), the
  open, blocked and overdue tasks, and the milestones reached.
- **A milestone:** reached when it has live tasks and all are done; overdue
  when its date has passed and it is not reached.
- The **projects list** shows each project's progress and overdue tasks. It
  takes one query for the whole list, not one per project (the lesson of
  P4-M07).

**Screens:**

- **Project tasks** (the working project):
  - progress bar and figures;
  - milestones with their state;
  - the task list, with filters: open, all or one status; person;
    milestone; priority; overdue only;
  - one task's details, its change form and its history in words, e.g.
    "Priority: Low → Urgent";
  - the forms to create, for those who manage.
- **My tasks:** what is assigned to me on every project, open first, each
  with its status form and its history. Workers reach it from their portal
  menu; it is the only task screen open to them.
- French and Spanish throughout. Three existing translations meant
  something else here, so the task screens have their own wording: "Title"
  is *Puesto* (a job title) in Spanish, "Reached" is *Contactado* (a
  candidate reached by phone), and "Open" is a verb.

## Tests

`tests/project_tasks_db.php` (6 checks):

- rollback, which keeps every project, and upgrade;
- silence on a repeat;
- the database refusing a status that does not exist;
- the fixture: two projects; desks; a supervisor with a crew on the first
  and one with none; a worker placed on each project; a milestone on the
  second; an account switched off.

`tests/project_tasks_http.py` (55 checks):

- **Milestones:** set by a recruiter; one without a title, or with a date
  that is not a date, is refused.
- **Creating tasks:**
  - with assignee, priority, deadline and milestone;
  - given to a worker on the project, who is told;
  - the history starts with "created" and "assigned";
  - refused: a worker on another project, an account switched off, a
    milestone of another project, no title, an unknown priority; nothing
    is created by a refusal;
  - payroll sees the tasks, without the forms, and cannot create;
  - the supervisor of the crew opens and creates on their project; a
    supervisor with no crew sees no project and no task.
- **Changing tasks:**
  - the person assigned moves their task along, but cannot change its
    other details or cancel it;
  - nobody else can move it unless they manage the project;
  - "nothing changed" is refused and not recorded;
  - a manager changes six details in one save, each its own history line
    with the old and new value;
  - a wrong date is refused;
  - the history reads in words.
- **A worker, from My tasks:**
  - sees their task with its project and milestone, and only their own;
  - does not open the project's task list;
  - "blocked" needs a reason; the creator is told it is blocked;
  - cannot cancel, change the deadline, or touch another task;
  - "done" records the time and clears the blocked reason; reopening
    clears the time;
  - reads the history.
- **Progress and milestones:**
  - 1 of 4 live tasks (25%), the cancelled one left out, 1 overdue;
  - the overdue task is marked; the open list leaves done and cancelled
    tasks out;
  - the filters: overdue only, by person and status;
  - milestone 1 under way, then reached once its tasks are done; milestone
    2 overdue;
  - a milestone with open tasks cannot be cancelled.
- **The projects list:** "2 of 4 done (50%)" and "1 overdue", and nothing
  for a project without tasks.
- **The audit log:** every creation and change of the test's tasks, with
  old and new values, and the milestones set.
- **Translations:** French and Spanish.

Also:

- the access matrix: 17 checks on the two new pages. Workers and clients
  are refused Project tasks, clients are refused My tasks, and no page
  shows a PHP error for any role;
- the CSRF sweep: `create`, `update` and `milestone_save` on Project tasks,
  and `update` on My tasks.

### Results

Full isolated run: **2218 PASS, 0 FAIL** (browser step skipped). That is
61 checks more than P4-M07's 2157, all of them this module's.

Mutation: I let anyone be given a task, removing the rule that limits it
to staff and to the crew of that project. The suite failed on "A worker
placed on another project cannot be given the task", after 2108 passes.

## Not done here (P5-M02 and later)

- Kanban, dependencies, subtasks, workload, capacity and sprints:
  P5-M02. Tasks here have no estimate, so progress counts tasks, not
  hours.
- Client visibility of tasks: clients see none.
- Email or SMS about tasks: none. Outbound messaging stays switched off.

# P5-M59 — A project manager sees the projects they manage

The owner, 16 Oct 2026, prepares a demonstration with one account per role: owner, project manager,
supervisor, recruiter. A project manager (Job setup, "Project manager", e.g. Manager Number 1 on
Plant 1) was only told of new client orders and requisitions. Signed in on the Supervisor desk - the
desk the plants' managers have - they saw an empty "My team" (they carry no crew themselves) and the
project tasks, board and workload of no project, those opening to the supervisors of a crew.

## What changes

- `supervised_job_ids()` counts the projects a person manages, as it counted those where they
  supervise a crew or hold the budget: the project tasks, the board, the workload, purchasing and the
  working-project picker open on their plants.
- **My team** (`app/project-manager.php`, `pm_projects()`): for each project they manage, a section -
  on site, hired and not started yet, without a supervisor (amber), the applicants on the open
  requisitions and their openings, the supervisors with their crews and how many are on site - with the
  project board, tasks and workload one click away. Their own crew, if any, follows as before. A
  supervisor who manages no project sees the page as before.

No table changes, so no migration and no rollback.

## Tests

`tests/project_manager_http.py` (9), with the account created by `tests/menu_crawl_db.php`: named in
Job setup by the administrator; My team opens on the project though they carry no crew; the four
figures; the supervisors and their crews; the project tasks open with the form to add one; the task
they add is on the project board, and the workload counts it against them; a supervisor who manages
no project sees no such section.

The first bench run failed: `starting`, a reserved word in MariaDB, was used as a column alias, and
every supervisor who manages a project met an error on My team (the menu crawl saw it too, through
System health, which lists the errors). The alias is `not_started`.

`tests/demo_cycle_http.py`: Manager Number 1, project manager of a plant in the demonstration, opens "My projects and team".

Mutation: the managed projects left out of `supervised_job_ids()` (caught: "The project tasks open on
their project, and they can add a task"). A first version of the test only checked that the pages
answered 200 and did not catch it; it now checks what the pages hold.

Full suite (M58 and M59 together): 3790 PASS, 0 FAIL.

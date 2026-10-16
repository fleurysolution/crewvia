# P5-M55 — An onboarding requirement asks the new hires, not the crew already on site

15 Oct 2026, at the owner's request, seven standard onboarding requirements were added to Plants 1,
2 and 3 (Form I-9, Form W-4, direct deposit, background check and drug test authorization,
emergency contact, site safety orientation, travel details), so that a new hire (Leo Noonan) has
something to do after the countersignature. Measured right after: every requirement had become a
task for every assignment of the project, the crew already on site included - about 6,400 tasks
"waiting for the worker" for some 900 employees whose paperwork was done before Crewvia. Plant 1 also
got a second "Travel details" (requirement 21) and Plant 3 none: two scripts of mine ran in the same
session and switched its working project under each other.

## What changes

- **Who a requirement asks** (`app/onboarding-tasks.php`): the new hires - assignments not started
  yet (offered, confirmed, travelling). The crew on site only when "Also ask the crew already on
  site" is ticked (when adding it, or later in its instructions; unticked again, their untouched
  tasks go, what they answered stays). Every place that created tasks (the onboarding page, the
  portal, the countersignature, the contract signature, the agency service templates) goes through
  `onboarding_sync()`.
- **The same title twice on a project is refused.**
- **"Remove this requirement"**, with a confirmation, on a requirement nobody has answered or had
  reviewed; its tasks go with it. Once somebody answered it, it stays (its instructions can change).

## Data

`onboarding_requirements.for_crew` (`install/upgrade.php`, reversed by `install/rollback/p5-m55.sql`).
The upgrade removes, once, the tasks of the crew on site (and of finished or cancelled assignments)
that nobody touched: pending, no answer, never reviewed. The new hires' tasks and anything answered
stay. It prints how many it removed.

After deploying: remove the duplicate "Travel details" on Plant 1 and add "Travel details" on Plant 3.

## Tests

`tests/onboarding_scope_http.py` (7): a requirement becomes a task for the new hire, not for the crew
on site; the same title twice refused; "Also ask the crew" gives it to them, unticked their untouched
tasks go and the new hire keeps theirs; "Remove" offered while nobody answered, removed with its
tasks; once answered, not removable.

`tests/onboarding_scope_db.php` (5): the rollback drops the column; the upgrade brings it back and says
how many untouched crew tasks it removed; the crew's untouched task gone, their answered one kept;
the new hires keep their tasks; a second upgrade is silent.

Updated: `tests/home_db.php`, whose worker is on site with an onboarding task: its requirement is now
one meant for the crew on site too (the first full run failed there, "puts their onboarding task first").

Seen in the browser on the test server: the box under each requirement and "Remove" only where
nobody answered.

Mutation: the scope widened back to every assignment (caught: "A requirement becomes a task for the
new hire, not for the crew already on site").

Full suite: 3755 PASS, 0 FAIL (3743 before, plus the 12 new checks).

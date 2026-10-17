# P5-M84 — from the manager to HR: approval hands the applicant over; HR sets the group and supervisor

RSS, 17 Oct 2026, the flow as they run it:

> The recruiter funnels it down, goes to the manager, the manager goes to
> HR, and HR does the onboarding. During onboarding the coordinator says
> this person is a supervisor, or reports to X, in group one.

## The flow

1. **Recruiter**: calls, then **Send for review** (P5-M83) to a manager or
   the owner.
2. **Manager or owner**: decides on Waiting on your decision.
3. **On approval**, the applicant goes to HR, automatically:
   - the project's **HR / project coordinator** gets a high-priority task,
     due in 2 days, with a notification: "Onboard :name: paperwork, drug
     test, group and supervisor";
   - the person gets the **portal invitation** to fill in the W-4, I-9 and
     direct deposit. They sign on site with HR's code, as before;
   - the person moves to Offered, out of the recruiter's lists;
   - the recruiter is told: "approved and handed to HR. Nothing more for
     you". Their page says "Approved by … and handed to HR for onboarding".
4. **HR** sends the drug test and background check details if the job needs
   them, then sets the person's **group and supervisor** on the placement:
   - a group name, picked from the project's groups or a new one;
   - the supervisor, who is notified;
   - "Leads this group": the person is the group's lead, and Manning then
     asks for a vehicle for them.

**The coordinator** is chosen on the plant ("HR / project coordinator").
Without one, the task goes to whoever on the recruiting desk has the fewest
open tasks.

**Manning** shows each person's group, and who leads it.

Data: `jobs.coordinator_id`, `assignment_details.crew_group` and
`leads_group`. Reversed by `install/rollback/p5-m84.sql`.

## Tests

`tests/client_contacts_http.py` covers:
- approval: the coordinator's task;
- the recruiter's notice;
- the person leaving the review list;
- the page saying "handed to HR";
- the group set with its lead;
- Manning showing the group and asking a vehicle for the lead.

## The two teams: HR and Project coordinator

"The onboarding I would call HR. Hotel and everything else is more like
project coordinator."

- **A new role: HR (onboarding).**
  - It works the onboarding screens of the recruiting desk.
  - Its own menu: Home, Activity, Onboarding, Tax forms, Forms register,
    Candidates, Contracts, Manning, My tasks, Approvals.
  - It receives the approved applicants when the project has no named HR
    person.
  - It gets the stage task "Check the tax forms, the direct deposit and ADP"
    (payroll when there is no HR yet).
- **"Hotels & Travel" is now shown as Project coordinator.** The role is
  the same, only its name changes.
- **To do on Team:** move the onboarding team to HR (onboarding).
- The plant's coordinator field is now **HR (onboarding)**.

Upgrade: `users.role` gains `hr`. The rollback moves HR back to Recruiting.

Tests:
- an HR account opens Onboarding, and its menu starts with its day;
- Team offers both role names.

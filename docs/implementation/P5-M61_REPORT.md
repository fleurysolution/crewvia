# P5-M61 — Giving somebody a supervisor says so, and tells the supervisor

Found on 16 Oct 2026, giving Leo Noonan his supervisor in production (Organization, Assign): the save
showed no message at all; without a trade it saved nothing and said nothing; and the supervisor who
gained a person was not told.

## What changes

`app/pages/operations.php`, "assignment" (from the organization chart or Operations):
- the save says what was recorded: ":name: :trade, with :supervisor." ("They are told." when the
  supervisor changed), or ":name: :trade, without a supervisor yet.";
- without a trade it is refused with "Enter the trade this person works on the project.", back to the
  same form;
- the supervisor who gains the person is told in their notifications: ":name joins your crew on
  :project (:trade, :shift), from :date." (link to My team).

No table changes, so no migration and no rollback.

## Tests

`tests/organization_http.py` gains 2 (with a `notes` command in `tests/organization_probe.php`): the
save says so and the supervisor is told; without a trade nothing is saved and it says why.

Mutation: the notice to the supervisor left out (caught: "The save says so, and the supervisor who
gains the person is told").

Full suite: 3792 PASS, 0 FAIL (3790 before, plus the 2 new checks).

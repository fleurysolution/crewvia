# Crewvia — database migration plan

## How a change reaches a database

There is one mechanism, and it stays: `install/upgrade.php`.

- **Guarded.** Every step first checks `information_schema` for the table
  or column, or uses `CREATE TABLE IF NOT EXISTS`.
- **Idempotent.** A second run changes nothing and prints nothing for that
  step.
- **Loud.** It prints one line for each thing it actually changed.
- **Additive.** It never drops a column or table holding data. It never
  narrows an ENUM (S8); an ENUM is widened before the new value is offered.
- **Backfills say what they did.** They count, print, and only fill where
  the answer is unambiguous. A row with no unambiguous answer is left NULL,
  not guessed.

A new module's DDL goes in its own SQL file under `install/`, and
`upgrade.php` applies it. That is how `hr-modules.sql` and
`hr-modules-2.sql` work today.

## Reversibility

Each module ships `install/rollback/<module>.sql`. It reverses that module's
schema and nothing else:

- It drops the tables the module created.
- It drops the columns the module added.

It is never run by `upgrade.php`. The owner runs it by hand, after a backup.

The test runner proves each rollback on the isolated database in four steps:

1. `upgrade.php` (up)
2. the rollback script (down)
3. `upgrade.php` again (up)
4. the module's tests

**A rollback loses the data written into the module's own tables.** That is
stated at the top of every rollback file. Data that existed before the
module is never touched by its rollback. In particular, the current-value
columns on existing tables keep their values.

## Before any foreign key is added to an existing table

Run `php install/verify-relationships.php` on the target database. It is
read-only and reports orphans.

A key is proposed only for a relationship that reports 0 orphans **and**
whose parent rows the application never deletes. Phase 1 adds keys only on
new tables.

## Deployment order (unchanged)

1. Build an archive from a **local** commit, never from `origin/main`
   (SEC-0).
2. Back up the live files and the database.
3. Check the sha256.
4. Extract the archive.
5. Run `php install/upgrade.php` and read every line it prints.
6. Run `php install/upgrade.php` a second time. It must be silent for the
   module's steps.
7. Run `php install/verify-relationships.php`.

---

## P1-M01

**New file:** `install/hr-relationships.sql`

| Object | Change |
|---|---|
| `employee_classifications` | **New table.** `id`, `candidate_id` (FK → candidates), `employment_type` ENUM('hourly','salaried','contractor','external'), `flsa_status` ENUM('non_exempt','exempt','not_applicable','not_determined'), `effective_from` DATE NULL (NULL only on the initial row: "held before history was kept"), `reason` VARCHAR(500), `recorded_by` (FK → users, NULL for the initial row), `recorded_at`. |
| `placement_rate_changes` | **New table.** `id`, `placement_id` (FK → placements), the old and new value of each of `pay_rate`, `bill_rate`, `per_diem_rate` and `guarantee_hours`, `changed_by` (FK → users), `changed_at`. |
| `placements.vacancy_id` | **New column**, INT UNSIGNED NULL, indexed. |
| `placements.order_line_id` | **New column**, INT UNSIGNED NULL, indexed. |
| `employee_profiles.flsa_status` | **New column**, same ENUM as above, default `not_determined`. It is the current-value companion of `employment_type`. |

**Backfill (once, in `upgrade.php`):**

- **Classifications.** One initial row per existing `employee_profiles` row,
  mapped as follows:

  | `employment_type` | `flsa_status` |
  |---|---|
  | hourly | non_exempt |
  | contractor, external | not_applicable |
  | salaried | not_determined, because the law decides this from duties and salary, and the database knows neither |

  `employee_profiles.flsa_status` takes the same value.
- **Placement links.** Where the candidate has applications on **exactly
  one** requisition of the placement's project, that requisition and its
  line are written. Every other placement stays NULL, and the number left
  is printed.

**Rollback:** `install/rollback/p1-m01.sql`

- Drops the two new tables.
- Drops the three new columns.
- `employee_profiles.employment_type` is untouched. It is still the value
  every existing screen reads.

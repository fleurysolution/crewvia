-- The trades this agency staffs.
--
-- These were six values frozen into an ENUM in three different tables. A
-- client asking for welders, riggers or boilermakers could not be answered
-- without a developer and a schema change, which is not a reasonable thing
-- to need in the middle of a strike.
--
-- The list is data now. The columns that refer to it are plain text, so
-- adding a trade is one row and nothing has to be altered.
--
-- A trade is retired rather than deleted: records already filed under it
-- must keep reading correctly, and a name that disappears from the
-- catalogue takes every past requisition's meaning with it.

CREATE TABLE IF NOT EXISTS disciplines (
  slug       VARCHAR(40) NOT NULL PRIMARY KEY,
  label      VARCHAR(90) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_discipline_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO disciplines (slug, label, sort_order) VALUES
  ('mechanical',      'Mechanical',      1),
  ('chemical',        'Chemical',        2),
  ('electrical',      'Electrical',      3),
  ('instrumentation', 'Instrumentation', 4),
  ('operator',        'Operator',        5),
  ('other',           'Other',          99);

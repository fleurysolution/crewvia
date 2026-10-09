-- The do-not-use register, and what people can actually do.
--
-- Two things the agency keeps in spreadsheets and in people's heads.
--
-- The first is who must never be called again. Somebody is fired on a
-- Tuesday, and three weeks later a different recruiter calls them for the
-- next job, because the only record was a red line on one person's
-- spreadsheet. The register is a column on the person, with the reason,
-- who set it and when, so the whole desk sees the same answer.
--
-- The second is what somebody actually does. "Discipline" answers which
-- department they belong to; it does not answer whether they can weld.
-- A person has several skills, they are searchable, and the list of
-- skills is data so a new trade needs no schema change.

CREATE TABLE IF NOT EXISTS skills (
  slug       VARCHAR(40) NOT NULL PRIMARY KEY,
  label      VARCHAR(90) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_skill_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- What RSS actually staffs, in their own words.
INSERT IGNORE INTO skills (slug, label, sort_order) VALUES
  ('welder',             'Welder',                     10),
  ('tank_welder',        'Tank welder',                11),
  ('pipefitter',         'Pipefitter',                 12),
  ('millwright',         'Millwright',                 13),
  ('machinist',          'Machinist',                  14),
  ('cnc_operator',       'CNC operator',               15),
  ('machine_operator',   'Machine operator',           16),
  ('maintenance',        'Maintenance',                17),
  ('electrician',        'Electrician',                18),
  ('instrument_tech',    'Instrument technician',      19),
  ('boilermaker',        'Boilermaker',                20),
  ('rigger',             'Rigger',                     21),
  ('painter',            'Painter',                    22),
  ('assembly',           'Assembly',                   23),
  ('heavy_equipment',    'Heavy equipment operator',   24),
  ('forklift',           'Forklift operator',          25),
  ('cdl_driver',         'CDL driver',                 26),
  ('order_selector',     'Order selector',             27),
  ('general_labour',     'General labour',             28);

CREATE TABLE IF NOT EXISTS candidate_skills (
  candidate_id INT UNSIGNED NOT NULL,
  skill_slug   VARCHAR(40) NOT NULL,
  years        TINYINT UNSIGNED NULL,
  -- Said by them on an application, or confirmed by somebody here. A
  -- recruiter needs to know which before relying on it.
  confirmed    TINYINT(1) NOT NULL DEFAULT 0,
  added_by     INT UNSIGNED NULL,
  added_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (candidate_id, skill_slug),
  KEY ix_skill_people (skill_slug, confirmed),
  FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

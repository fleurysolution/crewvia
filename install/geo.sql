-- T8 (P5-M12): locations. The US Census Bureau's 2023 Gazetteer, loaded
-- from install/gazetteer by install/upgrade.php; a ZIP code on candidates and
-- project sites; each candidate's coordinates and what they came from.
-- Reversed by install/rollback/p5-m12.sql.

CREATE TABLE IF NOT EXISTS geo_places (
  state     CHAR(2)      NOT NULL,
  place_key VARCHAR(120) NOT NULL,
  lat       DECIMAL(8,5) NOT NULL,
  lng       DECIMAL(8,5) NOT NULL,
  PRIMARY KEY (state, place_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS geo_zips (
  zip CHAR(5)      NOT NULL PRIMARY KEY,
  lat DECIMAL(8,5) NOT NULL,
  lng DECIMAL(8,5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

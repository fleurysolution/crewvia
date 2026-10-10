-- Assets: categories, a lifecycle, condition, repairs, inspections, and the
-- history of every item. Built on the equipment and equipment_issues
-- tables that already hold what the agency issues to its crews.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/assets.sql.
-- The columns added to equipment and equipment_issues are added by
-- upgrade.php. No comment line in this file may end in a semicolon:
-- upgrade.php splits statements there.

-- What kind of item it is, and how often it must be inspected. A harness
-- or a gas detector that is past its inspection is not issued.
CREATE TABLE IF NOT EXISTS asset_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  inspection_days SMALLINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_asset_category (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Everything that happened to an item, in order, and who did it.
CREATE TABLE IF NOT EXISTS asset_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  detail VARCHAR(500) NULL,
  cost DECIMAL(10,2) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_asset_event (equipment_id, id),
  FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

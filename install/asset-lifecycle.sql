-- P5-M03: the equipment module's inspections, maintenance and service as
-- records, and the item's lifecycle and value.
--
-- Built on Phase 2's equipment, asset_categories and asset_events. The
-- columns added to those (classification and valuation defaults on a
-- category; in-service, retirement, disposal and valuation on an item; the
-- disposed status) are added by upgrade.php, guarded.
--
-- Applied by install/upgrade.php. Reversed by install/rollback/p5-m03.sql.
-- No comment line in this file may end in a semicolon: upgrade.php splits
-- statements there.

-- Every inspection, passed or failed, with what was found and who did it.
CREATE TABLE IF NOT EXISTS asset_inspections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  inspected_on DATE NOT NULL,
  result ENUM('pass','fail') NOT NULL,
  findings VARCHAR(1000) NULL,
  next_due DATE NULL,
  inspected_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_inspection_item (equipment_id, inspected_on),
  FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  FOREIGN KEY (inspected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Recurring maintenance on an item: what, every how many days, when next.
-- One that blocks issue keeps the item from going out once overdue
-- (calibrating a gas detector, servicing a lift).
CREATE TABLE IF NOT EXISTS asset_maintenance_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  task VARCHAR(190) NOT NULL,
  every_days SMALLINT UNSIGNED NOT NULL,
  next_due DATE NOT NULL,
  blocks_issue TINYINT(1) NOT NULL DEFAULT 0,
  last_done_on DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_plan_due (is_active, next_due),
  KEY ix_plan_item (equipment_id),
  FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The service history: repairs, maintenance, calibrations, by whom, at
-- what cost and for how long the item was out of use. vendor_id names a
-- row of Phase 3's vendors (checked when recorded) without a foreign key:
-- vendors are never deleted, and a key here would stop P3-M06's rollback.
CREATE TABLE IF NOT EXISTS asset_service_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  equipment_id INT UNSIGNED NOT NULL,
  kind ENUM('repair','maintenance','calibration') NOT NULL,
  performed_on DATE NOT NULL,
  vendor_id INT UNSIGNED NULL,
  performed_by VARCHAR(190) NULL,
  cost DECIMAL(10,2) NULL,
  downtime_days SMALLINT UNSIGNED NULL,
  reference VARCHAR(120) NULL,
  notes VARCHAR(1000) NULL,
  plan_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_service_item (equipment_id, performed_on),
  FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  FOREIGN KEY (plan_id) REFERENCES asset_maintenance_plans(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rollback for P5-M15 (T20: weekly hotel folio reconciliation).
--
-- READ BEFORE RUNNING
--   * Take a database backup first (php install/backup.php).
--   * Deploy the code that precedes P5-M15 first.
--   * Lost by this rollback: every folio entered, its lines, notes and
--     incidental charges. Bills it created in Payables are KEPT (they are
--     ordinary vendor bills); only their link to the folio goes.
--
-- Run:  mysql <database> < install/rollback/p5-m15.sql

DROP TABLE IF EXISTS hotel_folio_lines;
DROP TABLE IF EXISTS hotel_folios;

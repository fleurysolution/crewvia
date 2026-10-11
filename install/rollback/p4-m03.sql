-- Rollback for P4-M03 (report builder, export policy, schedules, delivery).
--
-- READ BEFORE RUNNING
--   * Take a database backup first.
--   * Deploy the code that precedes P4-M03 first, and remove the cron line
--     that runs install/run-report-schedules.php, if one was added.
--   * Lost by this rollback: every saved report, every schedule, the
--     export policy, the
--     deliveries not yet downloaded, and the log of exported files. The
--     activity log keeps its own record of each export.
--
-- Run:  mysql <database> < install/rollback/p4-m03.sql

DROP TABLE IF EXISTS report_exports;
DROP TABLE IF EXISTS report_delivery_recipients;
DROP TABLE IF EXISTS report_deliveries;
DROP TABLE IF EXISTS report_schedules;
DROP TABLE IF EXISTS report_export_policy;
DROP TABLE IF EXISTS report_definitions;

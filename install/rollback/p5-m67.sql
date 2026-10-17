-- Rollback for P5-M67 (clocking in and out on the site).
-- The clock-outs and positions recorded are lost; the clock-ins stay.
ALTER TABLE clock_ins DROP COLUMN in_method, DROP COLUMN in_lat, DROP COLUMN in_lng, DROP COLUMN in_accuracy_m, DROP COLUMN in_distance_m, DROP COLUMN in_flag,
  DROP COLUMN qr_supervisor_id, DROP COLUMN clocked_out_at, DROP COLUMN out_method, DROP COLUMN out_lat, DROP COLUMN out_lng, DROP COLUMN out_accuracy_m,
  DROP COLUMN out_distance_m, DROP COLUMN out_flag, DROP COLUMN out_by;
ALTER TABLE jobs DROP COLUMN site_lat, DROP COLUMN site_lng, DROP COLUMN site_radius_m;

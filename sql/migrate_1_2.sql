-- =====================================================================
-- Smart Bin 1.2 - upgrade a 1.1 database (safe to run twice).
-- Adds demo bins: example bins (not real) that show the dashboard's
-- features. They are created from Settings > Demo bins.
--
-- Import in phpMyAdmin (Import tab) or:
--   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\migrate_1_2.sql
-- (A fresh install with schema.sql already has these.)
-- =====================================================================
USE smart_bin;

-- 1 = demo bin (fed by lib/demo.php, never sends email / WhatsApp)
ALTER TABLE bins
  ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER active;

-- Small key/value settings of the app itself (demo on/off, demo speed, ...)
CREATE TABLE IF NOT EXISTS app_settings (
  k  VARCHAR(40)  NOT NULL PRIMARY KEY,
  v  VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

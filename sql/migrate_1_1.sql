-- =====================================================================
-- Smart Bin 1.1 - upgrade an existing 1.0 database (safe to run twice).
-- Adds "test mode": the Simulator page can make the REAL bin act as if
-- it saw a fire or a gas leak, so the buzzer / fan work in the circuit.
--
-- Import in phpMyAdmin (Import tab) or:
--   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\migrate_1_1.sql
-- (A fresh install with schema.sql already has these columns.)
-- =====================================================================
USE smart_bin;

-- The test the operator started for a bin (NULL = no test running)
ALTER TABLE bins
  ADD COLUMN IF NOT EXISTS test_mode  ENUM('FIRE','VAPOUR') NULL AFTER full_alerted,
  ADD COLUMN IF NOT EXISTS test_until DATETIME    NULL AFTER test_mode,
  ADD COLUMN IF NOT EXISTS test_by    VARCHAR(50) NULL AFTER test_until;

-- Readings and events caused by a test are marked, so they never count as real
ALTER TABLE readings
  ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER fw;

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER acknowledged_at;

-- Rename the prototype bin
UPDATE bins SET name = 'Prototype bin' WHERE bin_id = 'BIN-01' AND name = 'Prototype pedal bin';

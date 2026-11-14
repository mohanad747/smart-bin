-- =====================================================================
-- Smart Bin - database schema
-- One table per data store in our level-1 DFD:
--   D1 bins, D2 readings, D3 events, D4 settings, D5 users
-- plus alert_log (every email / WhatsApp attempt and its result).
--
-- Import in phpMyAdmin (Import tab) or:
--   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\schema.sql
-- Safe to import again: it never drops a table.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS smart_bin
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_bin;

-- ---------------------------------------------------------------------
-- D1 Bins: one row per physical bin. Adding a bin = adding a row.
-- The "last_*" columns keep the last GOOD value of each sensor, so the
-- dashboard still shows something when one reading has a null.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bins (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  bin_id                VARCHAR(20)  NOT NULL UNIQUE,          -- e.g. BIN-01
  name                  VARCHAR(80)  NOT NULL,
  location_text         VARCHAR(160) NOT NULL DEFAULT '',
  lat                   DECIMAL(9,6) NULL,
  lng                   DECIMAL(9,6) NULL,
  device_token          VARCHAR(64)  NOT NULL,                 -- secret the ESP32 sends
  empty_depth_cm        DECIMAL(6,1) NOT NULL DEFAULT 40.0,    -- sensor to bottom of EMPTY bin
  fill_pct              DECIMAL(5,1) NULL,
  fill_rate_pct_per_hr  DECIMAL(7,2) NULL,
  predicted_full_at     DATETIME     NULL,
  prediction_status     ENUM('NO_DATA','FILLING','NOT_FILLING','FULL') NOT NULL DEFAULT 'NO_DATA',
  state                 ENUM('NORMAL','VAPOUR','FIRE') NOT NULL DEFAULT 'NORMAL',
  last_seen             DATETIME     NULL,
  active                TINYINT(1)   NOT NULL DEFAULT 1,
  is_demo               TINYINT(1)   NOT NULL DEFAULT 0,       -- demo bin: fake data, never alerts
  -- last good values for display
  last_distance_cm      DECIMAL(6,1) NULL,
  last_temp_c           DECIMAL(5,1) NULL,
  last_humidity         DECIMAL(5,1) NULL,
  last_gas_raw          SMALLINT     NULL,
  last_flame            TINYINT(1)   NULL,
  last_fan              TINYINT(1)   NULL,
  last_buzzer           TINYINT(1)   NULL,
  last_rssi             SMALLINT     NULL,
  last_uptime_s         INT UNSIGNED NULL,
  last_fw               VARCHAR(20)  NULL,
  full_alerted          TINYINT(1)   NOT NULL DEFAULT 0,       -- "bin full" message sent this cycle
  -- test mode (Simulator page): the real bin acts as if it saw a fire / gas
  test_mode             ENUM('FIRE','VAPOUR') NULL,              -- NULL = no test running
  test_until            DATETIME     NULL,
  test_by               VARCHAR(50)  NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- D2 Readings: every accepted reading, exactly as received, plus the
-- fill % worked out by the server and the server's own state check.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS readings (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  bin_id        VARCHAR(20)  NOT NULL,
  distance_cm   DECIMAL(6,1) NULL,
  fill_pct      DECIMAL(5,1) NULL,
  gas_raw       SMALLINT     NOT NULL,
  flame         TINYINT(1)   NOT NULL,
  temp_c        DECIMAL(5,1) NULL,
  humidity      DECIMAL(5,1) NULL,
  state         ENUM('NORMAL','VAPOUR','FIRE') NOT NULL,       -- decided by the ESP32
  server_state  ENUM('NORMAL','VAPOUR','FIRE') NOT NULL,       -- re-checked by the server
  fan           TINYINT(1)   NOT NULL,
  buzzer        TINYINT(1)   NOT NULL,
  uptime_s      INT UNSIGNED NULL,
  rssi          SMALLINT     NULL,
  fw            VARCHAR(20)  NULL,
  is_test       TINYINT(1)   NOT NULL DEFAULT 0,               -- state caused by a test command
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bin_time (bin_id, created_at),
  CONSTRAINT fk_readings_bin FOREIGN KEY (bin_id) REFERENCES bins(bin_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- D3 Events: hazard and collection events.
-- VAPOUR / FIRE / OFFLINE have a start and an end (ended_at NULL = still on).
-- EMPTIED is instant (started_at = ended_at).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  bin_id           VARCHAR(20)  NOT NULL,
  type             ENUM('VAPOUR','FIRE','OFFLINE','EMPTIED') NOT NULL,
  started_at       DATETIME     NOT NULL,
  ended_at         DATETIME     NULL,
  peak_temp_c      DECIMAL(5,1) NULL,
  peak_gas_raw     SMALLINT     NULL,
  note             VARCHAR(160) NULL,                          -- e.g. "Fill 86% -> 4%"
  alert_sent       TINYINT(1)   NOT NULL DEFAULT 0,
  acknowledged_by  VARCHAR(50)  NULL,
  acknowledged_at  DATETIME     NULL,
  is_test          TINYINT(1)   NOT NULL DEFAULT 0,            -- started by a test, not a real hazard
  INDEX idx_bin_open (bin_id, type, ended_at),
  INDEX idx_started (started_at),
  CONSTRAINT fk_events_bin FOREIGN KEY (bin_id) REFERENCES bins(bin_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- D4 Settings: thresholds.
-- The row with bin_id = 'DEFAULT' holds the global defaults.
-- A row for a real bin overrides only the columns that are NOT NULL.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  bin_id              VARCHAR(20) NOT NULL PRIMARY KEY,         -- 'DEFAULT' or a bin_id
  gas_raw             SMALLINT    NULL,   -- MQ-2 raw ADC value that means "vapour"
  temp_limit_c        DECIMAL(4,1) NULL,  -- above this = fire
  temp_rise_c         DECIMAL(4,1) NULL,  -- rise of this much ...
  temp_rise_window_s  SMALLINT    NULL,   -- ... within this many seconds = fire
  nearly_full_pct     TINYINT     NULL,   -- amber from here
  full_pct            TINYINT     NULL,   -- red from here
  upload_interval_s   SMALLINT    NULL,   -- normal upload interval of the ESP32
  updated_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- D5 Users: operator accounts. Passwords stored with password_hash().
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(80)  NOT NULL DEFAULT '',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login     DATETIME     NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- App settings (key/value): demo bins on/off, demo speed, last demo tick
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  k  VARCHAR(40)  NOT NULL PRIMARY KEY,
  v  VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Alert log: every attempt to send an email / WhatsApp, and the result.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  event_id    INT          NULL,                    -- NULL for test / "bin full" alerts
  bin_id      VARCHAR(20)  NULL,
  kind        ENUM('FIRE','FULL','TEST') NOT NULL,
  channel     ENUM('email','whatsapp')   NOT NULL,
  recipient   VARCHAR(120) NOT NULL,
  success     TINYINT(1)   NOT NULL,
  response    VARCHAR(500) NULL,                    -- error text or API reply
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

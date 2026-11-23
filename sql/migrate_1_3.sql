-- =====================================================================
-- Smart Bin 1.3 - upgrade a 1.2 database (safe to run twice).
-- Adds the automatic lid, the fire ball and commands from the dashboard.
--
-- Import in phpMyAdmin (Import tab) or:
--   C:\xampp\mysql\bin\mysql.exe -u root -p < sql\migrate_1_3.sql
-- (A fresh install with schema.sql already has these.)
-- =====================================================================
USE smart_bin;

-- What the bin reported last. NULL = older firmware without a lid / ball.
-- ("Full" uses the existing settings.full_pct, Settings > Thresholds.)
ALTER TABLE bins
  ADD COLUMN IF NOT EXISTS lid_state  ENUM('CLOSED','OPEN_HAND','OPEN_SERVICE','LOCKED_FULL','LOCKED_FIRE') NULL AFTER last_fw,
  ADD COLUMN IF NOT EXISTS ball_state ENUM('ARMED','RELEASED') NULL AFTER lid_state,
  ADD COLUMN IF NOT EXISTS hand_cm    DECIMAL(5,1) NULL AFTER ball_state,
  ADD COLUMN IF NOT EXISTS lid_opens  INT UNSIGNED NULL AFTER hand_cm,
  ADD COLUMN IF NOT EXISTS test_drop_ball TINYINT(1) NOT NULL DEFAULT 0 AFTER test_by;

ALTER TABLE readings
  ADD COLUMN IF NOT EXISTS lid_state  ENUM('CLOSED','OPEN_HAND','OPEN_SERVICE','LOCKED_FULL','LOCKED_FIRE') NULL AFTER is_test,
  ADD COLUMN IF NOT EXISTS ball_state ENUM('ARMED','RELEASED') NULL AFTER lid_state,
  ADD COLUMN IF NOT EXISTS lid_opened SMALLINT UNSIGNED NULL AFTER ball_state;

ALTER TABLE events
  MODIFY COLUMN type ENUM('VAPOUR','FIRE','OFFLINE','EMPTIED',
                          'LID_OPENED_SERVICE','LID_CLOSED_SERVICE','LOCKED_FULL',
                          'BALL_RELEASED','BALL_REARMED') NOT NULL;

-- Commands from the dashboard to a bin (api/bin_cmd.php -> api/device_poll.php)
CREATE TABLE IF NOT EXISTS bin_commands (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  bin_id      VARCHAR(20)  NOT NULL,
  name        ENUM('lid_open','lid_close','arm_ball') NOT NULL,
  status      ENUM('pending','sent','done','refused','expired') NOT NULL DEFAULT 'pending',
  created_by  VARCHAR(50)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME     NULL,
  done_at     DATETIME     NULL,
  INDEX idx_bin_status (bin_id, status, id),
  CONSTRAINT fk_commands_bin FOREIGN KEY (bin_id) REFERENCES bins(bin_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

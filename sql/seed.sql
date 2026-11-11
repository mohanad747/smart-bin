-- =====================================================================
-- Smart Bin - starting data
-- Import AFTER schema.sql. Running it again does not create duplicates.
--
-- Default login:  admin / SmartBin@2026   (change it on the Settings page)
-- BIN-01 token:   a random one is made on import. Copy it from
--                 Settings > Bins > BIN-01 into firmware/SmartBin/secrets.h
-- =====================================================================
USE smart_bin;

-- Global default thresholds (values from our first review report)
INSERT INTO settings (bin_id, gas_raw, temp_limit_c, temp_rise_c, temp_rise_window_s,
                      nearly_full_pct, full_pct, upload_interval_s)
VALUES ('DEFAULT', 1800, 60.0, 5.0, 60, 70, 90, 10)
ON DUPLICATE KEY UPDATE bin_id = bin_id;

-- The prototype bin at UTAS Muscat, South Al Khuwair
-- empty_depth_cm: measure from the ultrasonic sensor to the bottom of YOUR empty bin.
INSERT INTO bins (bin_id, name, location_text, lat, lng, device_token, empty_depth_cm)
VALUES ('BIN-01', 'Prototype bin',
        'UTAS Muscat campus, South Al Khuwair, Muscat',
        23.577815, 58.430020,
        SUBSTRING(SHA2(CONCAT(UUID(), RAND()), 256), 1, 32), 40.0)   -- random token
ON DUPLICATE KEY UPDATE bin_id = bin_id;

-- BIN-01 uses the defaults for now (NULL = use DEFAULT row)
INSERT INTO settings (bin_id) VALUES ('BIN-01')
ON DUPLICATE KEY UPDATE bin_id = bin_id;

-- Default operator account (password: SmartBin@2026)
INSERT INTO users (username, password_hash, full_name)
VALUES ('admin', '$2y$10$Kc.5CEs6tenw3SXBDQiheeAQwoSIMvlu6EnKTab4tgVqx9X0Fstbu', 'Administrator')
ON DUPLICATE KEY UPDATE username = username;

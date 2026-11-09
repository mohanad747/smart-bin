-- =====================================================================
-- Clear all test data before a demo.
-- KEEPS: bins (and their tokens), settings, users.
-- DELETES: readings, events, alert_log, and each bin's live values.
-- =====================================================================
USE smart_bin;

DELETE FROM alert_log;
DELETE FROM events;
DELETE FROM readings;

UPDATE bins SET
  fill_pct = NULL, fill_rate_pct_per_hr = NULL, predicted_full_at = NULL,
  prediction_status = 'NO_DATA', state = 'NORMAL', last_seen = NULL,
  last_distance_cm = NULL, last_temp_c = NULL, last_humidity = NULL, last_gas_raw = NULL,
  last_flame = NULL, last_fan = NULL, last_buzzer = NULL, last_rssi = NULL,
  last_uptime_s = NULL, last_fw = NULL, full_alerted = 0,
  test_mode = NULL, test_until = NULL, test_by = NULL;

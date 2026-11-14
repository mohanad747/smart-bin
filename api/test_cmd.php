<?php
// =====================================================================
// POST /smart_bin/api/test_cmd.php      (Simulator page, login + CSRF)
// Start or stop a test on the REAL bin.
//   bin_id  = BIN-01
//   mode    = FIRE (buzzer ON, fan OFF) | VAPOUR (fan ON) | STOP
//   seconds = how long, 5..120 (default 30); the test ends by itself
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/test_mode.php';
require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'use POST'], 405);
}
csrf_check();

$bin_id = $_POST['bin_id'] ?? '';
$mode   = $_POST['mode'] ?? '';
$bin = db_one($conn, 'SELECT bin_id FROM bins WHERE bin_id = ? AND active = 1 AND is_demo = 0', 's', [(string)$bin_id]);
if ($bin === null) {
    json_out(['ok' => false, 'error' => 'unknown bin'], 400);
}

if ($mode === 'STOP') {
    db_exec($conn, 'UPDATE bins SET test_mode = NULL, test_until = NULL, test_by = NULL
                    WHERE bin_id = ?', 's', [$bin_id]);
    json_out(['ok' => true, 'test' => null]);
}

if (!in_array($mode, ['FIRE', 'VAPOUR'], true)) {
    json_out(['ok' => false, 'error' => 'mode must be FIRE, VAPOUR or STOP'], 400);
}
$seconds = (int)($_POST['seconds'] ?? 30);
$seconds = max(TEST_MIN_S, min(TEST_MAX_S, $seconds));

db_exec($conn,
    'UPDATE bins SET test_mode = ?, test_until = NOW() + INTERVAL ? SECOND, test_by = ?
     WHERE bin_id = ?', 'siss', [$mode, $seconds, current_user(), $bin_id]);

json_out(['ok' => true, 'test' => current_test($conn, $bin_id)]);

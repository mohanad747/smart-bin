<?php
// =====================================================================
// POST /smart_bin/api/device_poll.php     (called by the ESP32 every 2 s)
// Tiny "any test for me?" check, so a test from the Simulator page
// reaches the bin in about 2 s instead of waiting for the next upload.
//
// Body (JSON):  {"bin_id":"BIN-01","token":"..."}
// Replies:      200 {"ok":true,"test":{"mode":"FIRE","seconds_left":23}}
//               200 {"ok":true,"test":null}
//               401 {"ok":false,"error":"invalid token"}
// Nothing is saved; readings still go to api/ingest.php.
// =====================================================================

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/test_mode.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'use POST'], 405);
}

$in     = json_decode(file_get_contents('php://input'), true);
$bin_id = is_array($in) ? ($in['bin_id'] ?? null) : null;
$token  = is_array($in) ? ($in['token'] ?? null) : null;
if (!is_string($bin_id) || !is_string($token) || $token === '') {
    json_out(['ok' => false, 'error' => 'invalid token'], 401);
}
$bin = db_one($conn, 'SELECT device_token FROM bins WHERE bin_id = ? AND active = 1', 's', [$bin_id]);
if ($bin === null || !hash_equals($bin['device_token'], $token)) {
    json_out(['ok' => false, 'error' => 'invalid token'], 401);
}

json_out(['ok' => true, 'test' => test_for_device(current_test($conn, $bin_id))]);

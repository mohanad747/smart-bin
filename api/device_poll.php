<?php
// =====================================================================
// POST /smart_bin/api/device_poll.php     (called by the ESP32 every 2 s)
// Tiny "any test or command for me?" check, so a test from the Simulator
// page or a command from the dashboard reaches the bin in about 2 s.
//
// Body (JSON):  {"bin_id":"BIN-01","token":"..."}
//               + "cmd_ack":41,"cmd_result":"done"   after doing a command
// Replies:      200 {"ok":true,"test":null,"cmd":null}
//               200 {"ok":true,"test":{"mode":"FIRE","seconds_left":23,"drop_ball":false},
//                    "cmd":{"id":41,"name":"lid_open"}}
//               401 {"ok":false,"error":"invalid token"}
// No reading is saved; readings still go to api/ingest.php.
// =====================================================================

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/test_mode.php';
require __DIR__ . '/../lib/bin_cmd.php';

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

ack_command($conn, $bin_id, $in);
json_out(['ok' => true, 'test' => test_for_device(current_test($conn, $bin_id)),
          'cmd' => next_command($conn, $bin_id)]);

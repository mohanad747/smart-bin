<?php
// =====================================================================
// POST /smart_bin/api/bin_cmd.php       (dashboard, login + CSRF)
//   bin_id = BIN-01
//   name   = lid_open | lid_close | arm_ball
// The ESP32 picks the command up within about 2 s (api/device_poll.php).
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/bin_cmd.php';
require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'use POST'], 405);
}
csrf_check();

$bin_id = (string)($_POST['bin_id'] ?? '');
$name   = (string)($_POST['name'] ?? '');
if (!isset(CMD_TEXT[$name])) {
    json_out(['ok' => false, 'error' => 'unknown command'], 400);
}
$bin = db_one($conn,
    'SELECT *, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS ago
     FROM bins WHERE bin_id = ? AND active = 1', 's', [$bin_id]);
if ($bin === null) {
    json_out(['ok' => false, 'error' => 'unknown bin'], 400);
}
if ($bin['is_demo']) {
    json_out(['ok' => false, 'error' => 'demo bins have no real lid'], 400);
}
if ($bin['lid_state'] === null) {
    json_out(['ok' => false, 'error' => 'this bin has not reported a lid (firmware 1.3.0 or newer is needed)'], 409);
}
$th = get_thresholds($conn, $bin_id);
if ($bin['ago'] === null || (int)$bin['ago'] > 3 * $th['upload_interval_s']) {
    json_out(['ok' => false, 'error' => 'the bin is offline'], 409);
}
if ($bin['state'] === 'FIRE' && $name === 'lid_open') {
    json_out(['ok' => false, 'error' => 'FIRE: the lid stays locked until the fire is over'], 409);
}
if ($name === 'arm_ball') {
    if ($bin['state'] === 'FIRE') {
        json_out(['ok' => false, 'error' => 'FIRE: re-arm the ball after the fire is over'], 409);
    }
    if ($bin['ball_state'] !== 'RELEASED') {
        json_out(['ok' => false, 'error' => 'the fire ball is already armed'], 409);
    }
}

expire_commands($conn, $bin_id);
if ($name === 'lid_close') {
    // "close" wins over an "open" the bin has not picked up yet
    db_exec($conn, "UPDATE bin_commands SET status = 'expired'
                    WHERE bin_id = ? AND name = 'lid_open' AND status = 'pending'", 's', [$bin_id]);
}
// pressed twice: keep the one that is already on its way
$open = db_one($conn, "SELECT id FROM bin_commands WHERE bin_id = ? AND name = ? AND status = 'pending'",
               'ss', [$bin_id, $name]);
if ($open === null) {
    db_exec($conn, 'INSERT INTO bin_commands (bin_id, name, created_by) VALUES (?, ?, ?)',
            'sss', [$bin_id, $name, current_user()]);
    $open = ['id' => $conn->insert_id];
}
json_out(['ok' => true, 'id' => (int)$open['id'], 'text' => CMD_TEXT[$name]]);

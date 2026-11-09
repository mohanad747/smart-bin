<?php
// =====================================================================
// Simulator helper (login + CSRF): writes 1 hour of past "filling"
// readings for a bin, so the time-to-full prediction can be demonstrated
// at once. TEST TOOL ONLY - it writes to the readings table directly.
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require_login(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'use POST'], 405);
}
csrf_check();

$bin_id   = (string)($_POST['bin_id'] ?? '');
$fill_now = max(0, min(100, (float)($_POST['fill_now'] ?? 50)));
$bin = db_one($conn, 'SELECT * FROM bins WHERE bin_id = ?', 's', [$bin_id]);
if (!$bin) {
    json_out(['ok' => false, 'error' => 'unknown bin'], 400);
}

// Do not go back past the last EMPTIED event (prediction starts there)
$since = db_one($conn,
    "SELECT TIMESTAMPDIFF(SECOND, MAX(started_at), NOW()) AS s FROM events
     WHERE bin_id = ? AND type = 'EMPTIED'", 's', [$bin_id])['s'];
$window = 3600;
if ($since !== null) {
    $window = min($window, (int)$since - 5);
}
if ($window < 600) {
    json_out(['ok' => false, 'error' =>
        'The bin was emptied less than 10 minutes ago, so there is no room for history. '
        . 'Use "Filling up" for a while instead.']);
}

// Straight line from (fill_now - 15) up to fill_now, one reading every 2 minutes
$fill_start = max(0, $fill_now - 15);
$depth = (float)$bin['empty_depth_cm'];
$count = 0;
for ($ago = $window; $ago > 0; $ago -= 120) {
    $fill = $fill_start + ($fill_now - $fill_start) * (1 - $ago / $window) + mt_rand(-5, 5) / 10;
    $fill = round(max(0, min(100, $fill)), 1);
    $dist = round($depth * (1 - $fill / 100), 1);
    $temp = round(31 + mt_rand(-3, 3) / 10, 1);
    db_exec($conn,
        "INSERT INTO readings (bin_id, distance_cm, fill_pct, gas_raw, flame, temp_c, humidity,
                               state, server_state, fan, buzzer, fw, created_at)
         VALUES (?, ?, ?, 800, 0, ?, 40, 'NORMAL', 'NORMAL', 0, 0, 'backfill', NOW() - INTERVAL ? SECOND)",
        'sdddi', [$bin_id, $dist, $fill, $temp, $ago]);
    $count++;
}

json_out(['ok' => true, 'message' =>
    "Added $count past readings ($fill_start% to $fill_now% over " . round($window / 60) . ' min). '
    . 'The next reading updates the prediction.']);

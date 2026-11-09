<?php
// =====================================================================
// GET /smart_bin/api/history.php?bin_id=BIN-01&hours=6
// Fill % and temperature of one bin for the charts (login required).
// Averaged per minute so the chart stays light (6 h = at most 360 points).
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require_login(true);

$bin_id = $_GET['bin_id'] ?? '';
$hours  = (int)($_GET['hours'] ?? 6);
$hours  = max(1, min(48, $hours));
if (!is_string($bin_id) || $bin_id === '') {
    json_out(['ok' => false, 'error' => 'bin_id is required'], 400);
}

$rows = db_all($conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS minute,
            ROUND(AVG(fill_pct), 1) AS fill_pct,
            ROUND(AVG(temp_c), 1)   AS temp_c
     FROM readings
     WHERE bin_id = ? AND created_at >= NOW() - INTERVAL ? HOUR
     GROUP BY minute ORDER BY minute",
    'si', [$bin_id, $hours]);

json_out([
    'ok'     => true,
    'bin_id' => $bin_id,
    'points' => array_map(fn($r) => [
        't'    => $r['minute'],
        'fill' => $r['fill_pct'] === null ? null : (float)$r['fill_pct'],
        'temp' => $r['temp_c']   === null ? null : (float)$r['temp_c'],
    ], $rows),
]);

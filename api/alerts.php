<?php
// =====================================================================
// GET /smart_bin/api/alerts.php   (login required)
// The hazards right now, for the alert bar / pop-up on EVERY page
// (assets/alerts.js asks every few seconds):
//   open VAPOUR events, and FIRE events until someone acknowledges them.
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/demo.php';
require_login(true);

$hazards = db_all($conn,
    "SELECT e.id, e.bin_id, e.type, e.started_at, e.ended_at, e.peak_temp_c, e.peak_gas_raw,
            e.acknowledged_by, e.acknowledged_at, e.is_test, e.alert_sent,
            b.name, b.location_text, b.lat, b.lng, b.is_demo
     FROM events e JOIN bins b ON b.bin_id = e.bin_id
     WHERE b.active = 1
       AND ((e.type = 'FIRE' AND (e.ended_at IS NULL OR e.acknowledged_at IS NULL))
            OR (e.type = 'VAPOUR' AND e.ended_at IS NULL))
     ORDER BY e.type = 'FIRE' DESC, e.started_at DESC");

// reply first, then let the demo bins take their next step (if it is time)
json_out_then([
    'ok'      => true,
    'now'     => date('Y-m-d H:i:s'),
    'csrf'    => csrf_token(),
    'hazards' => array_map(fn($h) => $h + [
        'maps' => ($h['lat'] !== null && $h['lng'] !== null)
                  ? 'https://maps.google.com/?q=' . $h['lat'] . ',' . $h['lng'] : null,
    ], $hazards),
], fn() => demo_tick($conn));

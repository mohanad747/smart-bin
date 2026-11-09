<?php
// =====================================================================
// GET /smart_bin/api/status.php[?events_bin=BIN-01]
// Everything the dashboard needs, polled every 5 s (login required).
//   bins     - every active bin with its latest values and thresholds
//   priority - DFD process 5.0: bins ordered by time-to-full, then fill %
//   hazards  - open VAPOUR events + FIRE events not yet acknowledged
//   events   - event log, newest first (optionally for one bin)
// It also opens OFFLINE events for bins that stopped reporting.
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require_login(true);

// ---------------------------------------------------------------------
// Bins + their thresholds
// ---------------------------------------------------------------------
$rows = db_all($conn,
    'SELECT b.*, TIMESTAMPDIFF(SECOND, b.last_seen, NOW()) AS seconds_ago,
            TIMESTAMPDIFF(SECOND, NOW(), b.predicted_full_at) AS seconds_to_full
     FROM bins b WHERE b.active = 1 ORDER BY b.bin_id');

$bins = [];
foreach ($rows as $r) {
    $th = get_thresholds($conn, $r['bin_id']);
    $seconds_ago = $r['seconds_ago'] === null ? null : (int)$r['seconds_ago'];
    // Offline = no reading for 3x the upload interval (or never seen)
    $offline = $seconds_ago === null || $seconds_ago > 3 * $th['upload_interval_s'];

    // Open an OFFLINE event the first time we notice (closed by ingest.php)
    if ($offline && $r['last_seen'] !== null) {
        $open = db_one($conn,
            "SELECT id FROM events WHERE bin_id = ? AND type = 'OFFLINE' AND ended_at IS NULL",
            's', [$r['bin_id']]);
        if ($open === null) {
            db_exec($conn,
                "INSERT INTO events (bin_id, type, started_at, note)
                 VALUES (?, 'OFFLINE', ?, 'No reading received')",
                'ss', [$r['bin_id'], $r['last_seen']]);
        }
    }

    $fill = $r['fill_pct'] === null ? null : (float)$r['fill_pct'];
    $level = 'unknown';
    if ($fill !== null) {
        $level = $fill >= $th['full_pct'] ? 'full'
               : ($fill >= $th['nearly_full_pct'] ? 'nearly' : 'ok');
    }

    $bins[] = [
        'bin_id'            => $r['bin_id'],
        'name'              => $r['name'],
        'location_text'     => $r['location_text'],
        'lat'               => $r['lat'] === null ? null : (float)$r['lat'],
        'lng'               => $r['lng'] === null ? null : (float)$r['lng'],
        'fill_pct'          => $fill,
        'level'             => $level,                 // ok / nearly / full / unknown
        'fill_rate'         => $r['fill_rate_pct_per_hr'] === null ? null : (float)$r['fill_rate_pct_per_hr'],
        'prediction_status' => $r['prediction_status'],
        'predicted_full_at' => $r['predicted_full_at'],
        'seconds_to_full'   => $r['seconds_to_full'] === null ? null : max(0, (int)$r['seconds_to_full']),
        'state'             => $r['state'],
        'last_seen'         => $r['last_seen'],
        'seconds_ago'       => $seconds_ago,
        'offline'           => $offline,
        'distance_cm'       => $r['last_distance_cm'] === null ? null : (float)$r['last_distance_cm'],
        'temp_c'            => $r['last_temp_c'] === null ? null : (float)$r['last_temp_c'],
        'humidity'          => $r['last_humidity'] === null ? null : (float)$r['last_humidity'],
        'gas_raw'           => $r['last_gas_raw'] === null ? null : (int)$r['last_gas_raw'],
        'flame'             => $r['last_flame'] === null ? null : (bool)$r['last_flame'],
        'fan'               => $r['last_fan'] === null ? null : (bool)$r['last_fan'],
        'buzzer'            => $r['last_buzzer'] === null ? null : (bool)$r['last_buzzer'],
        'rssi'              => $r['last_rssi'] === null ? null : (int)$r['last_rssi'],
        'fw'                => $r['last_fw'],
        'thresholds'        => $th,
    ];
}

// ---------------------------------------------------------------------
// 5.0 Rank collection priority:
//   1) bins with a known time-to-full (full now = 0), soonest first
//   2) the rest by fill %, highest first
// ---------------------------------------------------------------------
$priority = $bins;
usort($priority, function ($a, $b) {
    $ta = $a['seconds_to_full'];
    $tb = $b['seconds_to_full'];
    if ($ta !== null && $tb !== null && $ta !== $tb) return $ta <=> $tb;
    if ($ta !== null && $tb === null) return -1;
    if ($ta === null && $tb !== null) return 1;
    return ($b['fill_pct'] ?? -1) <=> ($a['fill_pct'] ?? -1);
});
$priority = array_map(fn($b) => $b['bin_id'], $priority);

// ---------------------------------------------------------------------
// Hazard banner: open VAPOUR, and FIRE until someone acknowledges it
// ---------------------------------------------------------------------
$hazards = db_all($conn,
    "SELECT e.*, b.name, b.location_text, b.lat, b.lng
     FROM events e JOIN bins b ON b.bin_id = e.bin_id
     WHERE (e.type = 'FIRE' AND (e.ended_at IS NULL OR e.acknowledged_at IS NULL))
        OR (e.type = 'VAPOUR' AND e.ended_at IS NULL)
     ORDER BY e.type = 'FIRE' DESC, e.started_at DESC");

// ---------------------------------------------------------------------
// Event log (newest first), with the alert status of each event
// ---------------------------------------------------------------------
$filter = $_GET['events_bin'] ?? '';
$sql = "SELECT e.*,
            TIMESTAMPDIFF(SECOND, e.started_at, COALESCE(e.ended_at, NOW())) AS duration_s,
            (SELECT COUNT(*) FROM alert_log a WHERE a.event_id = e.id) AS alert_attempts
        FROM events e";
if (is_string($filter) && $filter !== '') {
    $events = db_all($conn, $sql . ' WHERE e.bin_id = ? ORDER BY e.started_at DESC, e.id DESC LIMIT 100',
                     's', [$filter]);
} else {
    $events = db_all($conn, $sql . ' ORDER BY e.started_at DESC, e.id DESC LIMIT 100');
}

json_out([
    'ok'       => true,
    'now'      => date('Y-m-d H:i:s'),
    'user'     => current_user(),
    'csrf'     => csrf_token(),
    'bins'     => $bins,
    'priority' => $priority,
    'hazards'  => $hazards,
    'events'   => $events,
]);

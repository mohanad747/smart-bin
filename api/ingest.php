<?php
// =====================================================================
// POST /smart_bin/api/ingest.php      (called by the ESP32 bin unit)
//
// Body (JSON):
//   {"bin_id":"BIN-01","token":"...","distance_cm":42.3,"gas_raw":812,
//    "flame":false,"temp_c":31.4,"humidity":40.2,"state":"NORMAL",
//    "fan":false,"buzzer":false,"uptime_s":1234,"rssi":-61,"fw":"1.0.0"}
//
// Replies:
//   200 {"ok":true,"thresholds":{...}}
//   401 {"ok":false,"error":"invalid token"}
//   400 {"ok":false,"error":"<reason>"}         (nothing is saved)
//
// Steps follow our level-1 DFD:
//   1.0 receive + validate   2.0 detect hazard event   3.0 urgent alert
//   4.0 predict time-to-full (5.0 ranking is done by api/status.php)
// =====================================================================

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/alerts.php';

const MQ2_WARMUP_S  = 60;   // MQ-2 reads high while its heater warms up: ignore gas before this
const MERGE_GAP_S   = 120;  // a hazard that comes back within 2 min continues the same event
const EMPTIED_DROP  = 30;   // fill falling by 30 points or more = the bin was emptied
const MIN_POINTS    = 5;    // prediction needs at least 5 readings ...
const MIN_SPAN_S    = 600;  // ... spread over at least 10 minutes
const MIN_RATE      = 0.1;  // % per hour; slower than this counts as "not filling" (noise)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'use POST'], 405);
}

// ---------------------------------------------------------------------
// 1.0 Receive and validate the reading
// ---------------------------------------------------------------------
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(['ok' => false, 'error' => 'body must be JSON'], 400);
}

// --- token check (wrong or missing token -> 401, nothing saved) ---
$bin_id = $in['bin_id'] ?? null;
$token  = $in['token']  ?? null;
if (!is_string($bin_id) || !is_string($token) || $token === '') {
    json_out(['ok' => false, 'error' => 'invalid token'], 401);
}
$bin = db_one($conn, 'SELECT * FROM bins WHERE bin_id = ? AND active = 1', 's', [$bin_id]);
if ($bin === null || !hash_equals($bin['device_token'], $token)) {
    json_out(['ok' => false, 'error' => 'invalid token'], 401);
}

// --- range checks (bad value -> 400, nothing saved) ---
// Returns the number, or null if allowed; stops with 400 otherwise.
function number_in(array $in, string $key, float $min, float $max, bool $nullable): ?float
{
    $v = $in[$key] ?? null;
    if ($v === null) {
        if ($nullable) return null;
        json_out(['ok' => false, 'error' => "$key is missing"], 400);
    }
    if (!is_int($v) && !is_float($v)) {
        json_out(['ok' => false, 'error' => "$key must be a number"], 400);
    }
    if ($v < $min || $v > $max) {
        json_out(['ok' => false, 'error' => "$key out of range ($min to $max)"], 400);
    }
    return (float)$v;
}

// true / false (1 / 0 also accepted)
function bool_in(array $in, string $key): int
{
    $v = $in[$key] ?? null;
    if ($v === true || $v === 1)  return 1;
    if ($v === false || $v === 0) return 0;
    json_out(['ok' => false, 'error' => "$key must be true or false"], 400);
}

$distance = number_in($in, 'distance_cm', 0, 400, true);
$temp     = number_in($in, 'temp_c', -40, 80, true);
$humidity = number_in($in, 'humidity', 0, 100, true);
$gas      = (int)number_in($in, 'gas_raw', 0, 4095, false);
$flame    = bool_in($in, 'flame');
$fan      = bool_in($in, 'fan');
$buzzer   = bool_in($in, 'buzzer');
$uptime   = number_in($in, 'uptime_s', 0, 4294967295, true);
$rssi     = number_in($in, 'rssi', -127, 0, true);
$uptime   = $uptime === null ? null : (int)$uptime;
$rssi     = $rssi === null ? null : (int)$rssi;

$state = $in['state'] ?? null;
if (!in_array($state, ['NORMAL', 'VAPOUR', 'FIRE'], true)) {
    json_out(['ok' => false, 'error' => 'state must be NORMAL, VAPOUR or FIRE'], 400);
}
$fw = $in['fw'] ?? null;
if ($fw !== null && (!is_string($fw) || strlen($fw) > 20)) {
    json_out(['ok' => false, 'error' => 'fw must be a short text'], 400);
}

$th = get_thresholds($conn, $bin_id);

// --- fill % = (empty depth - distance) / empty depth x 100, kept 0..100 ---
$fill  = null;
$depth = (float)$bin['empty_depth_cm'];
if ($distance !== null && $depth > 0) {
    $fill = round(max(0, min(100, ($depth - $distance) / $depth * 100)), 1);
}

// ---------------------------------------------------------------------
// 2.0 Detect hazard
// The ESP32 decides the state itself (safety works without the server).
// The server re-checks the same thresholds; if its check is MORE severe
// (e.g. the bin still has old thresholds) the more severe state is used.
// ---------------------------------------------------------------------
$server_state = 'NORMAL';
$fire = ($flame === 1) || ($temp !== null && $temp >= $th['temp_limit_c']);
if (!$fire && $temp !== null) {
    // sudden rise: now minus the lowest temperature within the window
    $low = db_one($conn,
        'SELECT MIN(temp_c) AS t FROM readings
         WHERE bin_id = ? AND temp_c IS NOT NULL
           AND created_at >= NOW() - INTERVAL ? SECOND',
        'si', [$bin_id, $th['temp_rise_window_s']]);
    if ($low['t'] !== null && $temp - (float)$low['t'] >= $th['temp_rise_c']) {
        $fire = true;
    }
}
$warming_up = ($uptime !== null && $uptime < MQ2_WARMUP_S);
if ($fire) {
    $server_state = 'FIRE';
} elseif (!$warming_up && $gas >= $th['gas_raw']) {
    $server_state = 'VAPOUR';
}
$rank      = ['NORMAL' => 0, 'VAPOUR' => 1, 'FIRE' => 2];
$bin_state = $rank[$server_state] > $rank[$state] ? $server_state : $state;

$prev_fill   = $bin['fill_pct'] === null ? null : (float)$bin['fill_pct'];
$fire_event  = null;   // set when a NEW fire event starts -> alert
$send_full   = false;  // optional "bin full" message

$conn->begin_transaction();

// --- save the reading (D2) ---
db_exec($conn,
    'INSERT INTO readings (bin_id, distance_cm, fill_pct, gas_raw, flame, temp_c, humidity,
                           state, server_state, fan, buzzer, uptime_s, rssi, fw)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'sddiiddssiiiis',
    [$bin_id, $distance, $fill, $gas, $flame, $temp, $humidity,
     $state, $server_state, $fan, $buzzer, $uptime, $rssi, $fw]);

// --- update the bin (D1); COALESCE keeps the last good value when a sensor sent null ---
db_exec($conn,
    'UPDATE bins SET state = ?, last_seen = NOW(),
        fill_pct         = COALESCE(?, fill_pct),
        last_distance_cm = COALESCE(?, last_distance_cm),
        last_temp_c      = COALESCE(?, last_temp_c),
        last_humidity    = COALESCE(?, last_humidity),
        last_gas_raw = ?, last_flame = ?, last_fan = ?, last_buzzer = ?,
        last_rssi     = COALESCE(?, last_rssi),
        last_uptime_s = COALESCE(?, last_uptime_s),
        last_fw       = COALESCE(?, last_fw)
     WHERE bin_id = ?',
    'sddddiiiiiiss',
    [$bin_state, $fill, $distance, $temp, $humidity, $gas, $flame, $fan, $buzzer,
     $rssi, $uptime, $fw, $bin_id]);

// --- events (D3) ---
// The bin is talking again: close an open OFFLINE event
db_exec($conn,
    "UPDATE events SET ended_at = NOW()
     WHERE bin_id = ? AND type = 'OFFLINE' AND ended_at IS NULL", 's', [$bin_id]);

$open = db_one($conn,
    "SELECT * FROM events WHERE bin_id = ? AND type IN ('VAPOUR','FIRE') AND ended_at IS NULL
     ORDER BY id DESC LIMIT 1", 's', [$bin_id]);

// Higher of two values, ignoring nulls
function peak($old, $new)
{
    if ($old === null) return $new;
    if ($new === null) return $old;
    return max((float)$old, (float)$new);
}

if ($open !== null && $open['type'] !== $bin_state) {
    // state changed (back to NORMAL, or VAPOUR <-> FIRE): close the old event
    db_exec($conn, 'UPDATE events SET ended_at = NOW() WHERE id = ?', 'i', [$open['id']]);
    $open = null;
}

if ($bin_state !== 'NORMAL') {
    if ($open === null) {
        // Came back within MERGE_GAP_S? Then it is the same event (no second alert).
        $recent = db_one($conn,
            'SELECT * FROM events WHERE bin_id = ? AND type = ? AND ended_at IS NOT NULL
               AND ended_at >= NOW() - INTERVAL ? SECOND
             ORDER BY id DESC LIMIT 1',
            'ssi', [$bin_id, $bin_state, MERGE_GAP_S]);
        if ($recent !== null) {
            db_exec($conn, 'UPDATE events SET ended_at = NULL WHERE id = ?', 'i', [$recent['id']]);
            $open = $recent;
        } else {
            db_exec($conn,
                'INSERT INTO events (bin_id, type, started_at, peak_temp_c, peak_gas_raw)
                 VALUES (?, ?, NOW(), ?, ?)',
                'ssdi', [$bin_id, $bin_state, $temp, $gas]);
            $event_id = $conn->insert_id;
            if ($bin_state === 'FIRE') {
                $fire_event = $event_id;    // NEW fire event -> one urgent alert
            }
        }
    }
    if ($open !== null) {
        // still the same event: keep the peak values
        $pt = peak($open['peak_temp_c'], $temp);
        $pg = (int)peak($open['peak_gas_raw'], $gas);
        db_exec($conn, 'UPDATE events SET peak_temp_c = ?, peak_gas_raw = ? WHERE id = ?',
            'dii', [$pt, $pg, $open['id']]);
    }
}

// EMPTIED: the fill dropped by 30 points or more since the last reading
if ($prev_fill !== null && $fill !== null && $prev_fill - $fill >= EMPTIED_DROP) {
    $note = 'Fill ' . round($prev_fill) . '% -> ' . round($fill) . '%';
    db_exec($conn,
        "INSERT INTO events (bin_id, type, started_at, ended_at, note)
         VALUES (?, 'EMPTIED', NOW(), NOW(), ?)", 'ss', [$bin_id, $note]);
}

// Optional "bin full" message, once per fill cycle
if ($fill !== null) {
    if ($fill < $th['nearly_full_pct']) {
        db_exec($conn, 'UPDATE bins SET full_alerted = 0 WHERE bin_id = ?', 's', [$bin_id]);
    } elseif ($fill >= $th['full_pct'] && !$bin['full_alerted']
              && !empty($CONFIG['alerts']['notify_full'])) {
        db_exec($conn, 'UPDATE bins SET full_alerted = 1 WHERE bin_id = ?', 's', [$bin_id]);
        $send_full = true;
    }
}

// ---------------------------------------------------------------------
// 4.0 Predict time-to-full
// Straight-line fit (least squares) of fill % against time, using the
// readings since the bin was last emptied.
// ---------------------------------------------------------------------
$since = db_one($conn,
    "SELECT MAX(started_at) AS t FROM events WHERE bin_id = ? AND type = 'EMPTIED'",
    's', [$bin_id])['t'] ?? '1970-01-01 00:00:00';

$points = db_all($conn,
    'SELECT UNIX_TIMESTAMP(created_at) AS t, fill_pct AS f FROM readings
     WHERE bin_id = ? AND fill_pct IS NOT NULL AND created_at >= ?
     ORDER BY created_at DESC LIMIT 3000',
    'ss', [$bin_id, $since]);

$now_fill = $fill ?? $prev_fill;
$rate     = null;          // % per hour
$status   = 'NO_DATA';
$seconds_to_full = null;

$n = count($points);
if ($n >= MIN_POINTS) {
    $times = array_map(fn($p) => (int)$p['t'], $points);
    $t0    = min($times);                              // oldest point = time 0
    $span  = max($times) - $t0;
    if ($span >= MIN_SPAN_S) {
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($points as $p) {
            $x = ((int)$p['t'] - $t0) / 3600.0;        // hours
            $y = (float)$p['f'];
            $sx += $x; $sy += $y; $sxx += $x * $x; $sxy += $x * $y;
        }
        $den  = $n * $sxx - $sx * $sx;
        $rate = $den == 0 ? 0.0 : ($n * $sxy - $sx * $sy) / $den;
        $status = $rate > MIN_RATE ? 'FILLING' : 'NOT_FILLING';
    }
}
if ($now_fill !== null && $now_fill >= $th['full_pct']) {
    $status = 'FULL';
    $seconds_to_full = 0;
} elseif ($status === 'FILLING') {
    $seconds_to_full = (int)min(365 * 86400, ($th['full_pct'] - $now_fill) / $rate * 3600);
}

db_exec($conn,
    'UPDATE bins SET fill_rate_pct_per_hr = ?, prediction_status = ?,
        predicted_full_at = IF(? IS NULL, NULL, NOW() + INTERVAL ? SECOND)
     WHERE bin_id = ?',
    'dsiis', [$rate === null ? null : round($rate, 2), $status,
              $seconds_to_full, $seconds_to_full, $bin_id]);

$conn->commit();

// ---------------------------------------------------------------------
// Reply to the ESP32 FIRST, so it never waits for email / WhatsApp
// ---------------------------------------------------------------------
$reply = json_encode(['ok' => true, 'thresholds' => [
    'gas_raw'            => $th['gas_raw'],
    'temp_limit_c'       => $th['temp_limit_c'],
    'temp_rise_c'        => $th['temp_rise_c'],
    'temp_rise_window_s' => $th['temp_rise_window_s'],
    'upload_interval_s'  => $th['upload_interval_s'],
]]);

ignore_user_abort(true);     // keep running after the ESP32 hangs up
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($reply));
header('Connection: close');
echo $reply;
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ---------------------------------------------------------------------
// 3.0 Send urgent alert (after the reply). Only for a NEW fire event,
// never once per reading. VAPOUR is shown on the dashboard only.
// ---------------------------------------------------------------------
set_time_limit(90);
$now_text = date('Y-m-d H:i:s');

if ($fire_event !== null) {
    $show_temp = $temp ?? ($bin['last_temp_c'] === null ? null : (float)$bin['last_temp_c']);
    [$subject, $text] = fire_message($bin, $show_temp, $now_text);
    $results = send_alert($conn, $CONFIG, 'FIRE', $bin_id, $fire_event, $subject, $text);
    if (any_success($results)) {
        db_exec($conn, 'UPDATE events SET alert_sent = 1 WHERE id = ?', 'i', [$fire_event]);
    }
}

if ($send_full) {
    [$subject, $text] = full_message($bin, $fill, $now_text);
    send_alert($conn, $CONFIG, 'FULL', $bin_id, null, $subject, $text);
}

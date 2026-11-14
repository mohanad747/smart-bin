<?php
// =====================================================================
// POST /smart_bin/api/ingest.php      (called by the ESP32 bin unit)
//
// Body (JSON):
//   {"bin_id":"BIN-01","token":"...","distance_cm":42.3,"gas_raw":812,
//    "flame":false,"temp_c":31.4,"humidity":40.2,"state":"NORMAL",
//    "fan":false,"buzzer":false,"uptime_s":1234,"rssi":-61,"fw":"1.1.0",
//    "test":false}
//   "test" (optional) = true when the state comes from a test command
//   started on the Simulator page, not from the sensors.
//
// Replies:
//   200 {"ok":true,"thresholds":{...},"test":null | {"mode":"FIRE","seconds_left":23}}
//   401 {"ok":false,"error":"invalid token"}
//   400 {"ok":false,"error":"<reason>"}         (nothing is saved)
//
// Steps follow our level-1 DFD:
//   1.0 receive + validate   2.0 detect hazard event   3.0 urgent alert
//   4.0 predict time-to-full (5.0 ranking is done by api/status.php)
// =====================================================================

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/alerts.php';
require __DIR__ . '/../lib/test_mode.php';
require __DIR__ . '/../lib/demo.php';

const MQ2_WARMUP_S  = 60;   // MQ-2 reads high while its heater warms up: ignore gas before this
const MERGE_GAP_S   = 120;  // a hazard that comes back within 2 min continues the same event
const EMPTIED_DROP  = 30;   // fill falling by 30 points or more = the bin was emptied ...
const EMPTIED_CONFIRM = 3;  // ... if it stays down for 3 readings in a row (about 30 s)
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
$is_test  = array_key_exists('test', $in) && $in['test'] !== null ? bool_in($in, 'test') : 0;

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
                           state, server_state, fan, buzzer, uptime_s, rssi, fw, is_test)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    'sddiiddssiiiisi',
    [$bin_id, $distance, $fill, $gas, $flame, $temp, $humidity,
     $state, $server_state, $fan, $buzzer, $uptime, $rssi, $fw, $is_test]);

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

// The hazard is a test only if the bin says so AND the server's own check
// of the sensor values found nothing (a real fire during a test is real).
$event_is_test = ($is_test && $rank[$server_state] < $rank[$bin_state]) ? 1 : 0;

if ($open !== null && ($open['type'] !== $bin_state || (int)$open['is_test'] !== $event_is_test)) {
    // state changed (back to NORMAL, VAPOUR <-> FIRE, or test <-> real): close the old event
    db_exec($conn, 'UPDATE events SET ended_at = NOW() WHERE id = ?', 'i', [$open['id']]);
    $open = null;
}

if ($bin_state !== 'NORMAL') {
    if ($open === null) {
        // Came back within MERGE_GAP_S? Then it is the same event (no second alert).
        $recent = db_one($conn,
            'SELECT * FROM events WHERE bin_id = ? AND type = ? AND is_test = ? AND ended_at IS NOT NULL
               AND ended_at >= NOW() - INTERVAL ? SECOND
             ORDER BY id DESC LIMIT 1',
            'ssii', [$bin_id, $bin_state, $event_is_test, MERGE_GAP_S]);
        if ($recent !== null) {
            db_exec($conn, 'UPDATE events SET ended_at = NULL WHERE id = ?', 'i', [$recent['id']]);
            $open = $recent;
        } else {
            db_exec($conn,
                'INSERT INTO events (bin_id, type, started_at, peak_temp_c, peak_gas_raw, is_test, note)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?)',
                'ssdiis', [$bin_id, $bin_state, $temp, $gas, $event_is_test,
                           $event_is_test ? 'Test from the Simulator page' : null]);
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

// EMPTIED: the fill dropped by 30 points or more AND stayed down for
// EMPTIED_CONFIRM readings in a row. One odd echo (a hand, a moving bag,
// the lid opening) is not an emptying, so a single low reading is ignored.
if ($fill !== null) {
    $last = db_all($conn,
        'SELECT fill_pct, created_at FROM readings
         WHERE bin_id = ? AND fill_pct IS NOT NULL
         ORDER BY created_at DESC, id DESC LIMIT ' . (2 * EMPTIED_CONFIRM),
        's', [$bin_id]);
    if (count($last) === 2 * EMPTIED_CONFIRM) {
        $new = array_slice($last, 0, EMPTIED_CONFIRM);        // newest readings (this one included)
        $old = array_slice($last, EMPTIED_CONFIRM);           // the readings just before them
        $old_fills = array_map(fn($r) => (float)$r['fill_pct'], $old);
        sort($old_fills);
        $before = $old_fills[intdiv(EMPTIED_CONFIRM, 2)];     // median: the level before the drop
        $after  = max(array_map(fn($r) => (float)$r['fill_pct'], $new));   // ALL new ones are below this
        $first_low = $new[EMPTIED_CONFIRM - 1]['created_at'];
        if ($before - $after >= EMPTIED_DROP) {
            // only once per emptying: not if it was already logged for these readings
            $done = db_one($conn,
                "SELECT id FROM events WHERE bin_id = ? AND type = 'EMPTIED' AND started_at >= ?",
                'ss', [$bin_id, $old[EMPTIED_CONFIRM - 1]['created_at']]);
            if ($done === null) {
                $note = 'Fill ' . round($before) . '% -> ' . round($after) . '%';
                db_exec($conn,
                    "INSERT INTO events (bin_id, type, started_at, ended_at, note)
                     VALUES (?, 'EMPTIED', ?, ?, ?)", 'ssss', [$bin_id, $first_low, $first_low, $note]);
            }
        }
    }
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

// demo bins in fast mode fill 60x faster: they need a shorter span (still 5 readings)
$min_span = empty($bin['is_demo']) ? MIN_SPAN_S : max(40, intdiv(MIN_SPAN_S, demo_speed($conn)));

$n = count($points);
if ($n >= MIN_POINTS) {
    $times = array_map(fn($p) => (int)$p['t'], $points);
    $t0    = min($times);                              // oldest point = time 0
    $span  = max($times) - $t0;
    if ($span >= $min_span) {
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

$test = current_test($conn, $bin_id);   // test started on the Simulator page, if any

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
], 'test' => test_for_device($test)]);

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

// Demo bins (example bins, not real) never send email / WhatsApp
$may_alert = empty($bin['is_demo']);

if ($fire_event !== null && $may_alert) {
    $show_temp = $temp ?? ($bin['last_temp_c'] === null ? null : (float)$bin['last_temp_c']);
    [$subject, $text] = fire_message($bin, $show_temp, $now_text, (bool)$event_is_test);
    $results = send_alert($conn, $CONFIG, 'FIRE', $bin_id, $fire_event, $subject, $text);
    if (any_success($results)) {
        db_exec($conn, 'UPDATE events SET alert_sent = 1 WHERE id = ?', 'i', [$fire_event]);
    }
}

if ($send_full && $may_alert) {
    [$subject, $text] = full_message($bin, $fill, $now_text);
    send_alert($conn, $CONFIG, 'FULL', $bin_id, null, $subject, $text);
}

<?php
// =====================================================================
// Demo bins: example bins around the UTAS Muscat campus that are NOT real. They show what
// the dashboard can do (map, priority list, predictions, emptying).
//
//  * Their readings go through the REAL api/ingest.php, like the ESP32's,
//    so fill %, predictions, priority and EMPTIED events are all real logic.
//  * A "tick" runs after the dashboard's polls (status.php / alerts.php)
//    at most every DEMO_TICK_S seconds. No extra program has to run.
//  * Fast demo: 1 real minute = 1 simulated hour, so the priority list
//    visibly changes during a presentation. "Real time" is also possible.
//  * Demo bins never send email / WhatsApp (see api/ingest.php).
// =====================================================================

const DEMO_TICK_S   = 10;     // one reading per demo bin every 10 s
const DEMO_SPEED    = ['fast' => 60, 'real' => 1];   // simulated seconds per real second
const DEMO_MAX_GAP  = 30;     // after a long pause (dashboard closed) continue, do not jump

// id, name, location, lat, lng, depth (cm), fill rate (% per simulated hour, daytime), start fill
const DEMO_BINS = [
    ['DEMO-01', 'Main gate',          'UTAS Muscat campus, South Al Khuwair',   23.5797, 58.4283, 90,  9.0, 72],
    ['DEMO-02', 'Student cafeteria',  'UTAS Muscat campus, South Al Khuwair',   23.5768, 58.4318, 90,  7.0, 55],
    ['DEMO-03', 'Library',            'UTAS Muscat campus, South Al Khuwair',   23.5790, 58.4322, 110, 11.0, 30],
    ['DEMO-04', 'Sports ground',      'UTAS Muscat campus, South Al Khuwair',   23.5752, 58.4282, 80,  4.0, 15],
    ['DEMO-05', 'Car park',           'UTAS Muscat campus, South Al Khuwair',   23.5810, 58.4305, 110, 8.0, 88],
    ['DEMO-06', 'Bus stop',           'Near UTAS Muscat, South Al Khuwair',     23.5828, 58.4268, 90,  5.0, 45],
    ['DEMO-07', 'Mosque',             'Near UTAS Muscat, South Al Khuwair',     23.5742, 58.4340, 80,  3.0, 10],
    ['DEMO-08', 'Shops street',       'Near UTAS Muscat, South Al Khuwair',     23.5838, 58.4345, 90,  6.0, 62],
];

// ---------------------------------------------------------------------
// App settings (key/value table)
// ---------------------------------------------------------------------
function app_setting(mysqli $conn, string $k, string $default = ''): string
{
    $r = db_one($conn, 'SELECT v FROM app_settings WHERE k = ?', 's', [$k]);
    return $r === null ? $default : $r['v'];
}

function set_app_setting(mysqli $conn, string $k, string $v): void
{
    db_exec($conn, 'INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            'ss', [$k, $v]);
}

function demo_enabled(mysqli $conn): bool
{
    return app_setting($conn, 'demo_enabled', '0') === '1';
}

function demo_speed(mysqli $conn): int
{
    return DEMO_SPEED[app_setting($conn, 'demo_speed', 'fast')] ?? 60;
}

// ---------------------------------------------------------------------
// The simulated world
// ---------------------------------------------------------------------
// Simulated hour of the day (0..24). Fast demo: the day runs 60x faster.
function demo_sim_hour(int $speed): float
{
    $local = time() + (int)date('Z');                       // seconds since epoch, local time
    $sim   = $speed === 1 ? $local : $local * $speed;
    return fmod($sim / 3600, 24);
}

// People use the bins much more in the day than at night
function demo_activity(float $hour): float
{
    return ($hour >= 6 && $hour < 23) ? 1.3 : 0.25;
}

// Muscat in November: about 24 C at night, 32 C in the afternoon
function demo_weather(float $hour, int $n): array
{
    $wave = sin(2 * M_PI * ($hour - 9) / 24);               // +1 at 15:00, -1 at 03:00
    $temp = 28 + 4 * $wave + ($n % 3) * 0.4 + mt_rand(-10, 10) / 50;
    $hum  = 55 - 15 * $wave + mt_rand(-10, 10) / 10;
    return [round($temp, 1), round(max(15, min(95, $hum)), 1)];
}

function demo_def(string $bin_id): ?array
{
    foreach (DEMO_BINS as $n => $d) {
        if ($d[0] === $bin_id) return $d + [8 => $n];
    }
    return null;
}

// ---------------------------------------------------------------------
// Create (or re-create) the demo bins with a short history, so charts,
// predictions and the priority list show straight away.
// ---------------------------------------------------------------------
function demo_reset(mysqli $conn): void
{
    $speed  = demo_speed($conn);
    $ids    = array_column(DEMO_BINS, 0);
    $marks  = implode(',', array_fill(0, count($ids), '?'));
    $types  = str_repeat('s', count($ids));

    // forget everything the demo bins did before (never touches real bins)
    db_exec($conn, "DELETE FROM alert_log WHERE bin_id IN ($marks)", $types, $ids);
    db_exec($conn, "DELETE FROM events    WHERE bin_id IN ($marks)", $types, $ids);
    db_exec($conn, "DELETE FROM readings  WHERE bin_id IN ($marks)", $types, $ids);

    // history: 15 real minutes (fast) or 6 hours (real time), one reading per 30 s
    $span_s = $speed > 1 ? 15 * 60 : 6 * 3600;
    $step_s = 30;

    foreach (DEMO_BINS as $n => [$id, $name, $loc, $lat, $lng, $depth, $rate, $start]) {
        db_exec($conn,
            'INSERT INTO bins (bin_id, name, location_text, lat, lng, device_token, empty_depth_cm, active, is_demo)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), location_text = VALUES(location_text),
                lat = VALUES(lat), lng = VALUES(lng), empty_depth_cm = VALUES(empty_depth_cm),
                active = 1, is_demo = 1',
            'sssddsd', [$id, $name, $loc, $lat, $lng, bin2hex(random_bytes(16)), $depth]);
        db_exec($conn, 'INSERT IGNORE INTO settings (bin_id) VALUES (?)', 's', [$id]);

        $last = null;
        for ($ago = $span_s; $ago >= 0; $ago -= $step_s) {
            $sim_h_back = $ago * $speed / 3600;
            $fill = max(2.0, $start - $rate * 0.9 * $sim_h_back + mt_rand(-5, 5) / 10);
            $hour = fmod(demo_sim_hour($speed) - $sim_h_back + 48, 24);
            [$temp, $hum] = demo_weather($hour, $n);
            $gas  = 360 + $n * 15 + mt_rand(-25, 25);
            $dist = round($depth * (1 - $fill / 100), 1);
            db_exec($conn,
                "INSERT INTO readings (bin_id, distance_cm, fill_pct, gas_raw, flame, temp_c, humidity,
                                       state, server_state, fan, buzzer, uptime_s, rssi, fw, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, 'NORMAL', 'NORMAL', 0, 0, ?, ?, 'demo', NOW() - INTERVAL ? SECOND)",
                'sddiddiii', [$id, $dist, round($fill, 1), $gas, $temp, $hum, 86400 - $ago, -55 - mt_rand(0, 15), $ago]);
            $last = [$fill, $dist, $temp, $hum, $gas];
        }
        [$fill, $dist, $temp, $hum, $gas] = $last;
        db_exec($conn,
            "UPDATE bins SET state = 'NORMAL', fill_pct = ?, last_distance_cm = ?, last_temp_c = ?,
                last_humidity = ?, last_gas_raw = ?, last_flame = 0, last_fan = 0, last_buzzer = 0,
                last_rssi = -60, last_uptime_s = 86400, last_fw = 'demo', last_seen = NOW() - INTERVAL 20 SECOND,
                fill_rate_pct_per_hr = NULL, predicted_full_at = NULL, prediction_status = 'NO_DATA',
                full_alerted = 0, test_mode = NULL, test_until = NULL, test_by = NULL
             WHERE bin_id = ?",
            'ddddis', [round($fill, 1), $dist, $temp, $hum, $gas, $id]);
    }
    set_app_setting($conn, 'demo_enabled', '1');
    set_app_setting($conn, 'demo_last_tick', '0');
    demo_tick($conn, true);          // first live reading: predictions appear at once
}

// Show / hide the demo bins (hidden = inactive: off the dashboard, no readings)
function demo_set_enabled(mysqli $conn, bool $on): void
{
    if ($on && !db_one($conn, 'SELECT id FROM bins WHERE is_demo = 1 LIMIT 1')) {
        demo_reset($conn);           // first time: create them
        return;
    }
    db_exec($conn, 'UPDATE bins SET active = ? WHERE is_demo = 1', 'i', [$on ? 1 : 0]);
    set_app_setting($conn, 'demo_enabled', $on ? '1' : '0');
}

// ---------------------------------------------------------------------
// One step of the simulation: a new reading for every demo bin.
// Called after the dashboard's polls; does nothing if the last tick was
// less than DEMO_TICK_S ago (or another request is already doing it).
// ---------------------------------------------------------------------
function demo_tick(mysqli $conn, bool $force = false): void
{
    if (!demo_enabled($conn)) return;
    if (!$force && time() - (int)app_setting($conn, 'demo_last_tick', '0') < DEMO_TICK_S) return;

    $lock = db_one($conn, "SELECT GET_LOCK('smartbin_demo', 0) AS got");
    if ((int)($lock['got'] ?? 0) !== 1) return;          // another request is ticking
    try {
        if (!$force && time() - (int)app_setting($conn, 'demo_last_tick', '0') < DEMO_TICK_S) return;
        set_app_setting($conn, 'demo_last_tick', (string)time());

        $speed  = demo_speed($conn);
        $hour   = demo_sim_hour($speed);
        $url    = 'http://127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? 80) . app_url('api/ingest.php');
        $bins   = db_all($conn,
            'SELECT *, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS ago FROM bins WHERE is_demo = 1 AND active = 1');

        foreach ($bins as $b) {
            $def = demo_def($b['bin_id']);
            if ($def === null) continue;
            [, , , , , $depth, $rate, , $n] = $def;

            // the Simulator page's virtual bin is driving this bin right now: leave it alone
            if (strpos((string)$b['last_fw'], 'sim') === 0 && (int)$b['ago'] < 30) continue;

            $dt_real = min(DEMO_MAX_GAP, max(1, (int)($b['ago'] ?? DEMO_TICK_S)));
            $sim_h   = $dt_real * $speed / 3600;
            $fill    = (float)($b['fill_pct'] ?? 5);
            $th      = get_thresholds($conn, $b['bin_id']);

            if ($fill >= $th['full_pct'] && mt_rand(0, 1000) / 1000 < $sim_h / 1.5) {
                $fill = mt_rand(10, 40) / 10;              // collected (on average 1.5 h after full)
            } else {
                $fill = min(100, $fill + $rate * demo_activity($hour) * $sim_h + mt_rand(-3, 3) / 10);
            }
            $fill = max(0.5, $fill);
            [$temp, $hum] = demo_weather($hour, $n);

            $body = json_encode([
                'bin_id'      => $b['bin_id'],
                'token'       => $b['device_token'],
                'distance_cm' => round($depth * (1 - $fill / 100) + mt_rand(-2, 2) / 10, 1),
                'gas_raw'     => 360 + $n * 15 + mt_rand(-25, 25),
                'flame'       => false,
                'temp_c'      => $temp,
                'humidity'    => $hum,
                'state'       => 'NORMAL',
                'fan'         => false,
                'buzzer'      => false,
                'uptime_s'    => 86400 + time() % 86400,
                'rssi'        => -55 - mt_rand(0, 15),
                'fw'          => 'demo',
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } finally {
        db_one($conn, "SELECT RELEASE_LOCK('smartbin_demo') AS r");
    }
}

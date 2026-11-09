<?php
// =====================================================================
// Database connection + small shared helpers.
// Every page does:  require __DIR__ . '/../lib/db.php';   (or lib/db.php)
// =====================================================================

$CONFIG_FILE = __DIR__ . '/../config.php';
if (!file_exists($CONFIG_FILE)) {
    http_response_code(500);
    die('config.php is missing. Copy config.sample.php to config.php and edit it.');
}
$CONFIG = require $CONFIG_FILE;

date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Muscat');

// Throw exceptions on SQL errors (so we never silently lose a reading)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($CONFIG['db']['host'], $CONFIG['db']['user'],
                       $CONFIG['db']['pass'], $CONFIG['db']['name']);
    $conn->set_charset('utf8mb4');
    // MySQL NOW() uses the same clock and time zone as PHP
    $tz = $CONFIG['utc_offset'] ?? '+04:00';
    $stmt = $conn->prepare('SET time_zone = ?');
    $stmt->bind_param('s', $tz);
    $stmt->execute();
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Database connection failed. Is MySQL running in XAMPP?');
}

// ---------------------------------------------------------------------
// Run a prepared SELECT and return all rows as arrays.
//   db_all($conn, 'SELECT * FROM bins WHERE bin_id = ?', 's', [$id])
// ---------------------------------------------------------------------
function db_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// Same as db_all but returns only the first row (or null)
function db_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

// Run an INSERT / UPDATE / DELETE. Returns the number of affected rows.
function db_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->affected_rows;
}

// ---------------------------------------------------------------------
// Thresholds for one bin: the bin's own row overrides the DEFAULT row,
// column by column (a NULL in the bin row means "use the default").
// ---------------------------------------------------------------------
function get_thresholds(mysqli $conn, string $bin_id): array
{
    $row = db_one($conn,
        "SELECT
            COALESCE(b.gas_raw,            d.gas_raw)            AS gas_raw,
            COALESCE(b.temp_limit_c,       d.temp_limit_c)       AS temp_limit_c,
            COALESCE(b.temp_rise_c,        d.temp_rise_c)        AS temp_rise_c,
            COALESCE(b.temp_rise_window_s, d.temp_rise_window_s) AS temp_rise_window_s,
            COALESCE(b.nearly_full_pct,    d.nearly_full_pct)    AS nearly_full_pct,
            COALESCE(b.full_pct,           d.full_pct)           AS full_pct,
            COALESCE(b.upload_interval_s,  d.upload_interval_s)  AS upload_interval_s
         FROM settings d
         LEFT JOIN settings b ON b.bin_id = ?
         WHERE d.bin_id = 'DEFAULT'",
        's', [$bin_id]);

    // Safe fallbacks if the DEFAULT row was deleted by mistake
    $row = $row ?? [];
    return [
        'gas_raw'            => (int)  ($row['gas_raw']            ?? 1800),
        'temp_limit_c'       => (float)($row['temp_limit_c']       ?? 60),
        'temp_rise_c'        => (float)($row['temp_rise_c']        ?? 5),
        'temp_rise_window_s' => (int)  ($row['temp_rise_window_s'] ?? 60),
        'nearly_full_pct'    => (int)  ($row['nearly_full_pct']    ?? 70),
        'full_pct'           => (int)  ($row['full_pct']           ?? 90),
        'upload_interval_s'  => (int)  ($row['upload_interval_s']  ?? 10),
    ];
}

// Escape text for HTML output. Use it for EVERY value printed into a page.
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Send a JSON reply and stop
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

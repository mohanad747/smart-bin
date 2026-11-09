<?php
// =====================================================================
// Test mode: the operator makes the REAL bin act as if it saw a fire
// (buzzer ON) or a gas leak (fan ON), from the Simulator page.
// The command is stored on the bin row (test_mode / test_until) and the
// ESP32 picks it up from the ingest reply or from api/device_poll.php.
// =====================================================================

const TEST_MIN_S = 5;
const TEST_MAX_S = 120;

// The running test of a bin, or null. Expired tests are cleared here.
//   ['mode' => 'FIRE'|'VAPOUR', 'seconds_left' => 23, 'by' => 'admin']
function current_test(mysqli $conn, string $bin_id): ?array
{
    $row = db_one($conn,
        'SELECT test_mode, test_by, TIMESTAMPDIFF(SECOND, NOW(), test_until) AS left_s
         FROM bins WHERE bin_id = ?', 's', [$bin_id]);
    if ($row === null || $row['test_mode'] === null) {
        return null;
    }
    $left = (int)$row['left_s'];
    if ($left <= 0) {
        db_exec($conn, 'UPDATE bins SET test_mode = NULL, test_until = NULL, test_by = NULL
                        WHERE bin_id = ?', 's', [$bin_id]);
        return null;
    }
    return ['mode' => $row['test_mode'], 'seconds_left' => $left, 'by' => $row['test_by']];
}

// What the ESP32 gets in its reply: {"mode":"FIRE","seconds_left":23} or null
function test_for_device(?array $test): ?array
{
    return $test === null ? null : ['mode' => $test['mode'], 'seconds_left' => $test['seconds_left']];
}

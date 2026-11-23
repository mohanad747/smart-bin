<?php
// =====================================================================
// Commands from the dashboard to a real bin: open the lid for emptying,
// close it, re-arm the fire ball.
//   pending -> sent (the ESP32 picked it up) -> done / refused
//   not finished in CMD_EXPIRE_S -> expired
// A command stays available until the ESP32 confirms it, so a lost reply
// does not lose the command (the ESP32 never does the same id twice).
// =====================================================================

const CMD_EXPIRE_S = 120;
const CMD_TEXT = ['lid_open' => 'Open for emptying', 'lid_close' => 'Close lid', 'arm_ball' => 'Re-arm ball'];

function expire_commands(mysqli $conn, string $bin_id): void
{
    db_exec($conn,
        "UPDATE bin_commands SET status = 'expired'
         WHERE bin_id = ? AND status IN ('pending','sent') AND created_at < NOW() - INTERVAL ? SECOND",
        'si', [$bin_id, CMD_EXPIRE_S]);
}

// Oldest command the ESP32 still has to do: ['id' => 41, 'name' => 'lid_open'] or null
function next_command(mysqli $conn, string $bin_id): ?array
{
    expire_commands($conn, $bin_id);
    $c = db_one($conn,
        "SELECT id, name, status FROM bin_commands
         WHERE bin_id = ? AND status IN ('pending','sent') ORDER BY id LIMIT 1", 's', [$bin_id]);
    if ($c === null) {
        return null;
    }
    if ($c['status'] === 'pending') {
        db_exec($conn, "UPDATE bin_commands SET status = 'sent', sent_at = NOW() WHERE id = ?", 'i', [$c['id']]);
    }
    return ['id' => (int)$c['id'], 'name' => $c['name']];
}

// The ESP32 says it finished a command ("cmd_ack": 41, "cmd_result": "done" | "refused")
function ack_command(mysqli $conn, string $bin_id, array $in): void
{
    $id = $in['cmd_ack'] ?? null;
    if (!is_int($id)) {
        return;
    }
    $status = ($in['cmd_result'] ?? 'done') === 'refused' ? 'refused' : 'done';
    db_exec($conn,
        "UPDATE bin_commands SET status = ?, done_at = NOW(), sent_at = COALESCE(sent_at, NOW())
         WHERE id = ? AND bin_id = ? AND status IN ('pending','sent')",
        'sis', [$status, $id, $bin_id]);
}

// Who gave the latest such command in the last $within_s seconds (or null).
// A "close" only counts if it came after the last "open".
function command_by(mysqli $conn, string $bin_id, string $name, int $within_s): ?string
{
    $c = db_one($conn,
        "SELECT created_by FROM bin_commands
         WHERE bin_id = ? AND name = ? AND status IN ('sent','done') AND created_at >= NOW() - INTERVAL ? SECOND
           AND (name <> 'lid_close' OR id > (SELECT COALESCE(MAX(o.id), 0) FROM bin_commands o
                                             WHERE o.bin_id = ? AND o.name = 'lid_open' AND o.status IN ('sent','done')))
         ORDER BY id DESC LIMIT 1", 'ssis', [$bin_id, $name, $within_s, $bin_id]);
    return $c['created_by'] ?? null;
}

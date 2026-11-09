<?php
// =====================================================================
// POST /smart_bin/api/ack.php   (event_id, csrf)
// The operator acknowledges a FIRE alert: we record who and when.
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'use POST'], 405);
}
csrf_check();

$event_id = (int)($_POST['event_id'] ?? 0);
$changed = db_exec($conn,
    "UPDATE events SET acknowledged_by = ?, acknowledged_at = NOW()
     WHERE id = ? AND type = 'FIRE' AND acknowledged_at IS NULL",
    'si', [current_user(), $event_id]);

json_out(['ok' => $changed > 0]);

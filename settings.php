<?php
// =====================================================================
// Settings - DFD process 7.0 "Manage bins and settings".
//   * ESP32 connection info (laptop LAN IP + exact ingest URL)
//   * add / edit bins, generate a new device token
//   * thresholds: global defaults + per-bin overrides (FR10)
//   * alert channels + "Send test alert"
//   * change password
// Every form is POST + CSRF token, then redirect back (no double submit).
// =====================================================================

require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/alerts.php';
require_login();

// ---------------------------------------------------------------------
// Form helpers
// ---------------------------------------------------------------------
class FormError extends Exception {}

// Read a number from the form; blank -> null if $blank_ok
function form_num(string $name, float $min, float $max, bool $blank_ok, string $label): ?float
{
    $v = trim((string)($_POST[$name] ?? ''));
    if ($v === '') {
        if ($blank_ok) return null;
        throw new FormError("$label is required.");
    }
    if (!is_numeric($v) || $v < $min || $v > $max) {
        throw new FormError("$label must be a number from $min to $max.");
    }
    return (float)$v;
}

function form_text(string $name, int $max, bool $required, string $label): string
{
    $v = trim((string)($_POST[$name] ?? ''));
    if ($required && $v === '') throw new FormError("$label is required.");
    if (mb_strlen($v) > $max)   throw new FormError("$label is too long (max $max).");
    return $v;
}

function new_token(): string
{
    return bin2hex(random_bytes(16));     // 32 hex characters
}

// ---------------------------------------------------------------------
// Handle the POSTed form, then redirect
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $anchor = '';
    try {
        switch ($action) {

        case 'add_bin':
            $bin_id = strtoupper(form_text('bin_id', 20, true, 'Bin ID'));
            if (!preg_match('/^[A-Z0-9-]{2,20}$/', $bin_id)) {
                throw new FormError('Bin ID may only use letters, numbers and "-" (e.g. BIN-02).');
            }
            if (db_one($conn, 'SELECT id FROM bins WHERE bin_id = ?', 's', [$bin_id])) {
                throw new FormError("$bin_id already exists.");
            }
            $name  = form_text('name', 80, true, 'Name');
            $loc   = form_text('location_text', 160, false, 'Location');
            $lat   = form_num('lat', -90, 90, true, 'Latitude');
            $lng   = form_num('lng', -180, 180, true, 'Longitude');
            $depth = form_num('empty_depth_cm', 5, 400, false, 'Empty depth');
            $token = new_token();
            db_exec($conn,
                'INSERT INTO bins (bin_id, name, location_text, lat, lng, device_token, empty_depth_cm)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'sssddsd', [$bin_id, $name, $loc, $lat, $lng, $token, $depth]);
            db_exec($conn, 'INSERT IGNORE INTO settings (bin_id) VALUES (?)', 's', [$bin_id]);
            flash("$bin_id added. Put its token in the ESP32's secrets.h.");
            $anchor = '#bins';
            break;

        case 'save_bin':
            $bin_id = (string)($_POST['bin_id'] ?? '');
            $name   = form_text('name', 80, true, 'Name');
            $loc    = form_text('location_text', 160, false, 'Location');
            $lat    = form_num('lat', -90, 90, true, 'Latitude');
            $lng    = form_num('lng', -180, 180, true, 'Longitude');
            $depth  = form_num('empty_depth_cm', 5, 400, false, 'Empty depth');
            $act    = isset($_POST['active']) ? 1 : 0;
            db_exec($conn,
                'UPDATE bins SET name = ?, location_text = ?, lat = ?, lng = ?,
                                 empty_depth_cm = ?, active = ?
                 WHERE bin_id = ?',
                'ssdddis', [$name, $loc, $lat, $lng, $depth, $act, $bin_id]);
            flash("$bin_id saved.");
            $anchor = '#bins';
            break;

        case 'new_token':
            $bin_id = (string)($_POST['bin_id'] ?? '');
            db_exec($conn, 'UPDATE bins SET device_token = ? WHERE bin_id = ?', 'ss', [new_token(), $bin_id]);
            flash("New token for $bin_id. The ESP32 is rejected until you update its secrets.h.", 'err');
            $anchor = '#bins';
            break;

        case 'save_thresholds':
            $target = (string)($_POST['target'] ?? '');
            $is_default = ($target === 'DEFAULT');
            if (!$is_default && !db_one($conn, 'SELECT id FROM bins WHERE bin_id = ?', 's', [$target])) {
                throw new FormError('Unknown bin.');
            }
            $blank = !$is_default;     // a bin may leave a box empty = use the default
            $vals = [
                form_num('gas_raw', 100, 4095, $blank, 'Gas threshold'),
                form_num('temp_limit_c', 20, 80, $blank, 'Temperature limit'),
                form_num('temp_rise_c', 1, 30, $blank, 'Temperature rise'),
                form_num('temp_rise_window_s', 10, 600, $blank, 'Rise window'),
                form_num('nearly_full_pct', 10, 99, $blank, 'Nearly full'),
                form_num('full_pct', 10, 100, $blank, 'Full'),
                form_num('upload_interval_s', 2, 300, $blank, 'Upload interval'),
            ];
            db_exec($conn,
                'INSERT INTO settings (bin_id, gas_raw, temp_limit_c, temp_rise_c, temp_rise_window_s,
                                       nearly_full_pct, full_pct, upload_interval_s)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE gas_raw = VALUES(gas_raw), temp_limit_c = VALUES(temp_limit_c),
                    temp_rise_c = VALUES(temp_rise_c), temp_rise_window_s = VALUES(temp_rise_window_s),
                    nearly_full_pct = VALUES(nearly_full_pct), full_pct = VALUES(full_pct),
                    upload_interval_s = VALUES(upload_interval_s)',
                'sddddddd', array_merge([$target], $vals));
            // Nearly full must stay below full (check the values the bin really uses)
            $check = $is_default ? 'DEFAULT' : $target;
            $th = get_thresholds($conn, $check);
            if ($th['nearly_full_pct'] >= $th['full_pct']) {
                flash("Saved, but 'nearly full' ({$th['nearly_full_pct']}%) should be below 'full' ({$th['full_pct']}%).", 'err');
            } else {
                flash("Thresholds for $target saved. The bin gets them in its next upload reply.");
            }
            $anchor = '#thresholds';
            break;

        case 'test_alert':
            [$subject, $text] = ['Smart Bin test alert',
                "✅ Smart Bin test alert\nSent from the Settings page by " . current_user()
                . "\nTime: " . date('Y-m-d H:i:s')];
            $results = send_alert($conn, $CONFIG, 'TEST', null, null, $subject, $text);
            if (!$results) {
                flash('No alert channel is enabled in config.php.', 'err');
            } else {
                $parts = array_map(fn($r) => "{$r['channel']} to {$r['recipient']}: "
                                   . ($r['ok'] ? 'OK' : 'FAILED (' . $r['info'] . ')'), $results);
                flash(implode(' · ', $parts), any_success($results) ? 'ok' : 'err');
            }
            $anchor = '#alerts';
            break;

        case 'change_password':
            $user = db_one($conn, 'SELECT * FROM users WHERE username = ?', 's', [current_user()]);
            if (!$user || !password_verify((string)($_POST['current'] ?? ''), $user['password_hash'])) {
                throw new FormError('Current password is wrong.');
            }
            $new = (string)($_POST['new'] ?? '');
            if (strlen($new) < 8)                   throw new FormError('New password: at least 8 characters.');
            if ($new !== ($_POST['confirm'] ?? '')) throw new FormError('The two new passwords are different.');
            db_exec($conn, 'UPDATE users SET password_hash = ? WHERE id = ?', 'si',
                    [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('Password changed.');
            $anchor = '#account';
            break;

        default:
            throw new FormError('Unknown action.');
        }
    } catch (FormError $ex) {
        flash($ex->getMessage(), 'err');
    }
    header('Location: settings.php' . $anchor);
    exit;
}

// ---------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------
$bins     = db_all($conn, 'SELECT * FROM bins ORDER BY bin_id');
$settings = [];
foreach (db_all($conn, 'SELECT * FROM settings') as $s) {
    $settings[$s['bin_id']] = $s;
}
$alert_log = db_all($conn, 'SELECT * FROM alert_log ORDER BY id DESC LIMIT 15');

// Laptop LAN IPv4 addresses (the hotspot can change them)
$ips = [];
foreach ((array)gethostbynamel(gethostname()) as $ip) {
    if ($ip && strpos($ip, '127.') !== 0) $ips[] = $ip;
}
$server_ip = $_SERVER['SERVER_ADDR'] ?? '';
if ($server_ip && !in_array($server_ip, $ips, true) && !in_array($server_ip, ['127.0.0.1', '::1'], true)) {
    array_unshift($ips, $server_ip);
}
$app_path = app_url('api/ingest.php');

$email_cfg = $CONFIG['alerts']['email'] ?? [];
$wa_cfg    = $CONFIG['alerts']['whatsapp'] ?? [];

$flash      = flash();
$page_title = 'Smart Bin - Settings';
$active     = 'settings';
require __DIR__ . '/lib/header.php';

// One number box. $placeholder shows the default when a bin leaves it empty.
function num_box(string $name, string $label, $value, string $placeholder = '', string $step = 'any'): string
{
    return '<label>' . e($label) . '<input type="number" step="' . $step . '" name="' . e($name)
         . '" value="' . e($value ?? '') . '" placeholder="' . e($placeholder) . '"></label>';
}
?>
<main>
  <?php if ($flash): ?><div class="flash <?= e($flash['kind']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <!-- ============ ESP32 connection ============ -->
  <section class="panel" id="connect">
    <h2>ESP32 connection</h2>
    <p class="small muted">The phone hotspot can give the laptop a new IP. Check here and update
      <code>SERVER_URL</code> in the firmware's <code>secrets.h</code> if it changed.</p>
    <?php if (!$ips): ?>
      <p class="flash err">No LAN address found. Is the laptop connected to Wi-Fi / the hotspot?</p>
    <?php else: ?>
      <table>
        <tr><th>Laptop IP</th><th>Ingest URL for the ESP32</th></tr>
        <?php foreach ($ips as $ip): ?>
          <tr><td><code><?= e($ip) ?></code>
                <?= strpos($ip, '192.168.56.') === 0 ? '<span class="small muted">(VirtualBox adapter, not this one)</span>' : '' ?></td>
              <td><code>http://<?= e($ip . $app_path) ?></code></td></tr>
        <?php endforeach; ?>
      </table>
      <p class="small muted">Several IPs? Use the one on the same network as the ESP32
        (phone hotspots often use 192.168.x.x or 172.20.10.x).</p>
    <?php endif; ?>
  </section>

  <!-- ============ Bins ============ -->
  <section class="panel" id="bins">
    <h2>Bins</h2>
    <?php foreach ($bins as $b): ?>
      <details>
        <summary><?= e($b['bin_id']) ?>: <?= e($b['name']) ?>
          <?= $b['active'] ? '' : '<span class="chip off">inactive</span>' ?></summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_bin">
          <input type="hidden" name="bin_id" value="<?= e($b['bin_id']) ?>">
          <div class="row">
            <label>Name<input name="name" required maxlength="80" value="<?= e($b['name']) ?>"></label>
            <label>Location<input name="location_text" maxlength="160" value="<?= e($b['location_text']) ?>"></label>
          </div>
          <div class="row">
            <?= num_box('lat', 'Latitude', $b['lat']) ?>
            <?= num_box('lng', 'Longitude', $b['lng']) ?>
            <?= num_box('empty_depth_cm', 'Empty depth (cm): sensor to bottom', $b['empty_depth_cm'], '', '0.1') ?>
          </div>
          <label><input type="checkbox" name="active" <?= $b['active'] ? 'checked' : '' ?>>Active (shown on the dashboard, accepts readings)</label>
          <button class="btn primary">Save bin</button>
        </form>
        <hr style="border:0;border-top:1px solid var(--line);margin:14px 0">
        <div>Device token: <code><?= e($b['device_token']) ?></code></div>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('Make a new token? The ESP32 will be rejected until you update its secrets.h.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="new_token">
          <input type="hidden" name="bin_id" value="<?= e($b['bin_id']) ?>">
          <button class="btn small danger">Generate new token</button>
        </form>
      </details>
    <?php endforeach; ?>

    <details>
      <summary>+ Add a bin</summary>
      <form method="post" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_bin">
        <div class="row">
          <label>Bin ID<input name="bin_id" required maxlength="20" placeholder="BIN-02" pattern="[A-Za-z0-9-]{2,20}"></label>
          <label>Name<input name="name" required maxlength="80"></label>
          <label>Location<input name="location_text" maxlength="160"></label>
        </div>
        <div class="row">
          <?= num_box('lat', 'Latitude', '', '23.5778') ?>
          <?= num_box('lng', 'Longitude', '', '58.4300') ?>
          <?= num_box('empty_depth_cm', 'Empty depth (cm)', '40', '', '0.1') ?>
        </div>
        <p class="small muted">Tip: right-click a spot in Google Maps to copy its latitude, longitude.
          A device token is generated for you.</p>
        <button class="btn primary">Add bin</button>
      </form>
    </details>
  </section>

  <!-- ============ Thresholds ============ -->
  <section class="panel" id="thresholds">
    <h2>Thresholds</h2>
    <p class="small muted">The defaults apply to every bin. A bin row overrides only the boxes you fill in;
      leave a box empty to use the default. The bin receives the new values in its next upload reply.</p>
    <?php
    $def  = $settings['DEFAULT'] ?? [];
    $rows = array_merge([['bin_id' => 'DEFAULT', 'name' => 'Defaults for all bins']], $bins);
    foreach ($rows as $b):
        $id = $b['bin_id'];
        $s  = $settings[$id] ?? [];
        $is_def = ($id === 'DEFAULT');
        $ph = fn($k) => $is_def ? '' : (string)($def[$k] ?? '');
    ?>
      <details <?= $is_def ? 'open' : '' ?>>
        <summary><?= e($is_def ? 'Defaults (all bins)' : $id . ': ' . $b['name']) ?></summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_thresholds">
          <input type="hidden" name="target" value="<?= e($id) ?>">
          <div class="row">
            <?= num_box('gas_raw', 'Gas (MQ-2 raw, 0-4095)', $s['gas_raw'] ?? '', $ph('gas_raw'), '1') ?>
            <?= num_box('temp_limit_c', 'Temperature limit (°C)', $s['temp_limit_c'] ?? '', $ph('temp_limit_c'), '0.1') ?>
            <?= num_box('temp_rise_c', 'Sudden rise (°C)', $s['temp_rise_c'] ?? '', $ph('temp_rise_c'), '0.1') ?>
            <?= num_box('temp_rise_window_s', '... within (seconds)', $s['temp_rise_window_s'] ?? '', $ph('temp_rise_window_s'), '1') ?>
          </div>
          <div class="row">
            <?= num_box('nearly_full_pct', 'Nearly full (%), amber', $s['nearly_full_pct'] ?? '', $ph('nearly_full_pct'), '1') ?>
            <?= num_box('full_pct', 'Full (%), red', $s['full_pct'] ?? '', $ph('full_pct'), '1') ?>
            <?= num_box('upload_interval_s', 'Upload interval (s)', $s['upload_interval_s'] ?? '', $ph('upload_interval_s'), '1') ?>
          </div>
          <button class="btn primary">Save thresholds</button>
        </form>
      </details>
    <?php endforeach; ?>
  </section>

  <!-- ============ Alerts ============ -->
  <section class="panel" id="alerts">
    <h2>Urgent alerts</h2>
    <p class="small muted">Channels are set in <code>config.php</code>. One alert is sent per FIRE event.
      Vapour is shown on the dashboard only.
      "Bin full" messages: <b><?= !empty($CONFIG['alerts']['notify_full']) ? 'on' : 'off' ?></b>.</p>
    <table>
      <tr><th>Channel</th><th>Status</th><th>Recipients</th></tr>
      <tr><td>Email (Gmail SMTP)</td>
          <td><?= !empty($email_cfg['enabled']) ? '<span class="chip ok">enabled</span>' : '<span class="chip off">off</span>' ?></td>
          <td><?= e(implode(', ', (array)($email_cfg['to'] ?? []))) ?></td></tr>
      <tr><td>WhatsApp (CallMeBot)</td>
          <td><?= !empty($wa_cfg['enabled']) ? '<span class="chip ok">enabled</span>' : '<span class="chip off">off</span>' ?></td>
          <td><?= e(implode(', ', array_map(fn($r) => $r['phone'], (array)($wa_cfg['recipients'] ?? [])))) ?></td></tr>
    </table>
    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test_alert">
      <button class="btn warn">Send test alert</button>
      <span class="small muted">Sends a test message on every enabled channel (may take a few seconds).</span>
    </form>

    <h3 style="margin-top:18px" class="small muted">Last alert attempts</h3>
    <div class="table-wrap"><table>
      <tr><th>Time</th><th>Kind</th><th>Bin</th><th>Channel</th><th>To</th><th>Result</th></tr>
      <?php if (!$alert_log): ?><tr class="empty"><td colspan="6">No alerts sent yet</td></tr><?php endif; ?>
      <?php foreach ($alert_log as $a): ?>
        <tr><td><?= e($a['created_at']) ?></td><td><?= e($a['kind']) ?></td><td><?= e($a['bin_id'] ?? '–') ?></td>
            <td><?= e($a['channel']) ?></td><td><?= e($a['recipient']) ?></td>
            <td><?= $a['success'] ? '<span class="chip ok">OK</span>' : '<span class="chip FIRE">failed</span>' ?>
                <span class="small muted"><?= e($a['response']) ?></span></td></tr>
      <?php endforeach; ?>
    </table></div>
  </section>

  <!-- ============ Account ============ -->
  <section class="panel" id="account">
    <h2>Change password</h2>
    <form method="post" style="max-width:420px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <label>Current password<input type="password" name="current" required></label>
      <label>New password (8+ characters)<input type="password" name="new" required minlength="8"></label>
      <label>New password again<input type="password" name="confirm" required minlength="8"></label>
      <button class="btn primary">Change password</button>
    </form>
  </section>
</main>
</body>
</html>

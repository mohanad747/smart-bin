<?php
// =====================================================================
// Bin simulator (login required). Two parts:
//  1. Real bin test: makes the ESP32 act as if it saw a fire (buzzer ON)
//     or a gas leak (fan ON), so the circuit reacts too (api/test_cmd.php).
//  2. Virtual bin: sends fake readings from the browser to the REAL
//     api/ingest.php, exactly like the ESP32 would - no hardware needed.
// =====================================================================

require __DIR__ . '/../lib/auth.php';
require_login();

$bins = db_all($conn,
    'SELECT bin_id, name, device_token, empty_depth_cm, fill_pct FROM bins WHERE active = 1 ORDER BY bin_id');
$sim_bins = array_map(fn($b) => [
    'bin_id' => $b['bin_id'],
    'name'   => $b['name'],
    'token'  => $b['device_token'],
    'depth'  => (float)$b['empty_depth_cm'],
    'fill'   => $b['fill_pct'] === null ? 20.0 : (float)$b['fill_pct'],
], $bins);

$page_title = 'Smart Bin - Simulator';
$active     = 'sim';
require __DIR__ . '/../lib/header.php';
?>
<main>
  <?php if (!$sim_bins): ?>
  <section class="panel"><p class="flash err">No active bins. Add one on the Settings page first.</p></section>
  <?php else: ?>
  <section class="panel">
    <h2>Real bin test (drives the circuit)</h2>
    <p class="small muted">The ESP32 acts exactly as if its sensors saw a fire or a gas leak: the buzzer or fan
      switches on, the dashboard shows the hazard and the event is logged, all marked
      <span class="chip TEST">TEST</span>. Fire tests also send the alert, marked "TEST". The bin picks the
      command up within about 2 s and the test ends by itself. A real hazard always wins over a test.</p>
    <div class="row">
      <label>Bin
        <select id="tBin">
          <?php foreach ($sim_bins as $b): ?>
            <option value="<?= e($b['bin_id']) ?>"><?= e($b['bin_id'] . ' ' . $b['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Run for
        <select id="tSeconds">
          <option value="15">15 s</option><option value="30" selected>30 s</option><option value="60">60 s</option>
        </select></label>
    </div>
    <p>
      <button class="btn danger" data-test="FIRE">🔥 Test fire (buzzer ON)</button>
      <button class="btn warn" data-test="VAPOUR">⚠ Test gas leak (fan ON)</button>
      <button class="btn" data-test="STOP">■ Stop test</button>
    </p>
    <div id="tNote"></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Test</th><th>Bin state</th><th>Fan</th><th>Buzzer</th><th>Last reading</th></tr></thead>
      <tbody><tr id="tLive"><td colspan="5" class="muted">Loading…</td></tr></tbody>
    </table></div>
  </section>

  <section class="panel">
    <h2>Virtual bin (no hardware)</h2>
    <p class="small muted">Sends fake readings to <code>api/ingest.php</code> the same way the ESP32 does.
      Keep the dashboard open in another tab to watch it react.
      <b>Use a bin whose ESP32 is switched off</b>, or the fake and real readings mix.</p>
    <div class="row">
      <label>Bin
        <select id="bin">
          <?php foreach ($sim_bins as $i => $b): ?>
            <option value="<?= $i ?>"><?= e($b['bin_id'] . ' ' . $b['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>Send every
        <select id="every">
          <option value="2">2 s</option><option value="5" selected>5 s</option><option value="10">10 s</option>
        </select></label>
      <label>Filling speed (per reading)
        <select id="step">
          <option value="0.3">+0.3 %</option><option value="1" selected>+1 %</option><option value="3">+3 %</option>
        </select></label>
    </div>

    <h3 class="small muted">Scenario (keeps sending until you press Stop)</h3>
    <p>
      <button class="btn primary" data-mode="normal">Normal</button>
      <button class="btn primary" data-mode="filling">Filling up</button>
      <button class="btn warn" data-mode="gas">Gas leak (VAPOUR)</button>
      <button class="btn danger" data-mode="fire">Fire</button>
      <button class="btn" data-mode="fail">Sensor failure (nulls)</button>
      <button class="btn" id="stop">■ Stop</button>
    </p>
    <h3 class="small muted">One-shot</h3>
    <p>
      <button class="btn" data-once="emptied">Bin emptied</button>
      <button class="btn" data-once="badtoken">Wrong token (expect 401)</button>
      <button class="btn" data-once="badvalue">Bad value: 150 °C (expect 400)</button>
      <button class="btn" id="backfill">Add 1 h of filling history</button>
    </p>
    <p class="small muted">"Add 1 h of filling history" writes past readings straight into the database,
      so the time-to-full prediction appears right away instead of after 10 minutes.</p>
    <div id="backfillNote"></div>

    <div class="panel-head" style="margin-top:14px">
      <h3 style="margin:0">Now: <span id="modeName">stopped</span></h3>
      <span class="right small muted" id="simState"></span>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>Time</th><th>Sent</th><th>HTTP</th><th>Reply</th></tr></thead>
      <tbody id="log"><tr class="empty"><td colspan="4">Nothing sent yet</td></tr></tbody>
    </table></div>
  </section>
  <?php endif; ?>
</main>

<script>
// Bins come from PHP (json_encode with HEX flags = safe inside <script>)
const BINS = <?= json_encode($sim_bins, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
const INGEST = '../api/ingest.php';

let mode = null, timer = null, uptime = 3600;
// simulated sensor values per bin
const sim = BINS.map(b => ({ fill: b.fill, temp: 31.0, gas: 800 }));

function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function jitter(x) { return (Math.random() * 2 - 1) * x; }
function cur() { return Number(document.getElementById('bin').value); }

// Build one reading like the ESP32 would send
function reading(kind) {
  const b = BINS[cur()], s = sim[cur()];
  let flame = false, fan = false, buzzer = false, state = 'NORMAL';
  let temp = 31 + jitter(0.3), gas = Math.round(800 + jitter(30)), hum = 40 + jitter(1);

  if (kind === 'filling') s.fill = Math.min(100, s.fill + Number(document.getElementById('step').value));
  if (kind === 'gas')  { gas = Math.round(2200 + jitter(60)); state = 'VAPOUR'; fan = true; }
  if (kind === 'fire') { s.temp = Math.min(68, s.temp + 4); temp = s.temp; flame = true; buzzer = true; state = 'FIRE'; }
  else s.temp = 31;

  const fill = Math.max(0, s.fill + jitter(0.3));
  let distance = Math.round(b.depth * (1 - fill / 100) * 10) / 10;
  if (kind === 'fail') { distance = null; temp = null; hum = null; }

  uptime += Number(document.getElementById('every').value);
  return {
    bin_id: b.bin_id, token: b.token,
    distance_cm: distance,
    gas_raw: gas, flame,
    temp_c: temp === null ? null : Math.round(temp * 10) / 10,
    humidity: hum === null ? null : Math.round(hum * 10) / 10,
    state, fan, buzzer, uptime_s: uptime, rssi: -55 - Math.round(Math.random() * 10), fw: 'sim-1.0'
  };
}

async function send(body, label) {
  let code = 0, text = '';
  try {
    const res = await fetch(INGEST, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                                      body: JSON.stringify(body) });
    code = res.status; text = await res.text();
  } catch (e) { text = 'network error: ' + e.message; }
  const log = document.getElementById('log');
  if (log.querySelector('.empty')) log.innerHTML = '';
  const short = `${label}: fill ${body.distance_cm === null ? 'null' : sim[cur()].fill.toFixed(1) + '%'}, `
              + `temp ${body.temp_c}, gas ${body.gas_raw}, ${body.state}`;
  const color = code === 200 ? 'var(--ok)' : 'var(--fire)';
  log.insertAdjacentHTML('afterbegin', `<tr><td>${new Date().toLocaleTimeString()}</td>
    <td class="small">${esc(short)}</td><td style="color:${color};font-weight:700">${code || '-'}</td>
    <td class="small mono">${esc(text.slice(0, 200))}</td></tr>`);
  while (log.rows.length > 40) log.deleteRow(-1);
  document.getElementById('simState').textContent = `simulated fill ${sim[cur()].fill.toFixed(1)}%`;
}

function start(m) {
  mode = m;
  document.getElementById('modeName').textContent = m;
  clearInterval(timer);
  const tick = () => send(reading(mode), mode);
  tick();
  timer = setInterval(tick, Number(document.getElementById('every').value) * 1000);
}

document.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => start(b.dataset.mode));
document.getElementById('stop').onclick = () => {
  clearInterval(timer); mode = null;
  document.getElementById('modeName').textContent = 'stopped';
};
document.getElementById('every').onchange = () => { if (mode) start(mode); };

document.querySelectorAll('[data-once]').forEach(btn => btn.onclick = () => {
  const kind = btn.dataset.once;
  if (kind === 'emptied') {
    sim[cur()].fill = 5;
    send(reading('normal'), 'emptied');
  } else if (kind === 'badtoken') {
    const r = reading('normal'); r.token = 'wrong-token'; send(r, 'wrong token');
  } else if (kind === 'badvalue') {
    const r = reading('normal'); r.temp_c = 150; send(r, 'bad value');
  }
});

// ---------------------------------------------------------------------
// Real bin test: start / stop, and show what the bin is doing every 2 s
// ---------------------------------------------------------------------
function onOff(v) { return v === null ? '–' : (v ? '<span class="chip on">ON</span>' : 'off'); }

document.querySelectorAll('[data-test]').forEach(btn => btn.onclick = async () => {
  const note = document.getElementById('tNote');
  const body = new URLSearchParams({ csrf: CSRF, bin_id: document.getElementById('tBin').value,
                                     mode: btn.dataset.test, seconds: document.getElementById('tSeconds').value });
  try {
    const res = await fetch('../api/test_cmd.php', { method: 'POST', body });
    const text = await res.text();
    let j = null; try { j = JSON.parse(text); } catch (e) {}
    if (!j || !j.ok) throw new Error(j ? j.error : text.slice(0, 200));
    note.className = 'flash ok';
    note.textContent = j.test
      ? `${j.test.mode === 'FIRE' ? 'Fire' : 'Gas leak'} test sent: the bin reacts within about 2 s and stops after ${j.test.seconds_left} s.`
      : 'Test stopped: the bin goes back to normal within about 2 s.';
  } catch (e) {
    note.className = 'flash err';
    note.textContent = 'Could not send the test: ' + e.message;
  }
  liveTest();
});

async function liveTest() {
  const row = document.getElementById('tLive');
  if (!row) return;
  try {
    const res = await fetch('../api/status.php', { cache: 'no-store' });
    const j = await res.json();
    const b = j.bins.find(x => x.bin_id === document.getElementById('tBin').value);
    if (!b) { row.innerHTML = '<td colspan="5" class="muted">Bin not found</td>'; return; }
    const test = b.test
      ? `<span class="chip TEST">TEST</span> ${b.test.mode === 'FIRE' ? 'fire' : 'gas leak'}, ${b.test.seconds_left} s left`
      : '<span class="muted">none</span>';
    row.innerHTML = `<td>${test}</td><td><span class="chip ${esc(b.state)}">${esc(b.state)}</span></td>
      <td>${onOff(b.fan)}</td><td>${onOff(b.buzzer)}</td>
      <td>${b.offline ? '<span class="chip OFFLINE">Offline</span> ' : ''}${esc(b.seconds_ago === null ? 'never' : b.seconds_ago + ' s ago')}</td>`;
  } catch (e) {
    row.innerHTML = '<td colspan="5" class="muted">Server not reachable</td>';
  }
}
if (document.getElementById('tLive')) {
  document.getElementById('tBin').onchange = liveTest;
  liveTest();
  setInterval(liveTest, 2000);
}

document.getElementById('backfill').onclick = async () => {
  const b = BINS[cur()];
  const body = new URLSearchParams({ csrf: CSRF, bin_id: b.bin_id, fill_now: sim[cur()].fill.toFixed(1) });
  const res = await fetch('sim_backfill.php', { method: 'POST', body });
  const j = await res.json();
  const note = document.getElementById('backfillNote');
  note.className = 'flash ' + (j.ok ? 'ok' : 'err');
  note.textContent = j.message || j.error;
  if (j.ok) send(reading(mode || 'normal'), 'after backfill');   // live reading recomputes the prediction
};
</script>
</body>
</html>

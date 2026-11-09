// =====================================================================
// Smart Bin dashboard - polls api/status.php every 5 s and redraws.
// No page reloads (user experience requirement 2).
// =====================================================================

const POLL_MS    = 5000;    // status refresh
const HISTORY_MS = 30000;   // chart refresh

let data = null;                                 // last reply of status.php
let selected = null;                             // bin_id shown in the charts
try { selected = localStorage.getItem('sb_selected'); } catch (e) {}
let map = null, markers = {}, mapFitted = false;
let fillChart = null, tempChart = null;
let lastOk = null;

// ---------- small helpers ----------
// Escape text before putting it into innerHTML (stops HTML injection)
function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function el(id) { return document.getElementById(id); }
function num(v, digits = 1) { return v === null || v === undefined ? '–' : Number(v).toFixed(digits); }

// "12 s ago", "5 min ago", "2 h 3 min ago"
function ago(sec) {
  if (sec === null || sec === undefined) return 'never';
  if (sec < 60) return sec + ' s ago';
  if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
  return duration(sec) + ' ago';
}
// 8100 -> "2 h 15 min"
function duration(sec) {
  sec = Math.max(0, Math.round(sec));
  const d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
  if (d > 0) return d + ' d ' + h + ' h';
  if (h > 0) return h + ' h ' + m + ' min';
  if (m > 0) return m + ' min';
  return sec + ' s';
}
function timeOnly(dt) { return dt ? dt.slice(11, 19) : ''; }

const LEVEL_COLOR = { ok: '#2e7d32', nearly: '#e08a00', full: '#c62828', unknown: '#7b8794' };

// Purple "TEST" chip for events / bins driven from the Simulator page
const TEST_CHIP = '<span class="chip TEST">TEST</span>';
function testChip(e) { return Number(e.is_test) ? ' ' + TEST_CHIP : ''; }

// Text for the priority list
function etaText(b) {
  switch (b.prediction_status) {
    case 'FULL':        return 'Full now: collect';
    case 'FILLING':     return 'Full in ~' + duration(b.seconds_to_full);
    case 'NOT_FILLING': return 'Not filling';
    default:            return 'Not enough data';
  }
}

// ---------- hazard banner ----------
function drawBanner() {
  const box = el('banner');
  const hz = data.hazards;
  const offline = data.bins.filter(b => b.offline);
  if (hz.length === 0) {
    let extra = offline.length ? ` · ${offline.length} bin(s) offline: ${offline.map(b => esc(b.bin_id)).join(', ')}` : '';
    box.innerHTML = `<div class="banner calm"><span class="icon">✓</span><span class="text"><b>All bins normal</b>${extra}</span></div>`;
    document.title = 'Smart Bin - Dashboard';
    return;
  }
  let html = '';
  let anyFire = false;
  for (const h of hz) {
    const where = `${esc(h.bin_id)}: ${esc(h.name)}, ${esc(h.location_text)}`;
    if (h.type === 'FIRE') {
      anyFire = true;
      const acked = h.acknowledged_at !== null;
      const ended = h.ended_at ? ` · fire ended at ${esc(timeOnly(h.ended_at))}` : ' · still burning';
      const ackPart = acked
        ? `<span>Acknowledged by <b>${esc(h.acknowledged_by)}</b> at ${esc(timeOnly(h.acknowledged_at))}</span>`
        : `<button class="btn white" data-ack="${h.id}">Acknowledge</button>`;
      html += `<div class="banner fire ${acked ? 'acked' : ''}">
        <span class="icon">🔥</span>
        <span class="text"><b>FIRE at ${where}</b>${testChip(h)}<br>Started ${esc(h.started_at)}${ended}
          · peak ${num(h.peak_temp_c)} °C</span>
        <span class="call">Call Civil Defence 9999</span>${ackPart}</div>`;
    } else {
      html += `<div class="banner vapour"><span class="icon">⚠</span>
        <span class="text"><b>Flammable vapour at ${where}</b>${testChip(h)}<br>Since ${esc(timeOnly(h.started_at))}
          · gas peak ${esc(h.peak_gas_raw)} · fan venting the bin</span></div>`;
    }
  }
  box.innerHTML = html;
  document.title = anyFire ? '🔥 FIRE - Smart Bin' : '⚠ Vapour - Smart Bin';
  box.querySelectorAll('[data-ack]').forEach(btn => btn.onclick = () => acknowledge(btn.dataset.ack));
}

async function acknowledge(id) {
  const body = new URLSearchParams({ event_id: id, csrf: data.csrf });
  await fetch('api/ack.php', { method: 'POST', body });
  poll();
}

// ---------- map (Leaflet) ----------
function initMap() {
  if (typeof L === 'undefined') {           // Leaflet file missing
    el('map').style.display = 'none';
    el('mapNote').textContent = 'Map could not load. The bins are listed below.';
    return;
  }
  map = L.map('map').setView([23.5880, 58.3829], 12);   // Muscat
  const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '© OpenStreetMap contributors'
  }).addTo(map);
  let tileErrors = 0;
  tiles.on('tileerror', () => {
    if (++tileErrors === 3) {
      el('mapNote').textContent = 'Map tiles need internet and are not loading. Markers still work, and every bin is in the list and cards.';
    }
  });
  tiles.on('tileload', () => { if (tileErrors) { tileErrors = 0; el('mapNote').textContent = ''; } });
}

function pinIcon(b) {
  let cls = b.level, label = b.fill_pct === null ? '?' : Math.round(b.fill_pct) + '%';
  if (b.state === 'FIRE') { cls = 'fire'; label = '🔥'; }
  else if (b.state === 'VAPOUR') cls += ' vapour';
  return L.divIcon({ className: '', html: `<div class="pin ${cls}">${esc(label)}</div>`,
                     iconSize: [34, 34], iconAnchor: [17, 17] });
}

function drawMap() {
  if (!map) return;
  const seen = {};
  const points = [];
  for (const b of data.bins) {
    if (b.lat === null || b.lng === null) continue;
    seen[b.bin_id] = true;
    points.push([b.lat, b.lng]);
    const tip = `<b>${esc(b.bin_id)}</b> ${esc(b.name)}<br>${num(b.fill_pct, 0)}% · ${esc(b.state)}`;
    if (!markers[b.bin_id]) {
      markers[b.bin_id] = L.marker([b.lat, b.lng]).addTo(map)
        .on('click', () => select(b.bin_id, true));
    }
    markers[b.bin_id].setLatLng([b.lat, b.lng]).setIcon(pinIcon(b)).bindTooltip(tip);
  }
  for (const id in markers) {               // bin removed or deactivated
    if (!seen[id]) { map.removeLayer(markers[id]); delete markers[id]; }
  }
  if (!mapFitted && points.length) {
    points.length === 1 ? map.setView(points[0], 16) : map.fitBounds(points, { padding: [40, 40] });
    mapFitted = true;
  }
}

// ---------- priority list ----------
function drawPriority() {
  const byId = Object.fromEntries(data.bins.map(b => [b.bin_id, b]));
  const list = el('priority');
  if (data.priority.length === 0) { list.innerHTML = '<li class="muted">No active bins</li>'; return; }
  list.innerHTML = data.priority.map((id, i) => {
    const b = byId[id];
    return `<li data-bin="${esc(id)}" class="${id === selected ? 'sel' : ''}">
      <span class="rank" style="background:${LEVEL_COLOR[b.level]}">${i + 1}</span>
      <span class="info"><b>${esc(b.bin_id)}</b> <span class="small muted">${esc(b.name)}</span><br>
        <span class="eta">${esc(etaText(b))}</span>
        ${b.offline ? '<span class="chip OFFLINE">Offline</span>' : ''}</span>
      <span class="pct" style="color:${LEVEL_COLOR[b.level]}">${num(b.fill_pct, 0)}%</span></li>`;
  }).join('');
  list.querySelectorAll('li[data-bin]').forEach(li => li.onclick = () => select(li.dataset.bin, true));
}

// ---------- bin cards ----------
function onOff(v) { return v === null ? '–' : (v ? '<span class="chip on">ON</span>' : 'off'); }

function drawCards() {
  const box = el('cards');
  box.innerHTML = data.bins.map(b => {
    const color = LEVEL_COLOR[b.level];
    const stateCls = b.state === 'FIRE' ? 'fire' : b.state === 'VAPOUR' ? 'vapour' : '';
    const levelText = { ok: 'OK', nearly: 'Nearly full', full: 'Full', unknown: 'No fill data' }[b.level];
    return `<div class="card ${stateCls} ${b.bin_id === selected ? 'sel' : ''}" id="card-${esc(b.bin_id)}" data-bin="${esc(b.bin_id)}">
      <div class="head">
        <span class="id">${esc(b.bin_id)}</span><span class="name">${esc(b.name)}</span>
        <span class="chip ${esc(b.state)}">${esc(b.state)}</span>
        ${b.offline ? '<span class="chip OFFLINE">Offline</span>' : ''}
        ${b.test ? `${TEST_CHIP} <span class="small muted">${b.test.mode === 'FIRE' ? 'fire' : 'gas'} test, ${b.test.seconds_left} s left</span>` : ''}
      </div>
      <div class="small muted">${esc(b.location_text)}</div>
      <div class="fillrow"><span class="fill" style="color:${color}">${num(b.fill_pct, 0)}%</span>
        <span class="chip ${esc(b.level)}">${levelText}</span>
        <span class="small muted" style="margin-left:auto">${esc(etaText(b))}</span></div>
      <div class="bar"><div style="width:${b.fill_pct ?? 0}%;background:${color}"></div></div>
      <div class="metrics">
        <div class="metric"><div class="k">Temperature</div><div class="v">${num(b.temp_c)} °C</div></div>
        <div class="metric"><div class="k">Humidity</div><div class="v">${num(b.humidity, 0)} %</div></div>
        <div class="metric"><div class="k">Gas (raw)</div><div class="v">${b.gas_raw ?? '–'}
          <span class="small muted">/ ${b.thresholds.gas_raw}</span></div></div>
        <div class="metric"><div class="k">Fan</div><div class="v">${onOff(b.fan)}</div></div>
        <div class="metric"><div class="k">Buzzer</div><div class="v">${onOff(b.buzzer)}</div></div>
        <div class="metric"><div class="k">Last seen</div><div class="v">${esc(ago(b.seconds_ago))}</div></div>
      </div>
      <div class="small muted" style="margin-top:8px">
        Fill rate: ${b.fill_rate === null ? '–' : num(b.fill_rate, 1) + ' %/h'}
        · Wi-Fi ${b.rssi ?? '–'} dBm · fw ${esc(b.fw ?? '–')}</div>
    </div>`;
  }).join('') || '<p class="muted">No active bins. Add one on the Settings page.</p>';
  box.querySelectorAll('.card').forEach(c => c.onclick = () => select(c.dataset.bin, false));
}

// ---------- event log ----------
function alertText(e) {
  if (e.type !== 'FIRE') return '–';
  if (Number(e.alert_sent)) return '<span class="chip ok">Sent</span>';
  if (Number(e.alert_attempts) > 0) return `<span class="chip FIRE">Failed</span> <span class="small muted">${e.alert_attempts} tries</span>`;
  return '<span class="small muted">No channel on</span>';
}

function drawEvents() {
  const body = el('events');
  if (data.events.length === 0) { body.innerHTML = '<tr class="empty"><td colspan="10">No events yet</td></tr>'; return; }
  body.innerHTML = data.events.map(e => `<tr>
    <td><span class="chip ${esc(e.type)}">${esc(e.type)}</span>${testChip(e)}</td>
    <td>${esc(e.bin_id)}</td>
    <td>${esc(e.started_at)}</td>
    <td>${e.ended_at ? esc(e.ended_at) : '<b>ongoing</b>'}</td>
    <td>${e.type === 'EMPTIED' ? '–' : esc(duration(Number(e.duration_s)))}</td>
    <td>${e.peak_temp_c === null ? '–' : num(e.peak_temp_c) + ' °C'}</td>
    <td>${e.peak_gas_raw ?? '–'}</td>
    <td>${alertText(e)}</td>
    <td>${e.acknowledged_by ? esc(e.acknowledged_by) + '<br><span class="small muted">' + esc(e.acknowledged_at) + '</span>' : '–'}</td>
    <td class="small">${esc(e.note ?? '')}</td></tr>`).join('');
}

function fillFilter() {
  const sel = el('eventFilter');
  const have = new Set([...sel.options].map(o => o.value));
  for (const b of data.bins) {
    if (!have.has(b.bin_id)) sel.add(new Option(b.bin_id + ' ' + b.name, b.bin_id));
  }
}

// ---------- charts ----------
function makeChart(canvas, label, color, yMin, yMax) {
  return new Chart(canvas, {
    type: 'line',
    data: { labels: [], datasets: [{ label, data: [], borderColor: color, backgroundColor: color + '22',
            fill: true, tension: .25, pointRadius: 0, spanGaps: true }] },
    options: { animation: false, maintainAspectRatio: false, plugins: { legend: { display: false } },
               scales: { y: { min: yMin, max: yMax }, x: { ticks: { maxTicksLimit: 8 } } } }
  });
}

async function loadHistory() {
  if (!selected || typeof Chart === 'undefined') return;
  try {
    const res = await fetch('api/history.php?hours=6&bin_id=' + encodeURIComponent(selected));
    const h = await res.json();
    if (!h.ok) return;
    const labels = h.points.map(p => p.t.slice(11));
    fillChart.data.labels = labels; fillChart.data.datasets[0].data = h.points.map(p => p.fill);
    tempChart.data.labels = labels; tempChart.data.datasets[0].data = h.points.map(p => p.temp);
    fillChart.update(); tempChart.update();
  } catch (e) { /* next try in 30 s */ }
}

// ---------- selection ----------
function select(binId, scroll) {
  selected = binId;
  try { localStorage.setItem('sb_selected', binId); } catch (e) {}
  el('chartBin').textContent = binId;
  if (data) { drawCards(); drawPriority(); }
  if (scroll) {
    const card = document.getElementById('card-' + binId);
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  loadHistory();
}

// ---------- polling ----------
async function poll() {
  const line = el('statusLine');
  try {
    const f = el('eventFilter').value;
    const res = await fetch('api/status.php' + (f ? '?events_bin=' + encodeURIComponent(f) : ''),
                            { cache: 'no-store' });
    if (res.status === 401) { location.href = 'login.php'; return; }
    data = await res.json();
    lastOk = Date.now();
    if (!selected || !data.bins.some(b => b.bin_id === selected)) {
      if (data.bins.length) select(data.bins[0].bin_id, false);
    }
    drawBanner(); drawMap(); drawPriority(); drawCards(); drawEvents(); fillFilter();
    el('chartBin').textContent = selected ?? '-';
    line.className = 'right status-line';
    line.textContent = 'Live · updated ' + data.now.slice(11) + ' · every 5 s';
  } catch (e) {
    line.className = 'right status-line bad';
    line.textContent = 'Server not reachable' + (lastOk ? ' · last update ' + ago(Math.round((Date.now() - lastOk) / 1000)) : '');
  }
}

// ---------- start ----------
initMap();
if (typeof Chart !== 'undefined') {
  fillChart = makeChart(el('fillChart'), 'Fill %', '#1f7a8c', 0, 100);
  tempChart = makeChart(el('tempChart'), 'Temperature °C', '#d97706', undefined, undefined);
}
el('eventFilter').onchange = poll;
poll();
setInterval(poll, POLL_MS);
setInterval(loadHistory, HISTORY_MS);

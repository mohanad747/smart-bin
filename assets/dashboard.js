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

// Grey "DEMO" chip for example bins (not real)
const DEMO_CHIP = '<span class="chip DEMO" title="Example bin, not real">DEMO</span>';
function demoChip(x) { return Number(x.is_demo) ? ' ' + DEMO_CHIP : ''; }

// Text for the priority list
function etaText(b) {
  switch (b.prediction_status) {
    case 'FULL':        return 'Full now: collect';
    case 'FILLING':     return 'Full in ~' + duration(b.seconds_to_full * (b.time_scale || 1));
    case 'NOT_FILLING': return 'Not filling';
    default:            return 'Not enough data';
  }
}

// ---------- status line under the menu ----------
// Hazards are shown by assets/alerts.js on every page (sticky bar + pop-up),
// so here only the calm "All bins normal" line is drawn when nothing is wrong.
function drawBanner() {
  const box = el('banner');
  if (data.hazards.length) { box.innerHTML = ''; return; }
  const offline = data.bins.filter(b => b.offline);
  const extra = offline.length ? ` · ${offline.length} bin(s) offline: ${offline.map(b => esc(b.bin_id)).join(', ')}` : '';
  box.innerHTML = `<div class="banner calm"><span class="icon">✓</span><span class="text"><b>All bins normal</b>${extra}</span></div>`;
}

// alerts.js calls this after an acknowledge, so the dashboard redraws at once
window.sbRefresh = () => poll();
// alerts.js "Show the bin": open the bin and show it on the map, no page reload
window.sbShowBin = binId => {
  select(binId, false);
  showOnMap(binId);
  const m = el('map');
  if (m) m.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

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
  if (b.is_demo) cls += ' demo';
  return L.divIcon({ className: '', html: `<div class="pin ${cls}">${esc(label)}</div>`,
                     iconSize: [34, 34], iconAnchor: [17, 17] });
}

function drawMap() {
  if (!map) return;
  map.invalidateSize();                   // the map panel follows the priority list's height
  const seen = {};
  const points = [];
  for (const b of data.bins) {
    if (b.lat === null || b.lng === null) continue;
    seen[b.bin_id] = true;
    points.push([b.lat, b.lng]);
    const tip = `<b>${esc(b.bin_id)}</b> ${esc(b.name)}${b.is_demo ? ' (demo, not real)' : ''}<br>${num(b.fill_pct, 0)}% · ${esc(b.state)}`;
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

// Centre the map on a bin and show its label (priority list click)
function showOnMap(binId) {
  const m = map && markers[binId];
  if (!m) return;
  map.panTo(m.getLatLng());
  m.openTooltip();
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
      <span class="info"><b>${esc(b.bin_id)}</b> <span class="small muted">${esc(b.name)}</span>${demoChip(b)}<br>
        <span class="eta">${esc(etaText(b))}</span>
        ${b.offline ? '<span class="chip OFFLINE">Offline</span>' : ''}</span>
      <span class="pct" style="color:${LEVEL_COLOR[b.level]}">${num(b.fill_pct, 0)}%</span></li>`;
  }).join('');
  list.querySelectorAll('li[data-bin]').forEach(li => li.onclick = () => {
    select(li.dataset.bin, false);          // stay here: show the bin on the map instead
    showOnMap(li.dataset.bin);
  });
}

// ---------- summary tiles ----------
function drawTiles() {
  const bins = data.bins, total = bins.length;
  const offline = bins.filter(b => b.offline);
  const full    = bins.filter(b => b.level === 'full');
  const nearly  = bins.filter(b => b.level === 'nearly');
  const fire    = bins.filter(b => b.state === 'FIRE');
  const vapour  = bins.filter(b => b.state === 'VAPOUR');
  const fills   = bins.filter(b => b.fill_pct !== null);
  const avg     = fills.length ? fills.reduce((s, b) => s + b.fill_pct, 0) / fills.length : null;
  const top     = fills.slice().sort((a, b) => b.fill_pct - a.fill_pct)[0];
  const ids     = list => list.map(b => esc(b.bin_id)).join(', ');

  const tile = (cls, label, value, sub) => `<div class="tile ${cls}">
      <div class="k">${label}</div><div class="v">${value}</div><div class="s">${sub}</div></div>`;

  const hazards = fire.length + vapour.length;
  const collect = full.length + nearly.length;
  const demos   = bins.filter(b => b.is_demo).length;
  el('tiles').innerHTML =
    tile(offline.length ? 'off' : 'ok', 'Bins online', `${total - offline.length}<span class="of"> / ${total}</span>`,
         (offline.length ? 'Offline: ' + ids(offline) : 'All bins reporting')
         + (demos ? ` · ${demos} of them demo` : '')) +
    tile(full.length ? 'full' : nearly.length ? 'nearly' : 'ok', 'Need collection', collect,
         collect ? [full.length ? full.length + ' full' : '', nearly.length ? nearly.length + ' nearly full' : '']
                     .filter(Boolean).join(', ')
                 : 'No bin is nearly full') +
    tile(fire.length ? 'fire' : vapour.length ? 'nearly' : 'ok', 'Active hazards', hazards,
         hazards ? [fire.length ? 'Fire: ' + ids(fire) : '', vapour.length ? 'Vapour: ' + ids(vapour) : '']
                     .filter(Boolean).join(' · ')
                 : 'No fire or vapour') +
    tile('info', 'Average fill', avg === null ? '–' : Math.round(avg) + '<span class="of"> %</span>',
         top ? `Highest: ${esc(top.bin_id)} at ${Math.round(top.fill_pct)}%` : 'No fill data yet');
}

// ---------- bin cards ----------
function onOff(v) { return v === null ? '–' : (v ? '<span class="chip on">ON</span>' : 'off'); }

// Small picture of a bin, filled to the fill %
function binPicture(b, n) {
  const pct = b.fill_pct === null ? 0 : Math.max(0, Math.min(100, b.fill_pct));
  const h = 72 * pct / 100;                       // inside height of the bin body is 72
  const body = 'M13 24 L67 24 L61 96 L19 96 Z';
  return `<svg class="binpic" viewBox="0 0 80 100" role="img" aria-label="Fill ${Math.round(pct)}%">
    <defs><clipPath id="binclip${n}"><path d="${body}"/></clipPath></defs>
    <path d="${body}" fill="#eef1f4"/>
    <rect x="0" y="${96 - h}" width="80" height="${h}" fill="${LEVEL_COLOR[b.level]}" clip-path="url(#binclip${n})"/>
    <path d="${body}" fill="none" stroke="#9aa5b1" stroke-width="2.5" stroke-linejoin="round"/>
    <rect x="8" y="14" width="64" height="8" rx="3" fill="#9aa5b1"/>
    <rect x="32" y="7" width="16" height="7" rx="3" fill="#9aa5b1"/>
  </svg>`;
}

// Gas reading against its threshold: the mark shows where VAPOUR starts
function gasGauge(b) {
  const limit = b.thresholds.gas_raw;
  const scale = Math.min(4095, limit * 1.5);
  const gas   = b.gas_raw;
  const width = gas === null ? 0 : Math.min(100, gas / scale * 100);
  const color = gas === null ? '#9aa5b1' : gas >= limit ? 'var(--fire)' : gas >= limit * 0.7 ? 'var(--nearly)' : 'var(--ok)';
  return `<div class="gauge">
      <div class="gauge-head"><span class="k">Gas (raw)</span>
        <span><b>${gas ?? '–'}</b> <span class="small muted">/ limit ${limit}</span></span></div>
      <div class="gauge-bar"><div style="width:${width}%;background:${color}"></div>
        <i style="left:${limit / scale * 100}%" title="Vapour threshold ${limit}"></i></div>
    </div>`;
}

// Wi-Fi strength as 4 bars (from the RSSI the bin reports)
function wifiBars(rssi) {
  const level = rssi === null ? 0 : rssi >= -55 ? 4 : rssi >= -65 ? 3 : rssi >= -75 ? 2 : 1;
  let bars = '';
  for (let i = 1; i <= 4; i++) bars += `<i class="${i <= level ? 'on' : ''}" style="height:${3 + i * 3}px"></i>`;
  return `<span class="wifi" title="${rssi === null ? 'No signal data' : rssi + ' dBm'}">${bars}</span>`;
}

// Closed bin: one compact row (id, name, small fill bar, state)
function binRow(b, stateCls) {
  const color = LEVEL_COLOR[b.level];
  return `<div class="card mini ${stateCls}" id="card-${esc(b.bin_id)}" data-bin="${esc(b.bin_id)}" title="Show ${esc(b.bin_id)}">
      <span class="id">${esc(b.bin_id)}</span>
      <span class="name">${esc(b.name)}</span>
      <span class="minibar"><i style="width:${b.fill_pct ?? 0}%;background:${color}"></i></span>
      <span class="pct" style="color:${color}">${num(b.fill_pct, 0)}%</span>
      <span class="chips">
        ${b.state !== 'NORMAL' ? `<span class="chip ${esc(b.state)}">${esc(b.state)}</span>` : ''}
        ${b.test ? TEST_CHIP : ''}
        ${b.offline ? '<span class="chip OFFLINE">Offline</span>' : ''}
        ${b.is_demo ? DEMO_CHIP : ''}
      </span>
      <span class="chev">▸</span>
    </div>`;
}

// Bins as an accordion: the selected bin is open (and shown in the charts),
// every other bin is a compact row. Click a row to open it.
function drawCards() {
  const box = el('cards');
  box.innerHTML = data.bins.map((b, n) => {
    const color = LEVEL_COLOR[b.level];
    const stateCls = b.state === 'FIRE' ? 'fire' : b.state === 'VAPOUR' ? 'vapour' : '';
    if (b.bin_id !== selected) return binRow(b, stateCls);
    const levelText = { ok: 'OK', nearly: 'Nearly full', full: 'Full', unknown: 'No fill data' }[b.level];
    return `<div class="card open ${stateCls}" id="card-${esc(b.bin_id)}" data-bin="${esc(b.bin_id)}">
      <div class="head">
        <span class="id">${esc(b.bin_id)}</span><span class="name">${esc(b.name)}</span>
        ${b.is_demo ? DEMO_CHIP : ''}
        <span class="chip ${esc(b.state)}">${esc(b.state)}</span>
        ${b.offline ? '<span class="chip OFFLINE">Offline</span>' : ''}
        ${b.test ? `${TEST_CHIP} <span class="small muted">${b.test.mode === 'FIRE' ? 'fire' : 'gas'} test, ${b.test.seconds_left} s left</span>` : ''}
      </div>
      <div class="small muted">${esc(b.location_text)}</div>
      <div class="cardbody">
        ${binPicture(b, n)}
        <div class="fillinfo">
          <div class="fill" style="color:${color}">${num(b.fill_pct, 0)}<span class="unit">%</span></div>
          <div><span class="chip ${esc(b.level)}">${levelText}</span></div>
          <div class="eta">${esc(etaText(b))}</div>
          <div class="small muted">Fill rate: ${b.fill_rate === null ? '–' : num(b.fill_rate / (b.time_scale || 1), 1) + ' %/h'}</div>
        </div>
      </div>
      ${gasGauge(b)}
      <div class="metrics">
        <div class="metric"><div class="k">Temperature</div><div class="v">${num(b.temp_c)} °C</div></div>
        <div class="metric"><div class="k">Humidity</div><div class="v">${num(b.humidity, 0)} %</div></div>
        <div class="metric"><div class="k">Wi-Fi</div><div class="v">${wifiBars(b.rssi)}</div></div>
        <div class="metric"><div class="k">Fan</div><div class="v">${onOff(b.fan)}</div></div>
        <div class="metric"><div class="k">Buzzer</div><div class="v">${onOff(b.buzzer)}</div></div>
        <div class="metric"><div class="k">Last seen</div><div class="v">${esc(ago(b.seconds_ago))}</div></div>
      </div>
      <div class="small muted" style="margin-top:8px">Firmware ${esc(b.fw ?? '–')}</div>
    </div>`;
  }).join('') || '<p class="muted">No active bins. Add one on the Settings page.</p>';
  box.querySelectorAll('.card.mini').forEach(c => c.onclick = () => select(c.dataset.bin, false));
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
    <td><span class="chip ${esc(e.type)}">${esc(e.type)}</span>${testChip(e)}${demoChip(e)}</td>
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
               // same y-axis width on both charts, so their plot areas and time axes line up exactly
               scales: { y: { min: yMin, max: yMax, afterFit: axis => { axis.width = 46; } },
                         x: { ticks: { maxTicksLimit: 8, maxRotation: 0 } } } }
  });
}

async function loadHistory() {
  if (!selected || typeof Chart === 'undefined') return;
  const bin = selected;
  try {
    const res = await fetch('api/history.php?hours=6&bin_id=' + encodeURIComponent(bin));
    const h = await res.json();
    if (!h.ok || bin !== selected) return;          // another bin was opened meanwhile
    const empty = !h.points.some(p => p.fill !== null || p.temp !== null);
    el('chartEmpty').textContent = empty ? `No readings from ${bin} in the last 6 hours` : '';
    el('chartEmpty').hidden = !empty;
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
  const selBin = data && data.bins.find(b => b.bin_id === binId);
  el('chartDemo').hidden = !(selBin && selBin.is_demo && selBin.time_scale > 1);
  if (data) { drawCards(); drawPriority(); }
  if (scroll) {
    const card = document.getElementById('card-' + binId);
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  loadHistory();
}

// A bin that has JUST gone into FIRE or VAPOUR opens by itself (fire first),
// so a hazard is never hidden in a closed row. Only on the change, so the
// operator can still open another bin while the hazard goes on.
let hazardBefore = null;                         // bin_id -> state at the last poll
function openNewHazard() {
  const now = {};
  for (const b of data.bins) now[b.bin_id] = b.state;
  const fresh = data.bins.filter(b => b.state !== 'NORMAL'
                                   && (hazardBefore === null || hazardBefore[b.bin_id] !== b.state));
  hazardBefore = now;
  if (!fresh.length) return;
  const pick = fresh.find(b => b.state === 'FIRE') || fresh[0];
  if (pick.bin_id !== selected) select(pick.bin_id, false);
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
    openNewHazard();
    drawBanner(); drawTiles(); drawPriority(); drawMap(); drawCards(); drawEvents(); fillFilter();
    el('chartBin').textContent = selected ?? '-';
    const selBin = data.bins.find(b => b.bin_id === selected);
    el('chartDemo').hidden = !(selBin && selBin.is_demo && selBin.time_scale > 1);
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
// index.php?bin=BIN-02 (from the alert pop-up's "Show the bin"): open that bin
const askedBin = new URLSearchParams(location.search).get('bin');
if (askedBin) {
  selected = askedBin;
  try { localStorage.setItem('sb_selected', askedBin); } catch (e) {}
  history.replaceState(null, '', location.pathname);
}
poll().then(loadHistory);   // charts at once, also when the bin was remembered from last time
setInterval(poll, POLL_MS);
setInterval(loadHistory, HISTORY_MS);

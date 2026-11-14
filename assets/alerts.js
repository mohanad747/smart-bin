// =====================================================================
// Smart Bin - hazard alerts on EVERY page (Dashboard, Settings, Simulator)
//
//  * sticky bar under the top menu that stays visible while scrolling
//    (red = FIRE, amber = gas vapour), with Acknowledge for fires
//  * pop-up window when a NEW hazard starts (a fire pop-up stays until
//    someone acknowledges it)
//  * alarm sound while a fire is not acknowledged (Mute button)
//  * the tab title flashes "FIRE"
// It asks api/alerts.php every 3 seconds.
// =====================================================================
(function () {
  const ME     = document.currentScript;
  const BASE   = ME.dataset.base || '/';
  const POLL_MS = 3000;
  const TITLE  = document.title;

  let csrf = '';
  let hazards = [];
  let shownPopups = loadSet('sb_popups');      // hazard ids that already had their pop-up
  let muted = false;
  try { muted = localStorage.getItem('sb_muted') === '1'; } catch (e) {}

  // ---------- small helpers ----------
  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function loadSet(key) {
    try { return new Set(JSON.parse(localStorage.getItem(key) || '[]')); } catch (e) { return new Set(); }
  }
  function saveSet(key, set) {
    try { localStorage.setItem(key, JSON.stringify([...set].slice(-200))); } catch (e) {}
  }
  function timeOnly(dt) { return dt ? dt.slice(11, 19) : ''; }
  function chips(h) {
    return (Number(h.is_test) ? ' <span class="chip TEST">TEST</span>' : '')
         + (Number(h.is_demo) ? ' <span class="chip DEMO">DEMO</span>' : '');
  }
  const isFire    = h => h.type === 'FIRE';
  const needsAck  = h => isFire(h) && !h.acknowledged_at;

  // ---------- page elements ----------
  const bar = document.createElement('div');
  bar.id = 'alertbar';
  bar.setAttribute('role', 'alert');
  const topbar = document.querySelector('.topbar');
  (topbar ? topbar.after(bar) : document.body.prepend(bar));

  const modal = document.createElement('div');
  modal.id = 'alertmodal';
  modal.hidden = true;
  document.body.appendChild(modal);

  // ---------- sticky bar ----------
  function drawBar() {
    if (!hazards.length) { bar.innerHTML = ''; bar.className = ''; return; }
    bar.className = hazards.some(needsAck) ? 'has-fire' : hazards.some(isFire) ? 'has-fire acked' : 'has-vapour';
    bar.innerHTML = hazards.map(h => {
      const where = `${esc(h.bin_id)} ${esc(h.name)}, ${esc(h.location_text)}`;
      if (isFire(h)) {
        const status = h.acknowledged_at
          ? `acknowledged by <b>${esc(h.acknowledged_by)}</b> ${esc(timeOnly(h.acknowledged_at))}`
          : (h.ended_at ? `fire ended ${esc(timeOnly(h.ended_at))}, not acknowledged` : 'still burning');
        return `<div class="ab-row fire ${h.acknowledged_at ? 'acked' : ''}">
          <span class="ab-icon">🔥</span>
          <span class="ab-text"><b>FIRE at ${where}</b>${chips(h)}
            <span class="ab-sub">since ${esc(timeOnly(h.started_at))} · ${status}</span></span>
          <span class="ab-call">Call 9999</span>
          ${h.acknowledged_at ? '' : `<button class="btn white small" data-ack="${h.id}">Acknowledge</button>`}
        </div>`;
      }
      return `<div class="ab-row vapour">
        <span class="ab-icon">⚠</span>
        <span class="ab-text"><b>Gas vapour at ${where}</b>${chips(h)}
          <span class="ab-sub">since ${esc(timeOnly(h.started_at))} · fan venting the bin</span></span>
      </div>`;
    }).join('') + `<div class="ab-tools">
        <button class="ab-mute" type="button">${muted ? '🔇 Sound off' : '🔊 Sound on'}</button>
      </div>`;
    bar.querySelectorAll('[data-ack]').forEach(b => b.onclick = () => acknowledge(b.dataset.ack));
    bar.querySelector('.ab-mute').onclick = toggleMute;
  }

  // ---------- pop-up ----------
  function drawModal() {
    // the oldest hazard that still needs its pop-up (fires first)
    const h = hazards.find(x => needsAck(x) && !shownPopups.has(x.id))
           || hazards.find(x => !isFire(x) && !shownPopups.has(x.id));
    if (!h) { modal.hidden = true; modal.innerHTML = ''; return; }
    if (!modal.hidden && modal.dataset.id === String(h.id)) return;   // already showing
    const fire = isFire(h);
    modal.dataset.id = h.id;
    modal.innerHTML = `<div class="am-box ${fire ? 'fire' : 'vapour'}">
        <div class="am-icon">${fire ? '🔥' : '⚠'}</div>
        <h2>${fire ? 'FIRE' : 'Gas vapour'} detected${chips(h)}</h2>
        <p class="am-where"><b>${esc(h.bin_id)} ${esc(h.name)}</b><br>${esc(h.location_text)}</p>
        <p>Started ${esc(timeOnly(h.started_at))}${fire ? '' : ' · the fan is venting the bin'}</p>
        ${fire ? '<p class="am-call">Call Civil Defence: 9999</p>' : ''}
        <div class="am-buttons">
          ${fire ? `<button class="btn white" data-ack="${h.id}">Acknowledge</button>`
                 : `<button class="btn" data-close="${h.id}">OK, I have seen it</button>`}
          <a class="btn" href="${BASE}index.php?bin=${encodeURIComponent(h.bin_id)}" data-seen="${h.id}">Show the bin</a>
          ${h.maps ? `<a class="btn" href="${esc(h.maps)}" target="_blank" rel="noopener">Google Maps</a>` : ''}
        </div>
        ${fire ? '<p class="small">This window stays until the fire is acknowledged.</p>' : ''}
      </div>`;
    modal.hidden = false;
    modal.querySelectorAll('[data-ack]').forEach(b => b.onclick = () => acknowledge(b.dataset.ack));
    modal.querySelectorAll('[data-close]').forEach(b => b.onclick = () => {
      shownPopups.add(Number(b.dataset.close)); saveSet('sb_popups', shownPopups); drawModal();
    });
    // "Show the bin": close the pop-up (the bar keeps Acknowledge and the alarm until then).
    // On the dashboard it shows the bin on the map without reloading the page.
    modal.querySelectorAll('[data-seen]').forEach(a => a.onclick = ev => {
      shownPopups.add(h.id); saveSet('sb_popups', shownPopups);
      if (typeof window.sbShowBin === 'function') {
        ev.preventDefault();
        modal.hidden = true; modal.innerHTML = '';
        window.sbShowBin(h.bin_id);
      }
    });
    beep(fire ? 'fire' : 'vapour');
  }

  async function acknowledge(id) {
    try {
      await fetch(BASE + 'api/ack.php', { method: 'POST',
        body: new URLSearchParams({ event_id: id, csrf }) });
    } catch (e) {}
    shownPopups.add(Number(id)); saveSet('sb_popups', shownPopups);
    poll();
    if (typeof window.sbRefresh === 'function') window.sbRefresh();   // dashboard: redraw at once
  }

  // ---------- sound (Web Audio, no file needed) ----------
  let audio = null;
  function audioCtx() {
    if (!audio) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      audio = new AC();
    }
    if (audio.state === 'suspended') audio.resume();
    return audio;
  }
  // browsers only allow sound after the user clicked or typed on the page once
  ['click', 'keydown', 'touchstart'].forEach(ev =>
    document.addEventListener(ev, () => audioCtx(), { once: true, capture: true }));

  function tone(freq, start, dur) {
    const ctx = audioCtx();
    if (!ctx || ctx.state !== 'running') return;
    const o = ctx.createOscillator(), g = ctx.createGain();
    o.type = 'square'; o.frequency.value = freq;
    g.gain.setValueAtTime(0.0001, ctx.currentTime + start);
    g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + start + 0.02);
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + dur);
    o.connect(g).connect(ctx.destination);
    o.start(ctx.currentTime + start); o.stop(ctx.currentTime + start + dur + 0.05);
  }
  function beep(kind) {
    if (muted) return;
    if (kind === 'fire') { tone(1000, 0, .25); tone(750, .3, .25); tone(1000, .6, .25); tone(750, .9, .25); }
    else                 { tone(660, 0, .18); tone(660, .3, .18); }
  }
  // keep sounding every 3 s while a fire is not acknowledged
  setInterval(() => { if (hazards.some(needsAck)) beep('fire'); }, 3000);

  function toggleMute() {
    muted = !muted;
    try { localStorage.setItem('sb_muted', muted ? '1' : '0'); } catch (e) {}
    drawBar();
  }

  // ---------- flashing tab title ----------
  let flip = false;
  setInterval(() => {
    if (hazards.some(needsAck))      { flip = !flip; document.title = flip ? '🔥 FIRE - Smart Bin' : TITLE; }
    else if (hazards.length)         { document.title = (hazards.some(isFire) ? '🔥 ' : '⚠ ') + TITLE; }
    else                             { document.title = TITLE; }
  }, 1000);

  // ---------- polling ----------
  async function poll() {
    try {
      const res = await fetch(BASE + 'api/alerts.php', { cache: 'no-store' });
      if (!res.ok) return;
      const j = await res.json();
      csrf = j.csrf;
      hazards = j.hazards.map(h => ({ ...h, id: Number(h.id) }));
      drawBar(); drawModal();
      document.body.classList.toggle('hazard', hazards.length > 0);
    } catch (e) { /* next try in 3 s */ }
  }
  window.sbAlertsPoll = poll;
  poll();
  setInterval(poll, POLL_MS);
})();

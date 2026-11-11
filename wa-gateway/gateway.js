// =====================================================================
// Smart Bin - WhatsApp gateway (runs on the laptop next to XAMPP)
//
// Links ONE WhatsApp account (use a spare SIM) like WhatsApp Web does,
// then lets lib/alerts.php send messages through it:
//
//   GET  /status   -> {"ok":true,"state":"READY|QR|STARTING|DISCONNECTED","me":"968..."}
//   GET  /qr       -> page with the QR code to scan (only while not linked)
//   GET  /groups   -> the account's WhatsApp groups (name + id), to pick a group
//   POST /send     {"to":"96890000000" | "group:Smart Bin Team" | "...@g.us","text":"..."}
//
// Every request needs the header  X-Gateway-Secret: <secret from wa-config.json>.
// It listens on 127.0.0.1 only, so other devices on the hotspot cannot use it.
//
// Start it with start-wa-gateway.bat (or: npm start) and keep the window open.
// =====================================================================

const fs = require('fs');
const path = require('path');
const http = require('http');
const crypto = require('crypto');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const qrTerminal = require('qrcode-terminal');

// ---------- settings (wa-config.json, not in git) ----------
const CFG_FILE = path.join(__dirname, 'wa-config.json');
if (!fs.existsSync(CFG_FILE)) {
  const fresh = {
    port: 3010,
    secret: crypto.randomBytes(24).toString('hex'),
    chrome: 'C:/Program Files/Google/Chrome/Application/chrome.exe'
  };
  fs.writeFileSync(CFG_FILE, JSON.stringify(fresh, null, 2));
  console.log('Created wa-config.json: copy its "secret" into config.php (alerts > wa_gateway > secret).');
}
const CFG = JSON.parse(fs.readFileSync(CFG_FILE, 'utf8'));

// ---------- WhatsApp client ----------
let state = 'STARTING';      // STARTING -> QR -> READY  (or DISCONNECTED)
let lastQr = null;
let me = null;

const puppeteer = { headless: true, args: ['--no-sandbox', '--disable-gpu'] };
if (CFG.chrome && fs.existsSync(CFG.chrome)) puppeteer.executablePath = CFG.chrome;

const client = new Client({
  authStrategy: new LocalAuth({ dataPath: path.join(__dirname, '.wwebjs_auth') }),
  puppeteer
});

client.on('qr', qr => {
  state = 'QR';
  lastQr = qr;
  console.log('\nScan this QR with the SPARE phone: WhatsApp > Linked devices > Link a device');
  console.log(`(or open http://127.0.0.1:${CFG.port}/qr?secret=... from the Settings page)\n`);
  qrTerminal.generate(qr, { small: true });
});
function markReady(how) {
  if (state === 'READY') return;
  state = 'READY';
  lastQr = null;
  me = client.info && client.info.wid ? client.info.wid.user : me;
  console.log(`READY (${how}): sending as +${me}`);
}

// After a restart the library sometimes never fires 'ready' although the
// session is connected, so we also ask WhatsApp Web itself every 5 s.
let readyPoll = null;
client.on('authenticated', () => {
  console.log('Linked. Loading chats...');
  if (readyPoll) return;
  readyPoll = setInterval(async () => {
    if (state === 'READY') { clearInterval(readyPoll); readyPoll = null; return; }
    try {
      if (await client.getState() === 'CONNECTED') markReady('connection check');
    } catch (e) { /* page still loading */ }
  }, 5000);
});
client.on('ready', () => markReady('ready event'));
client.on('auth_failure', m => { state = 'DISCONNECTED'; console.log('Link failed:', m); });
client.on('disconnected', reason => {
  state = 'DISCONNECTED';
  console.log('Disconnected:', reason, '- restarting in 10 s');
  setTimeout(() => client.initialize().catch(e => console.log('Restart failed:', e.message)), 10000);
});

// "96890000000" -> "96890000000@c.us";  "group:Name" -> that group's id;  "...@g.us" stays
async function chatIdFor(to) {
  to = String(to || '').trim();
  if (to.endsWith('@c.us') || to.endsWith('@g.us')) return to;
  if (to.toLowerCase().startsWith('group:')) {
    const name = to.slice(6).trim().toLowerCase();
    const chats = await client.getChats();
    const g = chats.find(c => c.isGroup && c.name.trim().toLowerCase() === name);
    if (!g) throw new Error(`no group called "${to.slice(6).trim()}" on this WhatsApp account`);
    return g.id._serialized;
  }
  let digits = to.replace(/\D/g, '');
  if (digits.length === 8) digits = '968' + digits;      // Omani number without the country code
  if (digits.length < 10) throw new Error('phone must be in international format, e.g. 96890000000');
  const id = await client.getNumberId(digits);
  if (!id) throw new Error(`+${digits} is not on WhatsApp`);
  return id._serialized;
}

// ---------- tiny HTTP API ----------
function reply(res, code, obj, type = 'application/json') {
  const body = type === 'application/json' ? JSON.stringify(obj) : obj;
  res.writeHead(code, { 'Content-Type': type + '; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(body);
}
function secretOk(req, url) {
  const sent = req.headers['x-gateway-secret'] || url.searchParams.get('secret') || '';
  const a = Buffer.from(String(sent)), b = Buffer.from(String(CFG.secret));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
function readJson(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', c => { data += c; if (data.length > 20000) req.destroy(); });
    req.on('end', () => { try { resolve(JSON.parse(data || '{}')); } catch (e) { reject(new Error('body must be JSON')); } });
  });
}

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  if (!secretOk(req, url)) return reply(res, 401, { ok: false, error: 'wrong gateway secret' });

  try {
    if (req.method === 'GET' && url.pathname === '/status') {
      return reply(res, 200, { ok: true, state, me });
    }
    if (req.method === 'GET' && url.pathname === '/qr') {
      if (state === 'READY') return reply(res, 200, `<p style="font:16px sans-serif">Linked as +${me}. Nothing to scan.</p>`, 'text/html');
      if (!lastQr) return reply(res, 200, '<p style="font:16px sans-serif">Starting... reload in a few seconds.</p><script>setTimeout(()=>location.reload(),3000)</script>', 'text/html');
      const img = await qrcode.toDataURL(lastQr, { width: 320, margin: 2 });
      return reply(res, 200, `<div style="font:16px sans-serif;text-align:center;margin-top:30px">
        <h2>Smart Bin - link the WhatsApp sender</h2>
        <p>On the spare phone: WhatsApp &gt; Linked devices &gt; Link a device, then scan:</p>
        <img src="${img}" alt="QR code"><p>The code changes every ~20 s; this page reloads itself.</p></div>
        <script>setTimeout(()=>location.reload(),15000)</script>`, 'text/html');
    }
    if (req.method === 'GET' && url.pathname === '/groups') {
      if (state !== 'READY') return reply(res, 503, { ok: false, error: 'WhatsApp not linked yet (state ' + state + ')' });
      const chats = await client.getChats();
      return reply(res, 200, { ok: true, groups: chats.filter(c => c.isGroup).map(c => ({ name: c.name, id: c.id._serialized })) });
    }
    if (req.method === 'POST' && url.pathname === '/send') {
      if (state !== 'READY') return reply(res, 503, { ok: false, error: 'WhatsApp not linked yet (state ' + state + ')' });
      const { to, text } = await readJson(req);
      if (!text || typeof text !== 'string') return reply(res, 400, { ok: false, error: 'text is required' });
      const chatId = await chatIdFor(to);
      const msg = await client.sendMessage(chatId, text.slice(0, 4000));
      console.log(new Date().toLocaleTimeString(), 'sent to', to);
      // WhatsApp Web does not always hand back the sent message: the send still worked
      return reply(res, 200, { ok: true, to: chatId, id: msg && msg.id ? msg.id._serialized : null });
    }
    return reply(res, 404, { ok: false, error: 'unknown path' });
  } catch (e) {
    return reply(res, 400, { ok: false, error: e.message });
  }
}).listen(CFG.port, '127.0.0.1', () => {
  console.log(`Smart Bin WhatsApp gateway on http://127.0.0.1:${CFG.port}  (keep this window open)`);
});

client.initialize().catch(e => { state = 'DISCONNECTED'; console.log('Could not start WhatsApp:', e.message); });

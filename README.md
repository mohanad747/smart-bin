# Smart Bin: IoT smart community waste bin

IoT-based smart community waste bin with predictive collection dispatch and automatic
fire-safety response. Diploma project (CSNW2203, UTAS Muscat, Fall 2026).

An ESP32 bin unit measures the fill level (HC-SR04), flammable vapour (MQ-2), flame (IR sensor)
and temperature/humidity (DHT22). It reacts to hazards **on its own** (fan vents vapour; on fire the
fan stops and the buzzer sounds) and sends every reading over Wi-Fi to a PHP/MySQL server. The
operator dashboard shows a map, fill levels, a priority collection list with predicted time-to-full,
hazard banners, charts and an event log, and sends an urgent email / WhatsApp alert on fire.

The prototype has one bin, but everything handles many bins: **adding a bin = adding a row** on
the Settings page, no code changes.

---

## Folder layout

```
smart_bin/
├── index.php              dashboard (one page, refreshes every 5 s)
├── login.php, logout.php
├── settings.php           bins, thresholds, alerts, laptop IP, password
├── config.sample.php      copy to config.php (config.php is not in git)
├── api/
│   ├── ingest.php         ESP32 -> server (DFD 1.0 - 4.0)
│   ├── status.php         dashboard data + priority ranking (DFD 5.0, 6.0)
│   ├── history.php        chart data (last 6 h)
│   ├── ack.php            acknowledge a fire alert
│   ├── test_cmd.php       start/stop a test on the real bin (Simulator page)
│   ├── device_poll.php    ESP32 asks "any test for me?" every 2 s
│   └── wa_qr.php          QR code to link the WhatsApp gateway
├── tools/
│   ├── simulator.php      real-bin tests + fake bin for testing without hardware
│   └── sim_backfill.php
├── lib/                   db + helpers, login/CSRF, alerts, PHPMailer (not web-reachable)
├── assets/                CSS, dashboard JS, Chart.js 4.4.1, Leaflet 1.9.4 (served locally)
├── sql/
│   ├── schema.sql         tables (D1 bins, D2 readings, D3 events, D4 settings, D5 users, alert_log)
│   ├── seed.sql           BIN-01 at UTAS Muscat, admin user, default thresholds
│   ├── migrate_1_1.sql    upgrade a 1.0 database (test mode columns)
│   └── reset_data.sql     clear readings/events before a demo (keeps bins, settings, users)
├── firmware/SmartBin/     ESP32 Arduino sketch
├── wa-gateway/            WhatsApp sender (Node.js), start with start-wa-gateway.bat
├── README.md, TESTING.md
```

---

## 1. Server setup on XAMPP (Windows)

1. Install XAMPP (PHP 8.x). Open the **XAMPP Control Panel** and **Start** Apache and MySQL.
2. Put this folder at `C:\xampp\htdocs\smart_bin\`.
3. Create the database:
   - open <http://localhost/phpmyadmin> → **Import** → choose `sql/schema.sql` → **Go**
   - then import `sql/seed.sql` the same way.

   Or from a terminal:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\smart_bin\sql\schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\smart_bin\sql\seed.sql
   ```
   (Press Enter at the password prompt if your MySQL root has no password.)
   Already have a 1.0 database? Import `sql/migrate_1_1.sql` instead (adds the test-mode columns).
4. Copy `config.sample.php` to `config.php` and set the MySQL password in `'db' => ['pass' => ...]`.
5. Open <http://localhost/smart_bin/> and log in:
   - user **admin**, password **SmartBin@2026**
   - change it at once: **Settings → Change password**.
6. `seed.sql` gives BIN-01 a random token. Copy it from **Settings → Bins → BIN-01** into the
   ESP32's `secrets.h`.

---

## 2. Let the ESP32 reach the laptop

### Find the laptop IP
- Easiest: **Settings → ESP32 connection** shows every LAN IP and the exact ingest URL to copy.
  Ignore `192.168.56.x` (VirtualBox).
- Or run `ipconfig` and look at the **Wireless LAN adapter Wi-Fi → IPv4 Address**.
- A phone hotspot can hand out a new IP each time; check again before each demo.
- **Best for demos: the laptop's own Windows Mobile Hotspot.** On it the laptop is always
  `192.168.137.1`, so `SERVER_URL` = `http://192.168.137.1/smart_bin/api/ingest.php` never changes.

### Windows Firewall rule for Apache (Private profile)
1. Make the hotspot/router network **Private**: Settings → Network & internet → Wi-Fi →
   (your network) → Network profile type → **Private**.
2. Open **PowerShell as Administrator** and run:
   ```powershell
   New-NetFirewallRule -DisplayName "Apache HTTP (Smart Bin)" -Direction Inbound `
     -Program "C:\xampp\apache\bin\httpd.exe" -Action Allow -Profile Private
   ```
   (If Windows showed "Allow Apache HTTP Server" the first time Apache started, tick **Private**
   there instead.)
3. Test from your phone (on the same hotspot): open `http://<laptop-ip>/smart_bin/login.php`.

---

## 3. Alerts (email + WhatsApp)

All settings are in `config.php` → `'alerts'`. Each channel has `'enabled' => true/false`.

**Email (Gmail SMTP through PHPMailer, included in `lib/PHPMailer`, no Composer needed)**
1. Use a Gmail account with **2-Step Verification** on.
2. Google Account → Security → **App passwords** → create one (16 letters).
3. In `config.php`: `'username'` = the Gmail address, `'app_password'` = the 16 letters,
   `'to'` = who receives alerts, `'enabled' => true`.

**WhatsApp through our own gateway (recommended, free)**
`wa-gateway/` is a small Node.js program (whatsapp-web.js) that sends from a **linked WhatsApp
account, like WhatsApp Web**. Use a **spare SIM**, not a personal number: it is unofficial, and
WhatsApp may block numbers that look like spam (one message per fire is far from that).
1. Install Node.js 18+ and Google Chrome (the gateway uses Chrome, no extra download).
2. Double-click **`start-wa-gateway.bat`** and keep its window open. The first start installs the
   packages and creates `wa-gateway/wa-config.json` with a random `secret`.
3. In `config.php` → `'wa_gateway'`: `'enabled' => true`, `'secret'` = the secret from
   `wa-config.json`.
4. **Settings → Urgent alerts → Show QR code**, then on the spare phone: WhatsApp → Linked devices →
   Link a device → scan. The row turns **linked**. The link is remembered (`wa-gateway/.wwebjs_auth`).
5. In `'to'` list phone numbers (`'96890000000'`) and/or groups (`'group:Smart Bin Team'`, exact
   name; Settings lists the groups the spare number is in).
- It listens on 127.0.0.1 only and every request needs the secret. Its login data is not in git
  and the folder is blocked from the web.
- For a real deployment use the official WhatsApp Business (Cloud) API instead.

**WhatsApp (CallMeBot, free, but often full)**
1. Each receiving phone adds the CallMeBot number shown on
   <https://www.callmebot.com/blog/free-api-whatsapp-messages/> to its contacts and sends it on
   WhatsApp: `I allow callmebot to send me messages`.
2. CallMeBot replies with an **API key** for that phone.
3. In `config.php` add `['phone' => '+968XXXXXXXX', 'apikey' => 'KEY']` and `'enabled' => true`.

Then press **Settings → Send test alert**. Every attempt and its result is in the table under it
(`alert_log`).

How alerts behave:
- **FIRE**: one urgent message per fire event (bin, name, location, Google Maps link,
  temperature, time, "Call Civil Defence 9999"). Never one per reading. A fire that comes back
  within 2 minutes continues the same event.
- **VAPOUR**: dashboard only, no message.
- **Bin full** (Objective 2): optional, `'notify_full' => true` sends one message when a bin
  reaches the *Full* threshold (again only after it was emptied).
- The server answers the ESP32 **first** and sends the alert afterwards, with a short timeout
  (`'timeout_s'`), so the bin never waits for email/WhatsApp.

---

## 4. ESP32 firmware (Arduino IDE)

1. Arduino IDE 2 → **File → Preferences → Additional boards manager URLs**:
   `https://espressif.github.io/arduino-esp32/package_esp32_index.json`
2. **Boards Manager** → install **esp32 by Espressif Systems** (3.x, tested with 3.0.7).
3. **Library Manager** → install **DHT sensor library** (Adafruit, accept "Adafruit Unified
   Sensor" too) and **ArduinoJson** (v7).
4. In `firmware/SmartBin/` copy `secrets.example.h` to `secrets.h` and fill in:
   Wi-Fi name/password (2.4 GHz), `SERVER_URL` (from Settings), `BIN_ID`, `DEVICE_TOKEN`
   (from Settings → Bins → BIN-01).
5. Open `firmware/SmartBin/SmartBin.ino`, board **ESP32 Dev Module**, choose the COM port, **Upload**.
6. **Serial Monitor at 115200** prints one line per second (distance, gas, flame, temperature,
   rise, state, fan, buzzer, Wi-Fi) and each upload result.

Pins (report Table 2.2): TRIG 19, ECHO 18 (divider), MQ-2 AO 34 (divider), flame DO 27,
DHT22 4, relay IN 26 (fan on when LOW), buzzer 25. Change them in `config.h`.

**Calibrating**
- **Empty depth**: measure from the ultrasonic sensor to the bottom of the empty bin and set it on
  Settings → Bins. Fill % = (empty depth − distance) ÷ empty depth × 100.
- **Gas threshold**: power the unit in clean air, wait for the MQ-2 to warm up (gas is ignored for
  the first 60 s), read the gas values on the Serial Monitor, then set the threshold well above them
  (Settings → Thresholds). The bin gets new thresholds in its next upload reply and saves them in
  flash.
- **Buzzer**: if it sounds when it should be quiet, your module is "low level trigger": set
  `BUZZER_ON_LEVEL` to `LOW` in `config.h`.
- **MQ-2**: wire **AO** (through the divider) to GPIO34, not DO. The blue knob only changes DO, so
  it does not change the gas number. Clean air reads about 300–500. Test with one short spray of
  deodorant / air freshener (butane propellant) about 10 cm away; it should jump to 1000–2500.
  An empty lighter often gives nothing.
- **Flame sensor**: DO to GPIO27; turn its knob until the DO-LED is off in normal light and comes
  on with a flame. A TV remote pointed at it (infrared) also triggers it.

**Hardware notes**
- The relay is a **5 V** relay module (JQC3F-05VDC). On 3.3 V many of these click weakly or not at
  all. If the fan does not switch, move the relay **VCC to the 5 V rail** (IN stays on GPIO26).
- The safety logic (fan/buzzer) runs on the ESP32 every second and does not need Wi-Fi. If Wi-Fi
  is down the unit keeps reacting and retries the connection every 15 s.
- The DHT22 tops out at 80 °C. During a fire the firmware sends at most 80 °C so the server does
  not reject the reading.

---

## 5. Simulator page: real-bin tests and a virtual bin

### Real bin test (drives the circuit)
Makes the **real ESP32** act as if its sensors saw a fire or a gas leak, so the buzzer / fan work
in the circuit during a demo without a real fire:

| Button | On the bin | On the dashboard |
|---|---|---|
| Test fire | buzzer ON, fan OFF | red FIRE banner marked **TEST**, FIRE event marked TEST, alert sent with "TEST ONLY" |
| Test gas leak | fan ON, buzzer OFF | amber banner marked **TEST**, VAPOUR event marked TEST |
| Stop test | back to normal | |

- The command is saved on the bin row; the ESP32 picks it up within **about 2 s**
  (`api/device_poll.php` between uploads, and every upload reply).
- The test **ends by itself** after the chosen time (15 / 30 / 60 s, max 120 s), even if Wi-Fi drops.
- **Real sensors always win**: a real fire during a gas test is a FIRE, and an event the server's own
  check confirms from the sensor values is never marked as a test.
- A second fire test within 2 minutes continues the same event, so it does not send a second alert.

### Virtual bin (no hardware)
Sends fake readings through the real `api/ingest.php`. Use a bin whose ESP32 is switched off,
otherwise the fake and real readings mix:

| Button | What it does |
|---|---|
| Normal | steady readings |
| Filling up | fill rises each reading (choose the speed) |
| Gas leak | gas above the threshold, state VAPOUR, fan on |
| Fire | flame + rising temperature, state FIRE, buzzer on → one alert |
| Sensor failure | distance, temperature and humidity are `null` |
| Bin emptied | sends 3 readings at 5 % → EMPTIED event (the server needs 3 low readings in a row) |
| Wrong token | expect HTTP 401, nothing saved |
| Bad value | 150 °C, expect HTTP 400, nothing saved |
| Add 1 h of filling history | writes past readings so the prediction shows at once (test tool only) |

Keep the dashboard open in another tab to watch it react. Before a real demo, clear the test data
with `sql/reset_data.sql` (it keeps bins, thresholds and users).

---

## 6. API contract (ESP32 → server)

`POST /smart_bin/api/ingest.php`, `Content-Type: application/json`

```json
{"bin_id":"BIN-01","token":"device-token-here","distance_cm":42.3,"gas_raw":812,
 "flame":false,"temp_c":31.4,"humidity":40.2,"state":"NORMAL","fan":false,"buzzer":false,
 "uptime_s":1234,"rssi":-61,"fw":"1.1.0","test":false}
```

- `distance_cm`, `temp_c`, `humidity` may be `null` (sensor read failed). The reading is saved and
  the dashboard keeps showing the last good value.
- `test` (optional): `true` when the state comes from a test command, not the sensors.
- **200** `{"ok":true,"thresholds":{"gas_raw":1800,"temp_limit_c":60,"temp_rise_c":5,"temp_rise_window_s":60,"upload_interval_s":10},"test":null}`
  — `test` is `{"mode":"FIRE","seconds_left":23}` while a test is running.
- **401** `{"ok":false,"error":"invalid token"}`: wrong/missing token. Nothing saved.
- **400** `{"ok":false,"error":"temp_c out of range (-40 to 80)"}`: ranges: distance 0–400,
  temp −40–80, humidity 0–100, gas 0–4095. Nothing saved.

What the server does with a reading (DFD processes):

| DFD | Where | What |
|---|---|---|
| 1.0 Receive and validate | `ingest.php` | token check, range check, fill % |
| 2.0 Detect hazard | `ingest.php` | trusts the ESP32 state but re-checks the thresholds; if the server's check is more severe, that state is used. Opens/closes VAPOUR & FIRE events with peak values; EMPTIED when fill drops ≥ 30 points and stays down for 3 readings in a row (one odd echo is ignored) |
| 3.0 Send urgent alert | `lib/alerts.php` | one alert per new FIRE event, after replying |
| 4.0 Predict time-to-full | `ingest.php` | straight-line fit of fill % over time since the last EMPTIED; needs ≥ 5 readings over ≥ 10 min, else "Not enough data"; rate ≤ 0.1 %/h = "Not filling" |
| 5.0 Rank collection priority | `status.php` | soonest time-to-full first, then highest fill |
| 6.0 Log in and show dashboard | `login.php`, `index.php` | |
| 7.0 Manage bins and settings | `settings.php` | |

`POST /smart_bin/api/device_poll.php` with `{"bin_id":"BIN-01","token":"..."}` answers
`{"ok":true,"test":null}` or the running test (401 for a wrong token). Nothing is saved.

OFFLINE events: a bin is offline after **3 × its upload interval** with no reading. The event is
opened when the dashboard notices it and closed by the next reading.

---

## 7. Security

- Prepared statements (mysqli) for every query
- Passwords stored with `password_hash()` / checked with `password_verify()`
- Sessions (HttpOnly cookie, new session id on login) on every operator page and API
- CSRF token on every form and on the acknowledge button
- All output escaped (`e()` in PHP, `esc()` / `textContent` in JavaScript)
- Device token checked on every ingest (`hash_equals`), per bin, can be regenerated
- `config.php`, `lib/`, `sql/`, `firmware/` are blocked from the browser by `.htaccess`
- Secrets (`config.php`, `firmware/SmartBin/secrets.h`) are in `.gitignore`

### HTTPS for the final demo
The report says readings travel over HTTPS. For local testing we use plain HTTP. To switch:

**Server (XAMPP)**: Apache SSL is already set up in XAMPP (`https://localhost`) with a
self-signed certificate. For the ESP32 the certificate should name the laptop IP:
1. Create a certificate for the IP with OpenSSL (`C:\xampp\apache\bin\openssl.exe`), e.g.
   `openssl req -x509 -newkey rsa:2048 -nodes -days 365 -keyout server.key -out server.crt -subj "/CN=192.168.x.x" -addext "subjectAltName=IP:192.168.x.x"`
2. Put them in `C:\xampp\apache\conf\ssl.crt\server.crt` and `ssl.key\server.key`, restart Apache.
3. Add a firewall rule for port 443 (same as above).

**ESP32**:
1. `SERVER_URL` → `https://192.168.x.x/smart_bin/api/ingest.php`
2. In `upload()` use a `WiFiClientSecure` and pass it to `http.begin(client, SERVER_URL)`.
3. Give it the certificate: `client.setCACert(SERVER_CERT)` with the contents of `server.crt`
   pasted as a string in `secrets.h`. (`client.setInsecure()` encrypts but does not check who the
   server is; use it only to test.)
4. The TLS handshake takes about 1–2 s on the ESP32 and the HTTP timeout is 4 s, so it fits.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| ESP32 serial: `connection refused` / `connection lost` | wrong `SERVER_URL` IP, Apache stopped, or firewall. Check Settings → ESP32 connection, test the URL from a phone |
| ESP32 serial: `invalid token` | `DEVICE_TOKEN` / `BIN_ID` do not match Settings → Bins |
| ESP32 serial: `REJECTED (400)` | a sensor sends an impossible value; the reply says which |
| Bin shows **Offline** | no reading for 3 × upload interval; check the ESP32's Wi-Fi line |
| Map is grey | tiles need internet. Markers, cards and the priority list still work |
| "Database connection failed" | MySQL not started, or wrong password in `config.php` |
| Test alert fails | read the error in the alert table (wrong app password, CallMeBot key not activated, no internet) |

Third-party: Chart.js (MIT), Leaflet (BSD-2), PHPMailer (LGPL 2.1, `lib/PHPMailer/LICENSE`),
map data © OpenStreetMap contributors.

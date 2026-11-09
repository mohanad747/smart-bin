# Smart Bin: test checklist

Each requirement from the first review report (Tables 2.4, 2.5, 2.6) is mapped to a test step and
the expected result. Tick the box when it passes and note the date / evidence (screenshot name,
serial log) for the report.

**Before you start**
- Apache + MySQL running, logged in as `admin`.
- For a clean run: import `sql/reset_data.sql`.
- "Sim" = the Simulator page. "ESP32" = the real bin unit with the Serial Monitor open (115200).
- Steps marked **(sim)** can be done without hardware. Do them again with the ESP32 for the final
  report where it says **(ESP32)**.

---

## Functional requirements (Table 2.4)

| ID | Requirement | Test step | Expected result | Pass |
|---|---|---|---|---|
| FR1 | Waste level monitoring | **(ESP32)** Put objects in the bin at known heights (e.g. 0, 10, 20, 30 cm of waste). Compare the dashboard fill % with (waste height ÷ empty depth × 100). | Fill % within ±5 points of the calculated value, updated every ~10 s. Serial shows the median distance each second. | ☐ |
| FR2 | Data transmission | **(ESP32)** Run for 30 min. Count rows: `SELECT COUNT(*) FROM readings WHERE bin_id='BIN-01' AND created_at >= NOW() - INTERVAL 30 MINUTE;` | About 180 rows (one per 10 s). Delivery % = rows ÷ 180. Serial shows "Upload OK". | ☐ |
| FR2b | Only the bin can send | **(sim)** Press *Wrong token*. Also with curl (see API tests). | HTTP 401 `invalid token`; row count unchanged. | ☐ |
| FR3 | Collection status | **(sim)** *Filling up* until fill passes 70 % then 90 %. | Card/map/priority colour turns amber at 70 % ("Nearly full") and red at 90 % ("Full"); priority list shows "Full now: collect". | ☐ |
| FR4 | Fire-hazard detection | **(ESP32)** (a) Gas: hold a lighter's gas (unlit) near the MQ-2. (b) Flame: a lit lighter in front of the flame sensor at a safe distance. (c) Heat: warm the DHT22 with a hair dryer (+5 °C within 60 s). | (a) state VAPOUR. (b) and (c) state FIRE. Serial shows the state change within ~1 s. | ☐ |
| FR5 | Automatic hazard response | **(ESP32)** Repeat FR4 with **Wi-Fi off** (stop the hotspot). | VAPOUR: fan ON, buzzer off. FIRE: fan OFF, buzzer ON. Works with "wifi down" in the serial line. | ☐ |
| FR5b | Hazard response from the dashboard (test mode) | **(ESP32)** Simulator → Real bin test → *Test fire*, wait, *Stop test*; then *Test gas leak*. | Within ~2 s: fire → buzzer ON, fan OFF; gas → fan ON. Serial shows "TEST … from the dashboard" and "(TEST)" on each line. Dashboard banner and event log show **TEST**. Test ends by itself after the chosen time. | ☐ |
| FR6 | Emergency notifications | **(sim)** Enable email/WhatsApp in `config.php`, press *Settings → Send test alert*, then run *Fire* once. Then *Gas leak*. | Test message arrives. Fire: **one** message with bin, location, Maps link, temperature, time, 9999; event log "Alert: Sent". Gas leak: **no** message, amber banner only. | ☐ |
| FR7 | Dashboard display | **(sim)** Open the dashboard. | Fill %, location on the map, state, temperature, humidity, gas, fan, buzzer, last seen are shown. | ☐ |
| FR8 | Data and hazard logging | After the tests: `SELECT type, started_at, ended_at, peak_temp_c, peak_gas_raw FROM events;` and the dashboard event log. | Every VAPOUR/FIRE/EMPTIED/OFFLINE event with start, end, peaks; readings table has every reading with time. | ☐ |
| FR9 | Fill-time prediction and priority | **(sim)** *Add 1 h of filling history*, then *Filling up* for a minute. Add a second bin (Settings) and fill it faster/slower. | Card shows a fill rate (%/h); priority list shows "Full in ~X h Y min", soonest first. With fewer than 5 readings / 10 min: "Not enough data". After *Normal* for a while with no change: "Not filling". | ☐ |
| FR10 | Remote threshold settings | **(ESP32)** Settings → Thresholds → BIN-01: set gas to a value just above the clean-air reading → Save. | Within one upload (≤ 10 s) the serial shows "New thresholds: gas …". Reboot the ESP32: the startup line still shows the new value (saved in flash). | ☐ |

---

## Usability requirements (Table 2.5)

| ID | Requirement | Test step | Expected result | Pass |
|---|---|---|---|---|
| UR1 | Dashboard design | Open the dashboard on a laptop and a phone. | Banner, map, priority list, bin cards, charts and event log on one page, readable on both. | ☐ |
| UR2 | Easy access to information | Use only the dashboard page during a full sim run. | No page change needed to see status, alerts, history and events. | ☐ |
| UR3 | Bin location | Look at the map. | BIN-01 marker at UTAS Muscat (South Al Khuwair); clicking it selects the bin. With internet off: note "Map tiles need internet", bins still listed. | ☐ |
| UR4 | Fill level | Look at a card. | Fill shown as a % with a bar. | ☐ |
| UR5 | Hazard alerts | *Gas leak*, then *Fire*. | Amber banner for vapour; red flashing banner for fire with bin, location, time and "Call Civil Defence 9999". | ☐ |
| UR6 | Temperature display | Look at a card. | Temperature in °C with the unit next to the value. | ☐ |
| UR7 | Colour-coded status | *Filling up* through 70 % and 90 %. | Marker green → amber → red; flashing red 🔥 marker during FIRE. | ☐ |
| UR8 | Priority list and time-to-full | Two or more bins with data. | List next to the map, ordered soonest first, with "Full in ~…", "Not enough data" or "Not filling". | ☐ |
| UR9 | Event log | After the tests. | Gas and fire events with date and time, newest first; filter by bin works. | ☐ |

---

## User experience requirements (Table 2.6)

| ID | Requirement | Test step | Expected result | Pass |
|---|---|---|---|---|
| UX1 | Bin status at a glance | Ask a classmate who has not seen the system: "Is BIN-01 OK?" | They answer correctly in a few seconds from the card/banner. | ☐ |
| UX2 | Automatic data updates | Run *Filling up* and watch without touching the page. | Values change within 5 s; "Live · updated hh:mm:ss" ticks; no reload. Stop Apache: line turns red "Server not reachable". | ☐ |
| UX3 | Clear and noticeable alerts | *Fire* while the dashboard is in another browser tab. | Tab title becomes "🔥 FIRE - Smart Bin"; banner flashes until **Acknowledge**; after acknowledging it shows who and when. | ☐ |
| UX4 | Linking location to data | Click the map marker and a priority-list row. | The matching card is highlighted and the charts switch to that bin. | ☐ |
| UX5 | Calm display | Everything normal. | Calm green "All bins normal" line, no red/amber anywhere. | ☐ |

---

## Report test plan items (Table 2.1, stage 5)

| Test | How | Record |
|---|---|---|
| Fill-level accuracy | FR1 at 4+ known heights, 3 readings each | table: true % vs measured %, error |
| 20 fire-safety scenarios | FR4/FR5: e.g. 7 × gas, 7 × flame, 6 × heat; note the reaction time from serial | state reached? fan/buzzer right? time? |
| Upload success rate | FR2 over 30–60 min | rows received ÷ rows expected |

---

## API tests (curl)

Use Git Bash or PowerShell (`curl.exe`). Replace the token with BIN-01's token from Settings.

```bash
URL=http://localhost/smart_bin/api/ingest.php
TOKEN=paste-token-here

# 200 - accepted, thresholds returned
curl -i -H "Content-Type: application/json" -d '{"bin_id":"BIN-01","token":"'$TOKEN'","distance_cm":42.3,"gas_raw":812,"flame":false,"temp_c":31.4,"humidity":40.2,"state":"NORMAL","fan":false,"buzzer":false,"uptime_s":1234,"rssi":-61,"fw":"1.0.0"}' $URL

# 200 - sensor failure (nulls) still saved
curl -i -H "Content-Type: application/json" -d '{"bin_id":"BIN-01","token":"'$TOKEN'","distance_cm":null,"gas_raw":812,"flame":false,"temp_c":null,"humidity":null,"state":"NORMAL","fan":false,"buzzer":false}' $URL

# 401 - wrong token, nothing saved
curl -i -H "Content-Type: application/json" -d '{"bin_id":"BIN-01","token":"wrong","distance_cm":42.3,"gas_raw":812,"flame":false,"temp_c":31.4,"humidity":40.2,"state":"NORMAL","fan":false,"buzzer":false}' $URL

# 400 - out of range, nothing saved
curl -i -H "Content-Type: application/json" -d '{"bin_id":"BIN-01","token":"'$TOKEN'","distance_cm":42.3,"gas_raw":812,"flame":false,"temp_c":150,"humidity":40.2,"state":"NORMAL","fan":false,"buzzer":false}' $URL
```

| Case | Expected | Pass |
|---|---|---|
| Valid reading | `200 {"ok":true,"thresholds":{...}}` | ☐ |
| Nulls | `200`, dashboard keeps the last good values | ☐ |
| Wrong token | `401 {"ok":false,"error":"invalid token"}` | ☐ |
| Out of range | `400 {"ok":false,"error":"temp_c out of range (-40 to 80)"}` | ☐ |
| Alert does not delay the reply (enable email with a wrong SMTP host, send a FIRE reading) | reply in < 0.5 s; the failed attempt appears in the alert table a few seconds later | ☐ |

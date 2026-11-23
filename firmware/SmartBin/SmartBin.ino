// =====================================================================
// Smart Bin - ESP32 bin unit firmware
// Board: ESP32 Dev Module (ESP32 DevKit V1, ESP-WROOM-32)
// Libraries: "DHT sensor library" (Adafruit) + "Adafruit Unified Sensor",
//            "ArduinoJson" (v7), "ESP32Servo" (3.x)
//
// Once a second (flowchart, Figure 2.5):
//   1. read the sensors (ultrasonic = median of 5, MQ-2, flame, DHT22)
//   2. decide the state, most dangerous first:
//        FIRE   = flame, OR temperature >= limit, OR a sudden rise
//                 -> fan OFF (do not feed the fire), buzzer ON, lid locked
//        VAPOUR = gas >= threshold -> fan ON to vent, buzzer OFF
//        NORMAL -> fan OFF, buzzer OFF
//      This happens HERE on the bin, so it works without Wi-Fi or server.
//   3. upload to the server: at once when something important changes,
//      every 2 s during a hazard, otherwise every upload_interval_s (10 s).
//   4. take the thresholds from the server's reply and save them in flash,
//      so the operator can change them without reprogramming.
//
// All the time (the rules are in bin_logic.h):
//   - lid: opens for a hand within 20 cm and closes 4 s after it leaves.
//     Locked when full and during a fire. The dashboard can open it for
//     emptying (2 min at most).
//   - fire ball: dropped once, when a fire is CONFIRMED = flame seen for 3 s,
//     or the temperature limit, or a sudden rise. The alarm never waits.
//
// Test mode: the Simulator page can tell the bin "act as if you saw a fire /
// gas leak" for a few seconds. Real sensors always win. A test fire locks the
// lid but does NOT drop the ball unless the operator asked for it.
//
// The network runs in its own task on the other core, so a slow server never
// stops the lid, the alarm or the fire ball.
// =====================================================================

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <DHT.h>
#include <Preferences.h>
#include <ESP32Servo.h>
#include <time.h>

#include "config.h"
#include "bin_logic.h"
#include "secrets.h"     // copy secrets.example.h to secrets.h first

// ---------------------------------------------------------------------
// Thresholds (defaults until the first server reply, then from flash)
// ---------------------------------------------------------------------
struct Thresholds {
  int   gasRaw;
  float tempLimitC;
  float tempRiseC;
  int   tempRiseWindowS;
  int   uploadIntervalS;
  int   fullPct;
  float emptyDepthCm;        // sensor to the bottom of the empty bin, to work out the fill %
};
Thresholds th = { DEF_GAS_RAW, DEF_TEMP_LIMIT_C, DEF_TEMP_RISE_C, DEF_TEMP_RISE_WIN_S, DEF_UPLOAD_S,
                  DEF_FULL_PCT, DEF_EMPTY_DEPTH_CM };

enum BinState { NORMAL, VAPOUR, FIRE };
const char* STATE_NAME[] = { "NORMAL", "VAPOUR", "FIRE" };

// A copy of everything one request needs, so the network task never reads
// values that the main loop is changing.
struct Job {
  bool     upload;                 // true = reading (ingest.php), false = small poll (device_poll.php)
  float    distanceCm, tempC, humidity, handCm;
  int      gasRaw;
  bool     flame, fan, buzzer, test, ballArmed;
  BinState state;
  LidState lidState;
  uint32_t opens, ackId;
  bool     ackOk;
};
struct Answer {
  bool     upload;
  int      code;                   // HTTP code, or a negative HTTPClient error
  uint32_t ackId;                  // the command result this request carried
  char*    body;                   // freed by the main loop
};
QueueHandle_t jobQ, answerQ;
volatile bool netBusy = false;

DHT dht(PIN_DHT, DHT22);
Preferences prefs;

// ---------------------------------------------------------------------
// Latest sensor values (NAN = the sensor failed this time)
// ---------------------------------------------------------------------
float distanceCm = NAN;
float tempC      = NAN;
float humidity   = NAN;
int   gasRaw     = 0;
bool  flame      = false;        // already false while the lid is open (sunlight)
float handCm     = -1;           // HC-SR04 #2, -1 = nothing in front

BinState state     = NORMAL;   // what the bin does (real state, or the test if it is more severe)
BinState realState = NORMAL;   // from the sensors only (hold times work on this one)
bool fanOn       = false;
bool buzzerOn    = false;      // the fire alarm (the short "full" beeps are separate)

// Test mode (from the Simulator page): NORMAL = no test running
BinState testMode    = NORMAL;
uint32_t testUntilMs = 0;
uint32_t lastPollMs  = 0;
bool     testDropBall = false;   // this fire test may drop the real ball
bool     testDropped  = false;

// Lid and fire ball
LidLogic  lid;
FullLogic fullLogic;
FlameRun  flameRun;
BallLogic ball;
Servo     lidServo, ballServo;
int       lidPos = LID_CLOSED_DEG;
uint32_t  lidStepMs = 0, lidClosedMs = 0;
bool      heatSign  = false;     // temperature limit or sudden rise in the last safety pass
bool      uploadNow = false;     // something the dashboard must see at once
uint32_t  beepMs    = 0;
bool      beeping   = false;

// Commands from the dashboard (lid_open, lid_close, arm_ball)
uint32_t lastCmdId = 0;          // saved in flash: a command is never done twice
bool     lastCmdOk = true;
uint32_t ackId     = 0;          // tell the server in the next request, 0 = nothing

uint32_t lastServerOkMs = 0;     // last good answer from the server

// Temperature history for the "sudden rise" check (one sample per DHT read)
const int HIST = 160;                  // 160 x 2 s = 320 s, enough for a 300 s window
float    histTemp[HIST];
uint32_t histMs[HIST];
int      histCount = 0, histHead = 0;

uint32_t lastLoopMs = 0, lastDhtMs = 0, lastUploadMs = 0, lastWifiTryMs = 0;
uint32_t lastFireSignMs = 0, lastGasHighMs = 0;
bool     everUploaded = false;

const char* okText(bool ok) { return ok ? "ok" : "REFUSED"; }

// ---------------------------------------------------------------------
// Outputs
// ---------------------------------------------------------------------
void setFan(bool on) {
  fanOn = on;
  digitalWrite(PIN_RELAY, on ? RELAY_ON_LEVEL : !RELAY_ON_LEVEL);
}

// The alarm during a fire, or two short beeps when someone uses a full bin
void updateBuzzer(uint32_t now) {
  bool sound = buzzerOn;
  if (beeping) {
    uint32_t t = now - beepMs;
    if (t >= 360) beeping = false;
    else if (t < 120 || t >= 240) sound = true;
  }
  digitalWrite(PIN_BUZZER, sound ? BUZZER_ON_LEVEL : !BUZZER_ON_LEVEL);
}

// Where the lid is
bool lidIsClosed() { return lidPos == LID_CLOSED_DEG; }
bool lidMoving() {
  int target = lid.isOpen() ? LID_OPEN_DEG : LID_CLOSED_DEG;
  return lidPos != target || millis() - lidStepMs < 150;   // + time for the servo to arrive
}

// ---------------------------------------------------------------------
// Sensors
// ---------------------------------------------------------------------
// One HC-SR04 measurement in cm, or NAN if there was no echo
float readDistanceOnce(int trig, int echo, unsigned long timeoutUs) {
  digitalWrite(trig, LOW);
  delayMicroseconds(2);
  digitalWrite(trig, HIGH);
  delayMicroseconds(10);
  digitalWrite(trig, LOW);
  unsigned long us = pulseIn(echo, HIGH, timeoutUs);
  if (us == 0) return NAN;
  float cm = us * 0.0343f / 2.0f;                        // speed of sound, there and back
  if (cm < 2 || cm > 400) return NAN;                    // outside the sensor's range
  return cm;
}

// The two HC-SR04s take turns (never together): hand, fill, hand, fill ...
// Hand = median of 3. Fill = median of 5, only while the lid is closed and still,
// because the flap and the hand are in the way otherwise (the last value is kept).
void updateSonars(uint32_t now) {
  static uint32_t lastMs = 0;
  static bool  handTurn = false;
  static float hands[3] = { 999, 999, 999 };             // 999 = nothing in front
  static float v[5];
  static int   h = 0, tries = 0, good = 0;
  if (now - lastMs < SONAR_SLOT_MS) return;
  lastMs = now;
  handTurn = !handTurn;

  if (handTurn) {
    float d = readDistanceOnce(PIN_TRIG2, PIN_ECHO2, 12000UL);       // 12 ms = about 2 m
    hands[h] = isnan(d) ? 999 : d;
    h = (h + 1) % 3;
    float m = max(min(hands[0], hands[1]), min(max(hands[0], hands[1]), hands[2]));
    handCm = m > 900 ? -1 : m;
    return;
  }

  if (!lidIsClosed() || lidMoving() || now - lidClosedMs < LID_SETTLE_MS) {
    tries = good = 0;                                    // start again after the lid closed
    return;
  }
  float d = readDistanceOnce(PIN_TRIG, PIN_ECHO, 25000UL);           // 25 ms = about 4 m
  if (!isnan(d)) v[good++] = d;
  if (++tries < 5) return;
  if (good < 3) {
    distanceCm = NAN;                                    // too many misses = sensor problem
  } else {
    for (int i = 1; i < good; i++) {                     // small insertion sort
      float x = v[i];
      int j = i - 1;
      while (j >= 0 && v[j] > x) { v[j + 1] = v[j]; j--; }
      v[j + 1] = x;
    }
    distanceCm = v[good / 2];
    float fill = (th.emptyDepthCm - distanceCm) / th.emptyDepthCm * 100.0f;
    fullLogic.add(constrain(fill, 0.0f, 100.0f), th.fullPct);
  }
  tries = good = 0;
}

// MQ-2 and flame are sampled every 2 ms in the background:
// gas = average of 8, flame = at least 3 of the last 5 (ignores single glitches)
int  gasRing[8]   = { 0 };
bool flameRing[5] = { false };
void updateFastSensors(uint32_t now) {
  static uint32_t lastMs = 0;
  static int g = 0, f = 0;
  if (now - lastMs < 2) return;
  lastMs = now;
  gasRing[g] = analogRead(PIN_MQ2);
  g = (g + 1) % 8;
  flameRing[f] = digitalRead(PIN_FLAME) == FLAME_DETECTED_LEVEL;
  f = (f + 1) % 5;
}

int readGas() {
  long sum = 0;
  for (int v : gasRing) sum += v;
  return (int)(sum / 8);
}

// Not counted while the lid is open and 2 s after it closed (sunlight)
bool readFlame(uint32_t now) {
  int hits = 0;
  for (bool b : flameRing) hits += b ? 1 : 0;
  return hits >= 3 && lidIsClosed() && now - lidClosedMs >= FLAME_AFTER_LID_MS;
}

void rememberTemp(float t, uint32_t now) {
  histTemp[histHead] = t;
  histMs[histHead] = now;
  histHead = (histHead + 1) % HIST;
  if (histCount < HIST) histCount++;
}

// How much the temperature rose: now minus the lowest value inside the window
float tempRise(uint32_t now) {
  if (isnan(tempC) || histCount == 0) return 0;
  float low = tempC;
  uint32_t windowMs = (uint32_t)th.tempRiseWindowS * 1000UL;
  for (int i = 0; i < histCount; i++) {
    if (now - histMs[i] <= windowMs && histTemp[i] < low) low = histTemp[i];
  }
  return tempC - low;
}

// ---------------------------------------------------------------------
// Saved in flash (survives a reboot): thresholds, fire ball, last command
// ---------------------------------------------------------------------
void loadSaved() {
  prefs.begin("smartbin", true);
  th.gasRaw          = prefs.getInt("gas", th.gasRaw);
  th.tempLimitC      = prefs.getFloat("tlim", th.tempLimitC);
  th.tempRiseC       = prefs.getFloat("trise", th.tempRiseC);
  th.tempRiseWindowS = prefs.getInt("twin", th.tempRiseWindowS);
  th.uploadIntervalS = prefs.getInt("upl", th.uploadIntervalS);
  th.fullPct         = prefs.getInt("full", th.fullPct);
  th.emptyDepthCm    = prefs.getFloat("depth", th.emptyDepthCm);
  ball.armed         = prefs.getBool("armed", true);     // a reboot does not forget a dropped ball
  lastCmdId          = prefs.getUInt("cmd", 0);
  prefs.end();
}

void saveThresholds() {
  prefs.begin("smartbin", false);
  prefs.putInt("gas", th.gasRaw);
  prefs.putFloat("tlim", th.tempLimitC);
  prefs.putFloat("trise", th.tempRiseC);
  prefs.putInt("twin", th.tempRiseWindowS);
  prefs.putInt("upl", th.uploadIntervalS);
  prefs.putInt("full", th.fullPct);
  prefs.putFloat("depth", th.emptyDepthCm);
  prefs.end();
}

void saveBall() {
  prefs.begin("smartbin", false);
  prefs.putBool("armed", ball.armed);
  prefs.end();
}

// Take the thresholds from the server reply (only sensible values)
void applyThresholds(JsonObject t) {
  Thresholds n = th;
  if (t["gas_raw"].is<int>())            n.gasRaw          = constrain(t["gas_raw"].as<int>(), 100, 4095);
  if (t["temp_limit_c"].is<float>())     n.tempLimitC      = constrain(t["temp_limit_c"].as<float>(), 20.0f, 80.0f);
  if (t["temp_rise_c"].is<float>())      n.tempRiseC       = constrain(t["temp_rise_c"].as<float>(), 1.0f, 30.0f);
  if (t["temp_rise_window_s"].is<int>()) n.tempRiseWindowS = constrain(t["temp_rise_window_s"].as<int>(), 10, 300);
  if (t["upload_interval_s"].is<int>())  n.uploadIntervalS = constrain(t["upload_interval_s"].as<int>(), 2, 300);
  if (t["full_pct"].is<int>())           n.fullPct         = constrain(t["full_pct"].as<int>(), 10, 100);
  if (t["empty_depth_cm"].is<float>())   n.emptyDepthCm    = constrain(t["empty_depth_cm"].as<float>(), 5.0f, 400.0f);

  if (memcmp(&n, &th, sizeof(n)) != 0) {
    th = n;
    saveThresholds();
    Serial.printf("New thresholds: gas %d, limit %.1f C, rise %.1f C in %d s, upload every %d s, full %d %%, depth %.1f cm\n",
                  th.gasRaw, th.tempLimitC, th.tempRiseC, th.tempRiseWindowS, th.uploadIntervalS, th.fullPct, th.emptyDepthCm);
  }
}

// ---------------------------------------------------------------------
// Test mode: take {"mode":"FIRE","seconds_left":23,"drop_ball":false} or null from a reply
// ---------------------------------------------------------------------
void applyTest(JsonVariant t) {
  BinState mode = NORMAL;
  int secs = 0;
  bool drop = false;
  if (t.is<JsonObject>()) {
    const char* m = t["mode"] | "";
    if (strcmp(m, "FIRE") == 0)   mode = FIRE;
    if (strcmp(m, "VAPOUR") == 0) mode = VAPOUR;
    secs = constrain(t["seconds_left"] | 0, 0, TEST_MAX_S);
    drop = t["drop_ball"] | false;
  }
  if (secs <= 0) mode = NORMAL;

  if (mode != testMode) {
    testDropped = false;
    if (mode == NORMAL) Serial.println(">>> Test stopped from the dashboard");
    else Serial.printf(">>> TEST %s from the dashboard for %d s%s\n", STATE_NAME[mode], secs,
                       drop && mode == FIRE ? " (WITH the fire ball)" : "");
  }
  testMode     = mode;
  testDropBall = drop && mode == FIRE;
  testUntilMs  = millis() + (uint32_t)secs * 1000UL;
}

// The test also ends by itself here, even if Wi-Fi is lost during it
bool testRunning(uint32_t now) {
  if (testMode != NORMAL && (int32_t)(now - testUntilMs) >= 0) {
    Serial.println(">>> Test time is over");
    testMode = NORMAL;
    testDropBall = false;
  }
  return testMode != NORMAL;
}

// ---------------------------------------------------------------------
// Wi-Fi and HTTP: all of this runs in the network task (other core)
// ---------------------------------------------------------------------
void startWifi() {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  lastWifiTryMs = millis();
}

void keepWifi(uint32_t now) {
  static bool wasConnected = false;
  bool connected = (WiFi.status() == WL_CONNECTED);
  if (connected && !wasConnected) {
    Serial.printf("Wi-Fi connected, IP %s, RSSI %d dBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    configTime(4 * 3600, 0, "pool.ntp.org", "time.google.com");   // Oman time (UTC+4) from NTP
  }
  if (!connected && now - lastWifiTryMs >= WIFI_RETRY_MS) {
    wl_status_t st = WiFi.status();
    const char* why = st == WL_NO_SSID_AVAIL   ? "network not found (name wrong, out of range or 5 GHz only?)"
                    : st == WL_CONNECT_FAILED  ? "connection failed (wrong password?)"
                    : st == WL_CONNECTION_LOST ? "connection lost"
                    : "no answer from the network";
    Serial.printf("Wi-Fi down: %s. Looking for \"%s\", trying again...\n", why, WIFI_SSID);
    WiFi.disconnect();
    WiFi.begin(WIFI_SSID, WIFI_PASS);
    lastWifiTryMs = now;
  }
  wasConnected = connected;
}

// Number with 1 decimal for JSON, or null when the sensor failed
void putNumber(JsonDocument& doc, const char* key, float v, float lo, float hi) {
  if (isnan(v)) {
    doc[key] = nullptr;
  } else {
    // The server rejects out-of-range values, so keep inside the sensor's range
    // (e.g. the DHT22 tops out at 80 C during a fire).
    doc[key] = serialized(String(constrain(v, lo, hi), 1));
  }
}

void sendJob(const Job& j) {
  static String pollUrl;
  if (pollUrl.length() == 0) {
    pollUrl = SERVER_URL;
    pollUrl.replace("ingest.php", "device_poll.php");
  }
  JsonDocument doc;
  doc["bin_id"] = BIN_ID;
  doc["token"]  = DEVICE_TOKEN;
  if (j.ackId) {
    doc["cmd_ack"]    = j.ackId;
    doc["cmd_result"] = j.ackOk ? "done" : "refused";
  }
  if (j.upload) {
    putNumber(doc, "distance_cm", j.distanceCm, 0, 400);
    doc["gas_raw"] = j.gasRaw;
    doc["flame"]   = j.flame;
    putNumber(doc, "temp_c", j.tempC, -40, 80);
    putNumber(doc, "humidity", j.humidity, 0, 100);
    doc["state"]     = STATE_NAME[j.state];
    doc["fan"]       = j.fan;
    doc["buzzer"]    = j.buzzer;
    doc["lid"]       = LID_NAME[j.lidState];
    doc["ball"]      = j.ballArmed ? "ARMED" : "RELEASED";
    putNumber(doc, "hand_cm", j.handCm, -1, 400);
    doc["lid_opens"] = j.opens;
    doc["uptime_s"]  = millis() / 1000;
    doc["rssi"]      = WiFi.RSSI();
    doc["fw"]        = FW_VERSION;
    doc["test"]      = j.test;
  }
  String body;
  serializeJson(doc, body);

  int timeout = j.upload ? HTTP_TIMEOUT_MS : TEST_POLL_TIMEOUT_MS;
  HTTPClient http;
  http.setConnectTimeout(timeout);
  http.setTimeout(timeout);
  http.begin(j.upload ? String(SERVER_URL) : pollUrl);
  http.addHeader("Content-Type", "application/json");
  Answer a;
  a.upload = j.upload;
  a.ackId  = j.ackId;
  a.code   = http.POST(body);
  String reply = a.code > 0 ? http.getString() : "";
  http.end();
  a.body = strdup(reply.c_str());
  if (a.body && xQueueSend(answerQ, &a, pdMS_TO_TICKS(200)) != pdTRUE) free(a.body);
}

void networkTask(void*) {
  startWifi();
  for (;;) {
    Job j;
    if (xQueueReceive(jobQ, &j, pdMS_TO_TICKS(50)) == pdTRUE) {
      netBusy = true;
      if (WiFi.status() == WL_CONNECTED) sendJob(j);
      netBusy = false;
    }
    keepWifi(millis());
  }
}

// ---------------------------------------------------------------------
// Server answers and commands (main loop)
// ---------------------------------------------------------------------
Job makeJob(bool upload) {
  Job j;
  j.upload     = upload;
  j.distanceCm = distanceCm;
  j.tempC      = tempC;
  j.humidity   = humidity;
  j.handCm     = handCm;
  j.gasRaw     = gasRaw;
  j.flame      = flame;
  j.fan        = fanOn;
  j.buzzer     = buzzerOn;
  j.state      = state;
  j.test       = (state != realState);   // this state comes from a test, not the sensors
  j.lidState   = lid.state;
  j.opens      = lid.opens;
  j.ballArmed  = ball.armed;
  j.ackId      = ackId;
  j.ackOk      = lastCmdOk;
  return j;
}

// Command from the dashboard: {"id":41,"name":"lid_open"}
void applyCommand(JsonVariant c, uint32_t now) {
  if (!c.is<JsonObject>()) return;
  uint32_t id = c["id"].as<uint32_t>();
  const char* name = c["name"] | "";
  if (id == 0) return;
  if (id == lastCmdId) { ackId = id; return; }      // already done: only tell the server again

  bool ok = false;
  if (strcmp(name, "lid_open") == 0)       ok = lid.openService(now, state == FIRE);
  else if (strcmp(name, "lid_close") == 0) { lid.closeService(); ok = true; }
  else if (strcmp(name, "arm_ball") == 0) {
    ok = state != FIRE && ball.arm();               // a person hangs the ball first; never during a fire
    if (ok) saveBall();
  }
  Serial.printf(">>> Command %lu %s: %s\n", (unsigned long)id, name, okText(ok));
  lastCmdId = id;
  lastCmdOk = ok;
  ackId     = id;
  uploadNow = true;
  prefs.begin("smartbin", false);
  prefs.putUInt("cmd", lastCmdId);
  prefs.end();
}

void handleAnswers(uint32_t now) {
  Answer a;
  while (xQueueReceive(answerQ, &a, 0) == pdTRUE) {
    if (a.code == 200) {
      JsonDocument r;
      if (!deserializeJson(r, a.body) && r["ok"] == true) {
        lastServerOkMs = now;
        if (a.ackId && a.ackId == ackId) ackId = 0;      // the server knows the result now
        if (r["thresholds"].is<JsonObject>()) applyThresholds(r["thresholds"].as<JsonObject>());
        applyTest(r["test"]);
        applyCommand(r["cmd"], now);
      }
      if (a.upload) Serial.printf("Upload OK (%s)\n", STATE_NAME[state]);
    } else if (a.upload) {
      if (a.code == 401)      Serial.println("Upload REJECTED: invalid token. Check BIN_ID / DEVICE_TOKEN in secrets.h");
      else if (a.code == 400) Serial.printf("Upload REJECTED (400): %s\n", a.body);
      else if (a.code > 0)    Serial.printf("Upload failed: HTTP %d\n", a.code);
      else Serial.printf("Upload failed: %s (is SERVER_URL right? %s)\n",
                         HTTPClient::errorToString(a.code).c_str(), SERVER_URL);
    }
    free(a.body);
  }
}

// Upload when it is time, ask for tests and commands in between
void updateNetwork(uint32_t now) {
  bool wifi = WiFi.status() == WL_CONNECTED;
  uint32_t interval = (state == NORMAL) ? (uint32_t)th.uploadIntervalS * 1000UL : HAZARD_UPLOAD_MS;
  if (uploadNow || !everUploaded || now - lastUploadMs >= interval) {
    uploadNow    = false;
    everUploaded = true;
    lastUploadMs = now;
    lastPollMs   = now;          // the upload reply carries the test and the command too
    if (!wifi) {
      Serial.println("Upload skipped: no Wi-Fi (safety still works)");
      return;
    }
    Job j = makeJob(true);
    xQueueOverwrite(jobQ, &j);   // only the newest reading matters
  } else if (wifi && now - lastPollMs >= TEST_POLL_MS && !netBusy && uxQueueMessagesWaiting(jobQ) == 0) {
    lastPollMs = now;
    Job j = makeJob(false);
    xQueueOverwrite(jobQ, &j);
  }
}

// ---------------------------------------------------------------------
// Lid and fire ball: every pass of the loop, never waits
// ---------------------------------------------------------------------
void updateLidAndBall(uint32_t now) {
  // ---- lid decision (a fire locks it at once) ----
  bool online = now - lastServerOkMs < OFFLINE_UNLOCK_MS;
  bool hand   = handCm >= 0 && handCm <= HAND_CM;
  LidState before = lid.state;
  lid.update(now, state == FIRE, fullLogic.full, online, hand);
  if (lid.beepNow) { beeping = true; beepMs = now; }
  if (lid.state != before) {
    Serial.printf(">>> Lid %s -> %s\n", LID_NAME[before], LID_NAME[lid.state]);
    bool handOnly = (before == LID_CLOSED || before == LID_OPEN_HAND)
                 && (lid.state == LID_CLOSED || lid.state == LID_OPEN_HAND);
    if (!handOnly) uploadNow = true;              // a hand opening waits for the normal upload
  }

  // ---- fire ball: a confirmed real fire, or a test that asked for it ----
  bool flameLong = flameRun.update(now, readFlame(now));
  bool confirmed = realState == FIRE && (flameLong || heatSign);
  bool testDrop  = state == FIRE && testMode == FIRE && testDropBall && !testDropped;
  if (ball.armed && !ball.busy() && (confirmed || testDrop)) {
    Serial.printf(">>> FIRE %s: dropping the fire ball\n", confirmed ? "CONFIRMED" : "TEST");
    if (testDrop) testDropped = true;
    ball.drop();
  }
  bool wasAtRelease = ball.atRelease;
  if (ball.update(now, lidMoving())) {            // waits until the lid has stopped
    saveBall();
    uploadNow = true;
    Serial.println(">>> Fire ball RELEASED. Hang a new one, then re-arm it from the dashboard");
  }
  if (ball.atRelease != wasAtRelease) ballServo.write(ball.atRelease ? BALL_RELEASE_DEG : BALL_ARMED_DEG);

  // ---- lid servo: small steps, never while the ball servo moves (too much current) ----
  int target = lid.isOpen() ? LID_OPEN_DEG : LID_CLOSED_DEG;
  if (lidPos != target && !ball.moving() && now - lidStepMs >= LID_STEP_MS) {
    lidStepMs = now;
    lidPos += constrain(target - lidPos, -LID_STEP_DEG, LID_STEP_DEG);
    lidServo.write(lidPos);
    if (lidIsClosed()) lidClosedMs = now;
  }
}

// ---------------------------------------------------------------------
// Serial commands (type "help")
// ---------------------------------------------------------------------
void printStatus(uint32_t now) {
  Serial.printf("state %s%s | fan %s | alarm %s | gas %d | flame %s\n", STATE_NAME[state],
                state != realState ? " (TEST)" : "", fanOn ? "ON" : "off", buzzerOn ? "ON" : "off",
                gasRaw, flame ? "YES" : "no");
  Serial.printf("lid %s at %d deg | opened %lu times | full %s (limit %d %%) | hand %.1f cm\n",
                LID_NAME[lid.state], lidPos, (unsigned long)lid.opens, fullLogic.full ? "YES" : "no", th.fullPct, handCm);
  Serial.printf("fire ball %s\n", ball.armed ? "ARMED" : "RELEASED");
  Serial.printf("wifi %s | last server answer %lu s ago%s\n", WiFi.status() == WL_CONNECTED ? "ok" : "down",
                (unsigned long)((now - lastServerOkMs) / 1000),
                now - lastServerOkMs >= OFFLINE_UNLOCK_MS ? " (full lock is OFF)" : "");
}

void serialCommand(String s, uint32_t now) {
  static uint32_t askMs = 0;
  static bool     asking = false;
  if (asking) {
    asking = false;
    if (s == "YES" && now - askMs < 15000UL) { ball.drop(); Serial.println("Dropping the fire ball"); }
    else Serial.println("Not dropped");
    return;
  }
  s.toLowerCase();
  if (s == "status" || s == "lid status") printStatus(now);
  else if (s == "lid open")   Serial.printf("lid open: %s\n", okText(lid.openService(now, state == FIRE)));
  else if (s == "lid close")  { lid.closeService(); Serial.println("lid close: ok"); }
  else if (s == "hand")       Serial.printf("hand sensor: %.1f cm%s\n", handCm, handCm < 0 ? " (nothing in front)" : "");
  else if (s == "ball arm") {
    bool ok = state != FIRE && ball.arm();
    if (ok) { saveBall(); uploadNow = true; }
    Serial.printf("ball arm: %s\n", okText(ok));
  } else if (s == "ball drop") {
    if (!ball.armed) { Serial.println("The ball was already released. Hang it, then type: ball arm"); return; }
    asking = true;
    askMs = now;
    Serial.println("Really drop the fire ball? Type YES to confirm");
  } else {
    Serial.println("Commands: status | lid open | lid close | lid status | ball drop | ball arm | hand");
  }
}

void readSerial(uint32_t now) {
  static String line;
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n' || c == '\r') {
      line.trim();
      if (line.length()) serialCommand(line, now);
      line = "";
    } else if (line.length() < 40) {
      line += c;
    }
  }
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  delay(200);

  // Outputs OFF first thing (the relay is active LOW, so drive it HIGH right away).
  // pinMode comes first: this ESP32 core ignores a digitalWrite before it.
  pinMode(PIN_RELAY, OUTPUT);
  setFan(false);
  pinMode(PIN_BUZZER, OUTPUT);
  updateBuzzer(millis());

  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);
  pinMode(PIN_TRIG2, OUTPUT);
  pinMode(PIN_ECHO2, INPUT);
  pinMode(PIN_FLAME, INPUT);
  analogReadResolution(12);                  // 0..4095
  analogSetAttenuation(ADC_11db);            // full 0..3.3 V range
  dht.begin();

  loadSaved();
  Serial.printf("\nSmart Bin %s  (%s)\n", FW_VERSION, BIN_ID);
  Serial.printf("Thresholds: gas %d, limit %.1f C, rise %.1f C in %d s, upload every %d s, full %d %%, depth %.1f cm\n",
                th.gasRaw, th.tempLimitC, th.tempRiseC, th.tempRiseWindowS, th.uploadIntervalS, th.fullPct, th.emptyDepthCm);
  Serial.printf("MQ-2 warming up for %d s (gas ignored until then)\n", MQ2_WARMUP_S);
  Serial.printf("Fire ball %s. Type help for the Serial commands\n", ball.armed ? "ARMED" : "RELEASED");

  // Servos one after the other. They get no signal until the first write(),
  // so the ball arm is not thrown to the middle on power-up.
  lidServo.setPeriodHertz(50);
  lidServo.attach(PIN_SERVO_LID, SERVO_MIN_US, SERVO_MAX_US);
  lidServo.write(LID_CLOSED_DEG);
  delay(700);
  ballServo.setPeriodHertz(50);
  ballServo.attach(PIN_SERVO_BALL, SERVO_MIN_US, SERVO_MAX_US);
  ballServo.write(BALL_ARMED_DEG);
  delay(300);
  lidClosedMs = millis();

  jobQ    = xQueueCreate(1, sizeof(Job));
  answerQ = xQueueCreate(4, sizeof(Answer));
  xTaskCreatePinnedToCore(networkTask, "net", 10240, nullptr, 1, nullptr, 0);   // loop() runs on core 1
}

// ---------------------------------------------------------------------
// The safety pass: once a second
// ---------------------------------------------------------------------
void safetyPass(uint32_t now) {
  // ---- 1. read the sensors (the ultrasonics are measured in updateSonars) ----
  gasRaw = readGas();
  flame  = readFlame(now);
  if (now - lastDhtMs >= DHT_MS || lastDhtMs == 0) {
    lastDhtMs = now;
    float t = dht.readTemperature();
    float h = dht.readHumidity();
    tempC    = t;                 // NAN if the read failed
    humidity = h;
    if (!isnan(t)) rememberTemp(t, now);
  }

  // ---- 2. decide the state (fire first, it is the most dangerous) ----
  bool warmingUp = (now / 1000) < MQ2_WARMUP_S;
  float rise     = tempRise(now);
  heatSign       = (!isnan(tempC) && tempC >= th.tempLimitC) || rise >= th.tempRiseC;
  bool fireSign  = flame || heatSign;
  bool gasHigh   = !warmingUp && gasRaw >= th.gasRaw;
  bool gasClear  = gasRaw < th.gasRaw * VAPOUR_CLEAR_PCT / 100;

  if (fireSign) lastFireSignMs = now;
  if (gasHigh)  lastGasHighMs  = now;

  BinState realNext;
  if (fireSign || (realState == FIRE && now - lastFireSignMs < FIRE_HOLD_MS)) {
    realNext = FIRE;
  } else if (gasHigh || (realState == VAPOUR && (!gasClear || now - lastGasHighMs < VAPOUR_HOLD_MS))) {
    realNext = VAPOUR;
  } else {
    realNext = NORMAL;
  }
  realState = realNext;

  // A test from the dashboard only counts if it is MORE severe than what the
  // sensors see (a real fire during a gas test is still a fire).
  BinState next = realNext;
  if (testRunning(now) && testMode > realNext) next = testMode;

  // ---- outputs: work without any network ----
  switch (next) {
    case FIRE:   setFan(false); buzzerOn = true;  break;   // fan off: no air for the fire
    case VAPOUR: setFan(true);  buzzerOn = false; break;   // vent the vapour out
    default:     setFan(false); buzzerOn = false; break;
  }

  if (next != state) {
    uploadNow = true;                     // an alert never waits for the 10 s timer
    Serial.printf(">>> State %s -> %s%s\n", STATE_NAME[state], STATE_NAME[next],
                  next != realNext ? " (TEST)" : "");
  }
  state = next;

  // ---- serial log (use it to calibrate the gas threshold in clean air) ----
  char clock[9] = "--:--:--";
  time_t secs = time(nullptr);
  if (secs > 1700000000) {                // the clock was set by NTP
    struct tm tmNow;
    localtime_r(&secs, &tmNow);
    strftime(clock, sizeof(clock), "%H:%M:%S", &tmNow);
  }
  Serial.printf("[%s] dist %s cm | gas %d%s | flame %s | T %s C (rise %.1f) | H %s %% | %s%s | fan %s buzzer %s | lid %s | ball %s | wifi %s\n",
                clock,
                isnan(distanceCm) ? "--" : String(distanceCm, 1).c_str(),
                gasRaw, warmingUp ? " (warm-up)" : "",
                flame ? "YES" : "no",
                isnan(tempC) ? "--" : String(tempC, 1).c_str(), rise,
                isnan(humidity) ? "--" : String(humidity, 0).c_str(),
                STATE_NAME[state], state != realState ? " (TEST)" : "", fanOn ? "ON" : "off", buzzerOn ? "ON" : "off",
                LID_NAME[lid.state], ball.armed ? "armed" : "RELEASED",
                WiFi.status() == WL_CONNECTED ? "ok" : "down");
}

// ---------------------------------------------------------------------
// Main loop: the lid, ball and buzzer all the time, the safety pass once a second
// ---------------------------------------------------------------------
void loop() {
  readSerial(millis());
  handleAnswers(millis());
  updateFastSensors(millis());
  updateSonars(millis());            // up to 25 ms while it waits for an echo
  uint32_t now = millis();
  if (now - lastLoopMs >= LOOP_MS) {
    lastLoopMs = now;
    safetyPass(now);
  }
  updateLidAndBall(now);
  updateBuzzer(now);
  updateNetwork(now);
  vTaskDelay(1);                     // 1 ms: let the system tasks run
}

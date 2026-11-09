// =====================================================================
// Smart Bin - ESP32 bin unit firmware
// Board: ESP32 Dev Module (ESP32 DevKit V1, ESP-WROOM-32)
// Libraries: "DHT sensor library" (Adafruit) + "Adafruit Unified Sensor",
//            "ArduinoJson" (v7)
//
// What it does, once a second (flowchart, Figure 2.6):
//   1. read the sensors (ultrasonic = median of 5, MQ-2, flame, DHT22)
//   2. decide the state, most dangerous first:
//        FIRE   = flame, OR temperature >= limit, OR a sudden rise
//                 -> fan OFF (do not feed the fire), buzzer ON
//        VAPOUR = gas >= threshold -> fan ON to vent, buzzer OFF
//        NORMAL -> fan OFF, buzzer OFF
//      This happens HERE on the bin, so it works without Wi-Fi or server.
//   3. upload to the server: at once when the state changes, every 2 s
//      during a hazard, otherwise every upload_interval_s (10 s).
//   4. take the thresholds from the server's reply and save them in flash,
//      so the operator can change them without reprogramming.
// =====================================================================

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <DHT.h>
#include <Preferences.h>
#include <time.h>

#include "config.h"
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
};
Thresholds th = { DEF_GAS_RAW, DEF_TEMP_LIMIT_C, DEF_TEMP_RISE_C, DEF_TEMP_RISE_WIN_S, DEF_UPLOAD_S };

enum BinState { NORMAL, VAPOUR, FIRE };
const char* STATE_NAME[] = { "NORMAL", "VAPOUR", "FIRE" };

DHT dht(PIN_DHT, DHT22);
Preferences prefs;

// ---------------------------------------------------------------------
// Latest sensor values (NAN = the sensor failed this time)
// ---------------------------------------------------------------------
float distanceCm = NAN;
float tempC      = NAN;
float humidity   = NAN;
int   gasRaw     = 0;
bool  flame      = false;

BinState state   = NORMAL;
bool fanOn       = false;
bool buzzerOn    = false;

// Temperature history for the "sudden rise" check (one sample per DHT read)
const int HIST = 160;                  // 160 x 2 s = 320 s, enough for a 300 s window
float    histTemp[HIST];
uint32_t histMs[HIST];
int      histCount = 0, histHead = 0;

uint32_t lastLoopMs = 0, lastDhtMs = 0, lastUploadMs = 0, lastWifiTryMs = 0;
uint32_t lastFireSignMs = 0, lastGasHighMs = 0;
bool     everUploaded = false;

// ---------------------------------------------------------------------
// Outputs
// ---------------------------------------------------------------------
void setFan(bool on) {
  fanOn = on;
  digitalWrite(PIN_RELAY, on ? RELAY_ON_LEVEL : !RELAY_ON_LEVEL);
}

void setBuzzer(bool on) {
  buzzerOn = on;
  digitalWrite(PIN_BUZZER, on ? BUZZER_ON_LEVEL : !BUZZER_ON_LEVEL);
}

// ---------------------------------------------------------------------
// Sensors
// ---------------------------------------------------------------------
// One HC-SR04 measurement in cm, or NAN if there was no echo
float readDistanceOnce() {
  digitalWrite(PIN_TRIG, LOW);
  delayMicroseconds(2);
  digitalWrite(PIN_TRIG, HIGH);
  delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  unsigned long us = pulseIn(PIN_ECHO, HIGH, 25000UL);   // 25 ms = about 4 m
  if (us == 0) return NAN;
  float cm = us * 0.0343f / 2.0f;                        // speed of sound, there and back
  if (cm < 2 || cm > 400) return NAN;                    // outside the sensor's range
  return cm;
}

// Median of 5 readings: removes the odd wrong echo from a bag edge or the wall
float readDistance() {
  float v[5];
  int n = 0;
  for (int i = 0; i < 5; i++) {
    float d = readDistanceOnce();
    if (!isnan(d)) v[n++] = d;
    delay(30);                                           // let the echo die out
  }
  if (n < 3) return NAN;                                 // too many misses = sensor problem
  for (int i = 1; i < n; i++) {                          // small insertion sort
    float x = v[i];
    int j = i - 1;
    while (j >= 0 && v[j] > x) { v[j + 1] = v[j]; j--; }
    v[j + 1] = x;
  }
  return v[n / 2];
}

// MQ-2: average of 8 ADC reads (0..4095)
int readGas() {
  long sum = 0;
  for (int i = 0; i < 8; i++) {
    sum += analogRead(PIN_MQ2);
    delay(2);
  }
  return (int)(sum / 8);
}

// Flame: at least 3 of 5 quick reads must see a flame (ignores single glitches)
bool readFlame() {
  int hits = 0;
  for (int i = 0; i < 5; i++) {
    if (digitalRead(PIN_FLAME) == FLAME_DETECTED_LEVEL) hits++;
    delay(2);
  }
  return hits >= 3;
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
// Thresholds in flash (survive a reboot)
// ---------------------------------------------------------------------
void loadThresholds() {
  prefs.begin("smartbin", true);
  th.gasRaw          = prefs.getInt("gas", th.gasRaw);
  th.tempLimitC      = prefs.getFloat("tlim", th.tempLimitC);
  th.tempRiseC       = prefs.getFloat("trise", th.tempRiseC);
  th.tempRiseWindowS = prefs.getInt("twin", th.tempRiseWindowS);
  th.uploadIntervalS = prefs.getInt("upl", th.uploadIntervalS);
  prefs.end();
}

void saveThresholds() {
  prefs.begin("smartbin", false);
  prefs.putInt("gas", th.gasRaw);
  prefs.putFloat("tlim", th.tempLimitC);
  prefs.putFloat("trise", th.tempRiseC);
  prefs.putInt("twin", th.tempRiseWindowS);
  prefs.putInt("upl", th.uploadIntervalS);
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

  if (memcmp(&n, &th, sizeof(n)) != 0) {
    th = n;
    saveThresholds();
    Serial.printf("New thresholds: gas %d, limit %.1f C, rise %.1f C in %d s, upload every %d s\n",
                  th.gasRaw, th.tempLimitC, th.tempRiseC, th.tempRiseWindowS, th.uploadIntervalS);
  }
}

// ---------------------------------------------------------------------
// Wi-Fi + upload
// ---------------------------------------------------------------------
void startWifi() {
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  lastWifiTryMs = millis();
}

// Keep trying in the background; never blocks the safety loop
void keepWifi(uint32_t now) {
  static bool wasConnected = false;
  bool connected = (WiFi.status() == WL_CONNECTED);
  if (connected && !wasConnected) {
    Serial.printf("Wi-Fi connected, IP %s, RSSI %d dBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    configTime(4 * 3600, 0, "pool.ntp.org", "time.google.com");   // Oman time (UTC+4) from NTP
  }
  if (!connected && now - lastWifiTryMs >= WIFI_RETRY_MS) {
    Serial.println("Wi-Fi down, trying again...");
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

void upload() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Upload skipped: no Wi-Fi (safety still works)");
    return;
  }

  JsonDocument doc;
  doc["bin_id"] = BIN_ID;
  doc["token"]  = DEVICE_TOKEN;
  putNumber(doc, "distance_cm", distanceCm, 0, 400);
  doc["gas_raw"] = gasRaw;
  doc["flame"]   = flame;
  putNumber(doc, "temp_c", tempC, -40, 80);
  putNumber(doc, "humidity", humidity, 0, 100);
  doc["state"]    = STATE_NAME[state];
  doc["fan"]      = fanOn;
  doc["buzzer"]   = buzzerOn;
  doc["uptime_s"] = millis() / 1000;
  doc["rssi"]     = WiFi.RSSI();
  doc["fw"]       = FW_VERSION;

  String body;
  serializeJson(doc, body);

  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.begin(SERVER_URL);
  http.addHeader("Content-Type", "application/json");
  int code = http.POST(body);
  String reply = code > 0 ? http.getString() : "";
  http.end();

  if (code == 200) {
    JsonDocument r;
    if (!deserializeJson(r, reply) && r["ok"] == true) {
      applyThresholds(r["thresholds"].as<JsonObject>());
    }
    Serial.printf("Upload OK (%s)\n", STATE_NAME[state]);
  } else if (code == 401) {
    Serial.println("Upload REJECTED: invalid token. Check BIN_ID / DEVICE_TOKEN in secrets.h");
  } else if (code == 400) {
    Serial.printf("Upload REJECTED (400): %s\n", reply.c_str());
  } else if (code > 0) {
    Serial.printf("Upload failed: HTTP %d\n", code);
  } else {
    Serial.printf("Upload failed: %s (is SERVER_URL right? %s)\n",
                  http.errorToString(code).c_str(), SERVER_URL);
  }
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  delay(200);

  // Outputs OFF first thing (relay is active LOW, so drive it HIGH right away)
  pinMode(PIN_RELAY, OUTPUT);
  setFan(false);
  pinMode(PIN_BUZZER, OUTPUT);
  setBuzzer(false);

  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);
  pinMode(PIN_FLAME, INPUT);
  analogReadResolution(12);                  // 0..4095
  analogSetAttenuation(ADC_11db);            // full 0..3.3 V range
  dht.begin();

  loadThresholds();
  Serial.printf("\nSmart Bin %s  (%s)\n", FW_VERSION, BIN_ID);
  Serial.printf("Thresholds: gas %d, limit %.1f C, rise %.1f C in %d s, upload every %d s\n",
                th.gasRaw, th.tempLimitC, th.tempRiseC, th.tempRiseWindowS, th.uploadIntervalS);
  Serial.printf("MQ-2 warming up for %d s (gas ignored until then)\n", MQ2_WARMUP_S);

  startWifi();
}

// ---------------------------------------------------------------------
// Main loop: one pass per second
// ---------------------------------------------------------------------
void loop() {
  uint32_t now = millis();
  keepWifi(now);
  if (now - lastLoopMs < LOOP_MS) {
    delay(5);
    return;
  }
  lastLoopMs = now;

  // ---- 1. read the sensors ----
  distanceCm = readDistance();
  gasRaw     = readGas();
  flame      = readFlame();
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
  bool fireSign  = flame
                || (!isnan(tempC) && tempC >= th.tempLimitC)
                || rise >= th.tempRiseC;
  bool gasHigh   = !warmingUp && gasRaw >= th.gasRaw;
  bool gasClear  = gasRaw < th.gasRaw * VAPOUR_CLEAR_PCT / 100;

  if (fireSign) lastFireSignMs = now;
  if (gasHigh)  lastGasHighMs  = now;

  BinState next;
  if (fireSign || (state == FIRE && now - lastFireSignMs < FIRE_HOLD_MS)) {
    next = FIRE;
  } else if (gasHigh || (state == VAPOUR && (!gasClear || now - lastGasHighMs < VAPOUR_HOLD_MS))) {
    next = VAPOUR;
  } else {
    next = NORMAL;
  }

  // ---- outputs: work without any network ----
  switch (next) {
    case FIRE:   setFan(false); setBuzzer(true);  break;   // fan off: no air for the fire
    case VAPOUR: setFan(true);  setBuzzer(false); break;   // vent the vapour out
    default:     setFan(false); setBuzzer(false); break;
  }

  bool changed = (next != state);
  if (changed) {
    Serial.printf(">>> State %s -> %s\n", STATE_NAME[state], STATE_NAME[next]);
  }
  state = next;

  // ---- serial log (use it to calibrate the gas threshold in clean air) ----
  struct tm tmNow;
  char clock[9] = "--:--:--";
  if (getLocalTime(&tmNow, 0)) strftime(clock, sizeof(clock), "%H:%M:%S", &tmNow);
  Serial.printf("[%s] dist %s cm | gas %d%s | flame %s | T %s C (rise %.1f) | H %s %% | %s | fan %s buzzer %s | wifi %s\n",
                clock,
                isnan(distanceCm) ? "--" : String(distanceCm, 1).c_str(),
                gasRaw, warmingUp ? " (warm-up)" : "",
                flame ? "YES" : "no",
                isnan(tempC) ? "--" : String(tempC, 1).c_str(), rise,
                isnan(humidity) ? "--" : String(humidity, 0).c_str(),
                STATE_NAME[state], fanOn ? "ON" : "off", buzzerOn ? "ON" : "off",
                WiFi.status() == WL_CONNECTED ? "ok" : "down");

  // ---- 3. upload: at once on a change, faster during a hazard ----
  uint32_t interval = (state == NORMAL) ? (uint32_t)th.uploadIntervalS * 1000UL : HAZARD_UPLOAD_MS;
  if (changed || !everUploaded || now - lastUploadMs >= interval) {
    lastUploadMs = now;
    everUploaded = true;
    upload();
  }
}

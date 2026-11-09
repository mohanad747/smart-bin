// =====================================================================
// Smart Bin firmware - pins and timing (no secrets here).
// Pins follow Table 2.2 of our first review report.
// =====================================================================
#pragma once

#define FW_VERSION "1.0.0"

// ---------------- pins ----------------
#define PIN_TRIG     19   // HC-SR04 TRIG (3.3 V pulse is enough)
#define PIN_ECHO     18   // HC-SR04 ECHO through the 10k / 20k divider
#define PIN_MQ2      34   // MQ-2 AO through the 10k / 20k divider (ADC1, works with Wi-Fi on)
#define PIN_FLAME    27   // flame sensor DO, LOW when it sees a flame
#define PIN_DHT       4   // DHT22 data
#define PIN_RELAY    26   // relay IN, fan runs when LOW
#define PIN_BUZZER   25   // active buzzer module S pin

// Levels: change here if your module works the other way round
#define RELAY_ON_LEVEL        LOW    // report: "relay switches on when IN is LOW"
#define BUZZER_ON_LEVEL       HIGH   // most 3-pin active buzzer modules; use LOW for "low level trigger" boards
#define FLAME_DETECTED_LEVEL  LOW

// ---------------- timing ----------------
#define LOOP_MS            1000UL   // one sensor + safety pass per second (flowchart, Figure 2.6)
#define DHT_MS             2000UL   // the DHT22 cannot be read faster than every 2 s
#define HAZARD_UPLOAD_MS   2000UL   // while VAPOUR / FIRE: upload every 2 s (normal uses the server's interval)
#define HTTP_TIMEOUT_MS    4000     // never wait longer than this for the server
#define WIFI_RETRY_MS     15000UL   // try to reconnect every 15 s when Wi-Fi is down
#define MQ2_WARMUP_S         60     // MQ-2 heater warm-up: gas is ignored before this (it reads high)

// Hold times stop the state flickering on the edge of a threshold
#define FIRE_HOLD_MS      30000UL   // stay in FIRE 30 s after the last fire sign
#define VAPOUR_HOLD_MS    10000UL   // stay in VAPOUR 10 s after gas drops ...
#define VAPOUR_CLEAR_PCT     90     // ... below 90 % of the gas threshold

// Default thresholds, used until the first server reply (then saved in flash)
#define DEF_GAS_RAW          1800
#define DEF_TEMP_LIMIT_C     60.0
#define DEF_TEMP_RISE_C       5.0
#define DEF_TEMP_RISE_WIN_S    60
#define DEF_UPLOAD_S           10

// =====================================================================
// Smart Bin firmware - pins and timing (no secrets here).
// Pins follow Table 2.2 of our first review report.
// =====================================================================
#pragma once

#define FW_VERSION "1.3.0"

// ---------------- pins ----------------
#define PIN_TRIG     19   // HC-SR04 #1 (fill level) TRIG (3.3 V pulse is enough)
#define PIN_ECHO     18   // HC-SR04 #1 ECHO through the 10k / 20k divider
#define PIN_TRIG2    23   // HC-SR04 #2 (hand near the lid) TRIG
#define PIN_ECHO2    22   // HC-SR04 #2 ECHO through the 10k / 20k divider
#define PIN_MQ2      34   // MQ-2 AO through the 10k / 20k divider (ADC1, works with Wi-Fi on)
#define PIN_FLAME    27   // flame sensor DO, LOW when it sees a flame
#define PIN_DHT       4   // DHT22 data
#define PIN_RELAY    26   // relay IN, fan runs when LOW
#define PIN_BUZZER   25   // active buzzer module S pin
#define PIN_SERVO_BALL 13 // SG90 #1, fire ball release
#define PIN_SERVO_LID  33 // SG90 #2, lid flap

// Levels: change here if your module works the other way round
#define RELAY_ON_LEVEL        LOW    // report: "relay switches on when IN is LOW"
#define BUZZER_ON_LEVEL       HIGH   // most 3-pin active buzzer modules; use LOW for "low level trigger" boards
#define FLAME_DETECTED_LEVEL  LOW

// ---------------- servo angles (from t5_lid and t6_ball, WIRING.md) ----------------
#define LID_CLOSED_DEG     0
#define LID_OPEN_DEG      95
#define BALL_ARMED_DEG     0
#define BALL_RELEASE_DEG  90
#define SERVO_MIN_US     500
#define SERVO_MAX_US    2400

// ---------------- lid ----------------
#define HAND_CM             20        // something this close to the front sensor = a hand or bag
#define LID_CLOSE_DELAY_MS  4000UL    // close 4 s after the hand leaves
#define LID_SERVICE_MAX_MS  120000UL  // dashboard "Open for emptying" closes itself after 2 min
#define LID_STEP_DEG        2         // move 2 degrees ...
#define LID_STEP_MS         15UL      // ... every 15 ms: the flap does not slam
#define LID_SETTLE_MS       500UL     // wait this long after closing before measuring the fill level
#define FLAME_AFTER_LID_MS  2000UL    // ignore the flame sensor until 2 s after the lid closed (sunlight)
#define FULL_BEEP_GAP_MS    5000UL    // at most one double beep every 5 s when locked full
#define FULL_UNLOCK_PCT     5         // unlock when the fill is 5 points under "full" (no flapping at the edge)
#define OFFLINE_UNLOCK_MS   300000UL  // 5 min without server contact: the full lock is off

// ---------------- fire ball ----------------
#define BALL_FLAME_MS       3000UL    // the ball drops after the flame was seen this long ...
#define BALL_FLAME_GAP_MS   400UL     // ... (a real flame flickers: gaps up to this are allowed)
#define BALL_HOLD_MS        1000UL    // stay at the release angle this long
#define BALL_BACK_MS        600UL     // time to turn back to the armed angle

// ---------------- timing ----------------
#define SONAR_SLOT_MS        60UL   // the two HC-SR04s take turns, never together
#define LOOP_MS            1000UL   // one sensor + safety pass per second (flowchart, Figure 2.5)
#define DHT_MS             2000UL   // the DHT22 cannot be read faster than every 2 s
#define HAZARD_UPLOAD_MS   2000UL   // while VAPOUR / FIRE: upload every 2 s (normal uses the server's interval)
#define HTTP_TIMEOUT_MS    4000     // never wait longer than this for the server
#define WIFI_RETRY_MS     15000UL   // try to reconnect every 15 s when Wi-Fi is down
#define MQ2_WARMUP_S         60     // MQ-2 heater warm-up: gas is ignored before this (it reads high)
#define TEST_POLL_MS       2000UL   // ask the server "any test or command for me?" this often
#define TEST_POLL_TIMEOUT_MS 1500   // the poll is small: give up quickly so the loop never stalls
#define TEST_MAX_S          120     // a test never runs longer than this, whatever the server says

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
#define DEF_FULL_PCT           90
#define DEF_EMPTY_DEPTH_CM   40.0

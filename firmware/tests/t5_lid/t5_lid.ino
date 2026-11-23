// Stage 5 (WIRING.md): HC-SR04 #2 and the lid servo. Serial Monitor at 115200.
// Library: ESP32Servo.  START WITH THE ARM NOT JOINED TO THE FLAP.
// The flap opens when something is within 20 cm and closes 4 s after it leaves.
// Type:  angle 40            = go to 40 degrees and stay there (finds our real angles)
//        closed 0 / open 95  = set the two angles
//        auto                = back to the sensor
#include <ESP32Servo.h>
#define PIN_TRIG2     23
#define PIN_ECHO2     22
#define PIN_SERVO_LID 33

Servo lid;
int closedDeg = 0, openDeg = 95;
int pos = 0, target = 0;
bool manual = false, handEver = false;
uint32_t lastStep = 0, lastRead = 0, lastHand = 0, lastPrint = 0;
float hist[3] = { 999, 999, 999 };        // 999 = no echo, nothing near
int   hi = 0;

float once() {
  digitalWrite(PIN_TRIG2, LOW);  delayMicroseconds(2);
  digitalWrite(PIN_TRIG2, HIGH); delayMicroseconds(10);
  digitalWrite(PIN_TRIG2, LOW);
  unsigned long us = pulseIn(PIN_ECHO2, HIGH, 12000UL);
  return us == 0 ? 999 : us * 0.0343f / 2;
}

float median3() {
  return max(min(hist[0], hist[1]), min(max(hist[0], hist[1]), hist[2]));
}

void setup() {
  Serial.begin(115200);
  pinMode(PIN_TRIG2, OUTPUT);
  pinMode(PIN_ECHO2, INPUT);
  lid.setPeriodHertz(50);
  lid.attach(PIN_SERVO_LID, 500, 2400);
  lid.write(closedDeg);
  Serial.println("\nt5_lid. Type: angle <n> | closed <n> | open <n> | auto");
}

void loop() {
  uint32_t now = millis();
  if (now - lastRead >= 120) {
    lastRead = now;
    hist[hi] = once();
    hi = (hi + 1) % 3;
    if (median3() <= 20) { lastHand = now; handEver = true; }
  }
  if (!manual) target = (handEver && now - lastHand < 4000) ? openDeg : closedDeg;
  if (now - lastStep >= 15 && pos != target) {          // 2 degrees every 15 ms: slow and gentle
    lastStep = now;
    pos += constrain(target - pos, -2, 2);
    lid.write(pos);
  }
  if (now - lastPrint >= 500) {
    lastPrint = now;
    float d = median3();
    if (d > 900) Serial.printf("no echo (nothing near; always? check VCC, ECHO, R5/R6) | lid %d deg\n", pos);
    else         Serial.printf("PASS: hand sensor %.1f cm%s | lid %d deg %s\n", d, d <= 20 ? " HAND" : "", pos, manual ? "(manual)" : "");
  }
  if (Serial.available()) {
    String s = Serial.readStringUntil('\n');
    s.trim();
    int n = constrain(s.substring(s.indexOf(' ') + 1).toInt(), 0, 180);
    if (s.startsWith("angle "))       { manual = true; target = n; }
    else if (s.startsWith("closed ")) { closedDeg = n; manual = false; }
    else if (s.startsWith("open "))   { openDeg = n;   manual = false; }
    else if (s == "auto")             { manual = false; }
    Serial.printf(">>> config.h: LID_CLOSED_DEG %d  LID_OPEN_DEG %d\n", closedDeg, openDeg);
  }
}

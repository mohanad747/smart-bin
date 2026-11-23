// Stage 4 (WIRING.md): relay + fan, buzzer. Serial Monitor at 115200.
// The fan changes every 2 s, with one short beep each time it goes ON.
// Type:  flip  = swap the buzzer level     quiet = beeps off / on
#define PIN_RELAY  26     // fan runs when LOW
#define PIN_BUZZER 25

int  onLevel = HIGH;      // BUZZER_ON_LEVEL
bool fan = false, quiet = false;
uint32_t last = 0, beepEnd = 0;

void buzzer(bool on) { digitalWrite(PIN_BUZZER, on ? onLevel : !onLevel); }

void setup() {
  pinMode(PIN_RELAY, OUTPUT);
  digitalWrite(PIN_RELAY, HIGH);          // relay OFF
  pinMode(PIN_BUZZER, OUTPUT);
  buzzer(false);
  Serial.begin(115200);
  Serial.println("\nt4_outputs: PASS if what you SEE matches what is printed. Type flip or quiet");
}

void loop() {
  uint32_t now = millis();
  if (now - last >= 2000) {
    last = now;
    fan = !fan;
    digitalWrite(PIN_RELAY, fan ? LOW : HIGH);
    bool beep = fan && !quiet;
    Serial.printf("FAN %s%s\n", fan ? "ON  (must spin)" : "OFF (must stop)", beep ? "   BUZZER ON for 0.3 s" : "");
    if (!fan) Serial.println("   Does it spin now? Then it is on NC: move the fan wire to NO");
    if (beep) { buzzer(true); beepEnd = now + 300; }
  }
  if (beepEnd && (int32_t)(now - beepEnd) >= 0) { buzzer(false); beepEnd = 0; }
  if (Serial.available()) {
    String s = Serial.readStringUntil('\n');
    s.trim();
    if (s == "flip") {
      onLevel = !onLevel;
      buzzer(false);
      Serial.printf(">>> config.h: #define BUZZER_ON_LEVEL  %s\n", onLevel ? "HIGH" : "LOW");
    } else if (s == "quiet") {
      quiet = !quiet;
      Serial.println(quiet ? ">>> beeps off" : ">>> beeps on");
    }
  }
}

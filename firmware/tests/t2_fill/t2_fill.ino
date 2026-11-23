// Stage 2 (WIRING.md): HC-SR04 #1, fill level. Serial Monitor at 115200.
// Hold a book at 10, 20 and 30 cm: each must read within 1 cm.
#define PIN_TRIG 19
#define PIN_ECHO 18

float once() {
  digitalWrite(PIN_TRIG, LOW);  delayMicroseconds(2);
  digitalWrite(PIN_TRIG, HIGH); delayMicroseconds(10);
  digitalWrite(PIN_TRIG, LOW);
  unsigned long us = pulseIn(PIN_ECHO, HIGH, 25000UL);
  float cm = us * 0.0343f / 2;
  return (us == 0 || cm < 2 || cm > 400) ? NAN : cm;
}

void setup() {
  Serial.begin(115200);
  pinMode(PIN_TRIG, OUTPUT);
  pinMode(PIN_ECHO, INPUT);
  Serial.println("\nt2_fill");
}

void loop() {
  float v[5];
  int n = 0;
  for (int i = 0; i < 5; i++) {
    float d = once();
    if (!isnan(d)) v[n++] = d;
    delay(60);
  }
  for (int i = 1; i < n; i++) {                 // small insertion sort
    float x = v[i];
    int j = i - 1;
    while (j >= 0 && v[j] > x) { v[j + 1] = v[j]; j--; }
    v[j + 1] = x;
  }
  if (n >= 3) Serial.printf("PASS: %.1f cm (%d of 5 echoes, spread %.1f cm)\n", v[n / 2], n, v[n - 1] - v[0]);
  else        Serial.printf("FAIL: %d of 5 echoes. Check VCC on 5 V, TRIG/ECHO, and R3/R4\n", n);
  delay(200);
}

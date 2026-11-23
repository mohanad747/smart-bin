// Stage 3 (WIRING.md): MQ-2, flame sensor, DHT22. Serial Monitor at 115200.
// Library: DHT sensor library (Adafruit)
#include <DHT.h>
#define PIN_MQ2   34
#define PIN_FLAME 27
#define PIN_DHT    4
DHT dht(PIN_DHT, DHT22);

int  gasMin = 4095, gasMax = 0;
bool sawFlame = false, sawClear = false;

void setup() {
  Serial.begin(115200);
  pinMode(PIN_FLAME, INPUT);
  analogReadResolution(12);
  analogSetAttenuation(ADC_11db);
  dht.begin();
  Serial.println("\nt3_sensors (gas is only checked after 60 s of warm-up)");
}

void loop() {
  long sum = 0;
  for (int i = 0; i < 16; i++) { sum += analogRead(PIN_MQ2); delay(2); }
  int gas = sum / 16;
  bool flame = digitalRead(PIN_FLAME) == LOW;
  float t = dht.readTemperature(), h = dht.readHumidity();
  bool warm = millis() > 60000UL;
  if (warm) { gasMin = min(gasMin, gas); gasMax = max(gasMax, gas); }
  if (flame) sawFlame = true; else sawClear = true;

  Serial.printf("gas %4d %-9s| flame %-3s | T %.1f C | H %.0f %%\n", gas, warm ? "" : "(warm-up)", flame ? "YES" : "no", t, h);
  Serial.printf("   MQ-2  : %s\n", gas <= 5 ? "FAIL: 0, check AO and R1/R2" : gas >= 4090 ? "FAIL: at the top, check R1/R2"
                                 : !warm ? "wait" : gasMax - gasMin > 300 ? "PASS" : "spray air freshener 10 cm away");
  Serial.printf("   Flame : %s\n", sawFlame && sawClear ? "PASS" : sawFlame ? "always YES: turn the knob, avoid sunlight" : "point a TV remote at it");
  Serial.printf("   DHT22 : %s\n", isnan(t) || isnan(h) ? "FAIL: no answer, check DAT, VCC and GND" : t > -10 && t < 60 ? "PASS" : "FAIL: value makes no sense");
  delay(2000);
}

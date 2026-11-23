// Stage 1 (WIRING.md): the ESP32 runs from the board. Serial Monitor at 115200.
// The voltages on the rails are checked with a multimeter, not here.
#include <esp_system.h>

#define PIN_LED 2          // blue LED on the ESP32 board

void setup() {
  Serial.begin(115200);
  delay(300);
  pinMode(PIN_LED, OUTPUT);
  esp_reset_reason_t why = esp_reset_reason();
  Serial.println("\nt1_rails");
  Serial.printf("Last reset: %s\n", why == ESP_RST_BROWNOUT ? "BROWNOUT" : why == ESP_RST_POWERON ? "power-on" : "other");
  if (why == ESP_RST_BROWNOUT) Serial.println("FAIL: the 5 V dropped. Check the power bank, the cable and for a short");
  else                         Serial.println("PASS: ESP32 OK");
}

void loop() {
  static int n = 0;
  digitalWrite(PIN_LED, n++ % 2);
  Serial.printf("ESP32 OK | up %lu s | free heap %lu\n", millis() / 1000UL, (unsigned long)ESP.getFreeHeap());
  delay(1000);
}

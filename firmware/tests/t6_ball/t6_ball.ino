// Stage 6 (WIRING.md): fire ball servo. Serial Monitor at 115200.
// Library: ESP32Servo.  START WITH NO BALL ON THE ARM.
// Type:  drop = go to the release angle     arm = back to the armed angle
//        angle 40 = go to 40 degrees        armed 0 / release 90 = set the two angles
#include <ESP32Servo.h>
#define PIN_SERVO_BALL 13

Servo ball;
int armedDeg = 0, releaseDeg = 90, drops = 0;

void setup() {
  Serial.begin(115200);
  delay(300);
  ball.setPeriodHertz(50);
  ball.attach(PIN_SERVO_BALL, 500, 2400);
  ball.write(armedDeg);
  Serial.println("\nt6_ball: arm is at the ARMED angle. Type: drop | arm | angle <n> | armed <n> | release <n>");
}

void loop() {
  if (!Serial.available()) { delay(10); return; }
  String s = Serial.readStringUntil('\n');
  s.trim();
  int n = constrain(s.substring(s.indexOf(' ') + 1).toInt(), 0, 180);
  if (s == "drop")                   { ball.write(releaseDeg); Serial.printf("Drop %d: did the ball fall? yes = PASS, no = FAIL\n", ++drops); }
  else if (s == "arm")               { ball.write(armedDeg);   Serial.println("Armed: hang the net again"); }
  else if (s.startsWith("angle "))   { ball.write(n);          Serial.printf("At %d degrees\n", n); }
  else if (s.startsWith("armed "))   { armedDeg = n; ball.write(n); }
  else if (s.startsWith("release ")) { releaseDeg = n; }
  Serial.printf(">>> config.h: BALL_ARMED_DEG %d  BALL_RELEASE_DEG %d\n", armedDeg, releaseDeg);
}

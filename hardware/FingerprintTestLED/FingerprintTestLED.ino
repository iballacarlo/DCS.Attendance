#include <Adafruit_Fingerprint.h>

constexpr int FINGER_RX_PIN = 13;
constexpr int FINGER_TX_PIN = 14;
constexpr int GREEN_LED_PIN = 16;
constexpr int RED_LED_PIN = 17;
constexpr int YELLOW_LED_PIN = 2;
constexpr unsigned long SCAN_INTERVAL_MS = 120;
constexpr unsigned long RECONNECT_INTERVAL_MS = 10000;
constexpr unsigned long IMAGE_INDICATOR_HOLD_MS = 2000;

HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);
bool sensorReady = false;
bool imageObserved = false;
uint8_t communicationErrors = 0;
uint8_t lastReportedResult = FINGERPRINT_TIMEOUT;
uint32_t samples = 0;
unsigned long lastProbe = 0;
unsigned long lastScan = 0;
unsigned long lastImage = 0;
unsigned long lastReport = 0;

void setIndicators(bool green, bool red, bool yellow) {
  digitalWrite(GREEN_LED_PIN, green ? HIGH : LOW);
  digitalWrite(RED_LED_PIN, red ? HIGH : LOW);
  digitalWrite(YELLOW_LED_PIN, yellow ? HIGH : LOW);
}

void probeSensor() {
  sensorReady = false;
  imageObserved = false;
  communicationErrors = 0;
  setIndicators(false, false, true);
  fingerSerial.end();
  // Do not call finger.begin(): keep the explicit UART pin assignment.
  fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);
  delay(2000);

  for (int attempt = 1; attempt <= 5 && !sensorReady; attempt++) {
    while (fingerSerial.available()) fingerSerial.read();
    sensorReady = finger.verifyPassword();
    Serial.printf("AS608 verify attempt %d/5: %s\n", attempt,
                  sensorReady ? "OK" : "FAILED");
    if (!sensorReady) {
      setIndicators(false, false, false);
      delay(200);
      setIndicators(false, false, true);
      delay(200);
    }
  }
  lastProbe = millis();
  setIndicators(false, !sensorReady, sensorReady);
  Serial.println(sensorReady ? "Sensor responding. Starting raw getImage test."
                             : "Sensor verification failed. Retry in 10 seconds.");
}

void setup() {
  Serial.begin(115200);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(YELLOW_LED_PIN, OUTPUT);
  setIndicators(false, false, false);
  Serial.println("AS608 LED test: image capture only; no template or flash writes.");
  probeSensor();
}

void loop() {
  if (!sensorReady) {
    if (millis() - lastProbe >= RECONNECT_INTERVAL_MS) probeSensor();
    delay(5);
    return;
  }
  if (millis() - lastScan < SCAN_INTERVAL_MS) {
    delay(5);
    return;
  }
  lastScan = millis();
  uint8_t result = finger.getImage();
  samples++;
  if (result != lastReportedResult || millis() - lastReport >= 2000) {
    Serial.printf("GETIMAGE: %02X / samples: %lu\n", result,
                  static_cast<unsigned long>(samples));
    lastReportedResult = result;
    lastReport = millis();
  }

  if (result == FINGERPRINT_OK) {
    imageObserved = true;
    lastImage = millis();
    communicationErrors = 0;
    setIndicators(true, false, false);
  } else if (result == FINGERPRINT_NOFINGER) {
    communicationErrors = 0;
    // Hold a real capture indication long enough to see even after removal.
    bool holdGreen = imageObserved && millis() - lastImage < IMAGE_INDICATOR_HOLD_MS;
    setIndicators(holdGreen, false, !holdGreen);
  } else {
    imageObserved = false;
    setIndicators(false, true, false);
    if (result == FINGERPRINT_PACKETRECIEVEERR) {
      if (++communicationErrors >= 3) {
        sensorReady = false;
        lastProbe = millis();
        Serial.println("Repeated UART errors. Retry sensor verification in 10 seconds.");
      }
    } else {
      communicationErrors = 0;
    }
  }
}

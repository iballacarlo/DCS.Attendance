#include <WiFi.h>
#include "../DCSAttendanceLCD/device_config.h"

void scanForConfiguredNetwork() {
  Serial.println("Scanning for configured WiFi...");
  int networkCount = WiFi.scanNetworks(false, true);
  bool found = false;

  for (int index = 0; index < networkCount; index++) {
    if (WiFi.SSID(index) != WIFI_NAME) continue;

    found = true;
    Serial.print("FOUND - RSSI: ");
    Serial.print(WiFi.RSSI(index));
    Serial.print(" dBm, channel: ");
    Serial.print(WiFi.channel(index));
    Serial.print(", security code: ");
    Serial.println(static_cast<int>(WiFi.encryptionType(index)));
  }

  if (!found) {
    Serial.println("NOT FOUND - check the exact SSID and enable 2.4 GHz.");
  }

  WiFi.scanDelete();
}

void connectToConfiguredNetwork() {
  Serial.println("Connecting...");
  WiFi.begin(WIFI_NAME, WIFI_PASSWORD);

  for (int attempt = 0; attempt < 30 && WiFi.status() != WL_CONNECTED; attempt++) {
    Serial.print('.');
    delay(1000);
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("CONNECTED");
    Serial.print("IP address: ");
    Serial.println(WiFi.localIP());
    Serial.print("Signal: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
    return;
  }

  Serial.print("FAILED - WiFi status code: ");
  Serial.println(static_cast<int>(WiFi.status()));
}

void setup() {
  Serial.begin(115200);
  delay(1500);
  Serial.println("\nESP32 WiFi-only test");

  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.disconnect(false, false);
  delay(500);

  scanForConfiguredNetwork();
  connectToConfiguredNetwork();
}

void loop() {
  static unsigned long lastReport = 0;
  if (millis() - lastReport < 5000) return;
  lastReport = millis();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Still connected, RSSI: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
  } else {
    Serial.print("Disconnected, status: ");
    Serial.println(static_cast<int>(WiFi.status()));
  }
}

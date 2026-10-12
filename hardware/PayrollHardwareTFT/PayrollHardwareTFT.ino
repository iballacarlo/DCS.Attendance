#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <Wire.h>
#include <RTClib.h>
#include <esp_sntp.h>
#include <sys/time.h>
#include <MFRC522.h>
#define LGFX_USE_V1
#include <LovyanGFX.hpp>
#include <Adafruit_Fingerprint.h>
#include <LittleFS.h>
#include <Preferences.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <freertos/semphr.h>
#include <time.h>
#include "device_config.h"

#ifndef FORMAT_OFFLINE_STORAGE_ONCE
#define FORMAT_OFFLINE_STORAGE_ONCE false
#endif

#define RFID_SS_PIN 5
#define RFID_RST_PIN 4
#define BUZZER_PIN 15
#define GREEN_LED_PIN 16
#define RED_LED_PIN 17
#define YELLOW_LED_PIN 2
#define FINGER_RX_PIN 13
#define FINGER_TX_PIN 14
#define TFT_SCK_PIN 32
#define TFT_MOSI_PIN 33
#define TFT_CS_PIN 25
#define TFT_DC_PIN 26
#define TFT_RST_PIN 27
#define RTC_SDA_PIN 21
#define RTC_SCL_PIN 22

class PayrollDisplay : public lgfx::LGFX_Device {
  lgfx::Panel_ILI9488 panel;
  lgfx::Bus_SPI bus;

 public:
  PayrollDisplay() {
    {
      auto config = bus.config();
      config.spi_host = HSPI_HOST;
      config.spi_mode = 0;
      config.freq_write = 20000000;
      config.freq_read = 16000000;
      config.spi_3wire = false;
      config.use_lock = true;
      config.dma_channel = 1;
      config.pin_sclk = TFT_SCK_PIN;
      config.pin_mosi = TFT_MOSI_PIN;
      config.pin_miso = -1;
      config.pin_dc = TFT_DC_PIN;
      bus.config(config);
      panel.setBus(&bus);
    }

    {
      auto config = panel.config();
      config.pin_cs = TFT_CS_PIN;
      config.pin_rst = TFT_RST_PIN;
      config.pin_busy = -1;
      config.memory_width = 320;
      config.memory_height = 480;
      config.panel_width = 320;
      config.panel_height = 480;
      config.offset_x = 0;
      config.offset_y = 0;
      config.offset_rotation = 0;
      config.readable = false;
      config.invert = false;
      config.rgb_order = false;
      config.dlen_16bit = false;
      config.bus_shared = false;
      panel.config(config);
    }

    setPanel(&panel);
  }
};

const char* API_BASE_URL = "https://payroll-system.up.railway.app/api/hardware";
const char* API_HOST = "payroll-system.up.railway.app";
SemaphoreHandle_t hardwareHttpMutex = nullptr;

MFRC522 rfid(RFID_SS_PIN, RFID_RST_PIN);
PayrollDisplay lcd;
HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);
Preferences offlinePreferences;
RTC_DS3231 rtc;
bool rtcAvailable = false;
volatile bool rtcSyncPending = false;

bool fingerprintAvailable = false;
bool waitForFingerRelease = false;
uint8_t fingerprintImageConfirmations = 0;
uint8_t fingerprintReleaseConfirmations = 0;
unsigned long lastClockUpdate = 0;
unsigned long lastFingerprintScan = 0;
unsigned long lastWiFiAttempt = 0;
unsigned long lastFingerprintReconnect = 0;
unsigned long lastRegistrationBlink = 0;
uint16_t fingerprintCapacity = 127;
uint16_t nextFingerprintSlotHint = 1;
bool registrationLedOn = false;
bool offlineStorageAvailable = false;
bool wifiWasConnected = false;
uint32_t offlineQueueHead = 0;
uint32_t offlineEventSequence = 0;
unsigned long lastOfflineSyncAttempt = 0;
unsigned long offlineSyncRetryMs = 1500;
String offlineSyncStatus = "FACULTY ATTENDANCE";
volatile bool offlineUploadsPending = false;
volatile bool serverReconnectRequested = false;
uint8_t serverTransportFailures = 0;
unsigned long lastServerReconnect = 0;

const unsigned long WIFI_RETRY_MS = 10000;
const unsigned long ENROLLMENT_POLL_MS = 2500;
const unsigned long FINGER_SCAN_INTERVAL_MS = 120;
const uint8_t FINGER_CONFIRMATION_SAMPLES = 3;
const unsigned long OFFLINE_SYNC_INTERVAL_MS = 1500;
const uint32_t MAX_OFFLINE_ATTENDANCE = 2000;
const time_t MINIMUM_VALID_TIME = 1700000000;
const char* OFFLINE_QUEUE_PATH = "/attendance.bin";

// Keep enrollment connected so requests from the faculty form reach the device.
const bool ENABLE_REMOTE_ENROLLMENT = true;

struct EnrollmentRequest {
  char id[24];
  char method[16];
  char currentIdentifier[101];
};

struct __attribute__((packed)) OfflineAttendanceRecord {
  uint8_t version;
  uint32_t occurredAt;
  char eventId[48];
  char identifier[101];
  char method[12];
};

QueueHandle_t enrollmentQueue = nullptr;
volatile bool enrollmentBusy = false;
volatile bool attendanceBusy = false;

const uint16_t DISPLAY_BACKGROUND = 0xF7BE;
const uint16_t DISPLAY_GREEN = 0x0247;
const uint16_t DISPLAY_GOLD = 0xFE60;
const uint16_t DISPLAY_WHITE = 0xFFFF;
const uint16_t DISPLAY_MUTED = 0x6B6D;
lgfx::LGFX_Sprite displayBuffer(&lcd);
bool displayBufferReady = false;
bool displayFrameReady = false;
bool displayShowingClock = false;
String lastDisplayLines[2];
String lastDisplayFooter;
String lastDisplaySyncStatus;
uint8_t fingerprintCommunicationErrors = 0;
uint8_t lastFingerprintImageResult = FINGERPRINT_TIMEOUT;

void drawTextBand(int y, String text, int textSize, uint16_t color) {
  if (displayBufferReady) {
    displayBuffer.fillSprite(DISPLAY_BACKGROUND);
    displayBuffer.setTextDatum(lgfx::middle_center);
    displayBuffer.setTextColor(color, DISPLAY_BACKGROUND);
    displayBuffer.setTextSize(textSize);
    while (textSize > 1 && displayBuffer.textWidth(text) > lcd.width() - 32) {
      displayBuffer.setTextSize(--textSize);
    }
    displayBuffer.drawString(text, lcd.width() / 2, 41);
    displayBuffer.pushSprite(0, y);
  } else {
    // Opaque padded text avoids a clear-then-draw flash if allocation fails.
    lcd.setTextDatum(lgfx::middle_center);
    lcd.setTextColor(color, DISPLAY_BACKGROUND);
    lcd.setTextSize(textSize);
    while (textSize > 1 && lcd.textWidth(text) > lcd.width() - 32) lcd.setTextSize(--textSize);
    lcd.setTextPadding(lcd.width());
    lcd.drawString(text, lcd.width() / 2, y + 41);
    lcd.setTextPadding(0);
  }
}

void drawDisplayFrame() {
  if (displayFrameReady) return;
  lcd.fillScreen(DISPLAY_BACKGROUND);
  lcd.fillRect(0, 0, lcd.width(), 48, DISPLAY_GREEN);
  lcd.fillRect(0, 48, lcd.width(), 3, DISPLAY_GOLD);
  lcd.setTextDatum(lgfx::top_left);
  lcd.setTextColor(DISPLAY_WHITE, DISPLAY_GREEN);
  lcd.setTextSize(2);
  lcd.setCursor(16, 16);
  lcd.print("CvSU IMUS / DCS PAYROLL");
  lcd.setTextColor(DISPLAY_MUTED, DISPLAY_BACKGROUND);
  lcd.setTextSize(1);
  lcd.setCursor(16, 64);
  lcd.print("FACULTY ATTENDANCE");
  displayFrameReady = true;
}

void printLine(int row, String text) {
  if (lastDisplayLines[row] == text) return;
  lastDisplayLines[row] = text;
  int size = displayShowingClock ? (row == 0 ? 2 : 5) : (row == 0 ? 3 : 2);
  drawTextBand(row == 0 ? 80 : 178, text, size, row == 0 ? DISPLAY_MUTED : DISPLAY_GREEN);
}

void updateDisplayFooter() {
  if (offlineSyncStatus != lastDisplaySyncStatus) {
    lastDisplaySyncStatus = offlineSyncStatus;
    lcd.setTextDatum(lgfx::top_left);
    lcd.setTextSize(1);
    lcd.setTextColor(DISPLAY_MUTED, DISPLAY_BACKGROUND);
    lcd.setTextPadding(lcd.width() - 32);
    lcd.drawString(offlineSyncStatus.substring(0, 74), 16, 64);
    lcd.setTextPadding(0);
  }
  String text = WiFi.status() == WL_CONNECTED ? "ONLINE" : "OFFLINE";
  text += fingerprintAvailable ? "  |  FINGER READY" : "  |  FINGER UNAVAILABLE";
  if (fingerprintAvailable) {
    char scanStatus[12];
    snprintf(scanStatus, sizeof(scanStatus), " / SCAN %02X", lastFingerprintImageResult);
    text += scanStatus;
  }
  text += "  |  QUEUED " + String(pendingOfflineRecordCount());
  if (text == lastDisplayFooter) return;
  lastDisplayFooter = text;
  if (displayBufferReady) {
    displayBuffer.fillSprite(DISPLAY_GREEN);
    displayBuffer.setTextDatum(lgfx::middle_center);
    displayBuffer.setTextSize(1);
    displayBuffer.setTextColor(DISPLAY_WHITE, DISPLAY_GREEN);
    displayBuffer.drawString(text, lcd.width() / 2, 20);
    displayBuffer.pushSprite(0, 280);
  } else {
    lcd.setTextDatum(lgfx::middle_center);
    lcd.setTextSize(1);
    lcd.setTextColor(DISPLAY_WHITE, DISPLAY_GREEN);
    lcd.setTextPadding(lcd.width());
    lcd.drawString(text, lcd.width() / 2, 300);
    lcd.setTextPadding(0);
  }
}

void showMessage(String first, String second) {
  drawDisplayFrame();
  if (displayShowingClock) {
    lastDisplayLines[0] = "\x01";
    lastDisplayLines[1] = "\x01";
  }
  displayShowingClock = false;
  printLine(0, first);
  printLine(1, second);
  updateDisplayFooter();
}

void startRegistrationIndicator() {
  registrationLedOn = true;
  lastRegistrationBlink = millis();
  digitalWrite(YELLOW_LED_PIN, HIGH);
}

void updateRegistrationIndicator() {
  if (millis() - lastRegistrationBlink < 250) return;
  registrationLedOn = !registrationLedOn;
  digitalWrite(YELLOW_LED_PIN, registrationLedOn ? HIGH : LOW);
  lastRegistrationBlink = millis();
}

void stopRegistrationIndicator() {
  registrationLedOn = false;
  digitalWrite(YELLOW_LED_PIN, LOW);
}

void registrationReadySignal() {
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);
  tone(BUZZER_PIN, 900, 85);
  delay(120);
  noTone(BUZZER_PIN);
  delay(55);
  tone(BUZZER_PIN, 900, 85);
  delay(120);
  noTone(BUZZER_PIN);
}

void successSignal() {
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(YELLOW_LED_PIN, LOW);
  digitalWrite(GREEN_LED_PIN, HIGH);
  tone(BUZZER_PIN, 1500, 100);
  delay(150);
  tone(BUZZER_PIN, 1900, 150);
  delay(200);
  noTone(BUZZER_PIN);
  digitalWrite(GREEN_LED_PIN, LOW);
}

void doneSignal() {
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(YELLOW_LED_PIN, LOW);
  digitalWrite(GREEN_LED_PIN, HIGH);
  tone(BUZZER_PIN, 1100, 180);
  delay(240);
  tone(BUZZER_PIN, 700, 220);
  delay(280);
  noTone(BUZZER_PIN);
  digitalWrite(GREEN_LED_PIN, LOW);
}

void errorSignal() {
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(YELLOW_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, HIGH);
  tone(BUZZER_PIN, 350, 450);
  delay(500);
  noTone(BUZZER_PIN);
  digitalWrite(RED_LED_PIN, LOW);
}

String jsonValue(String json, String key) {
  String marker = String("\"") + key + "\":\"";
  int start = json.indexOf(marker);
  if (start < 0) return "";
  start += marker.length();
  int finish = json.indexOf("\"", start);
  return finish < 0 ? "" : json.substring(start, finish);
}

String rfidUid() {
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(rfid.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}

bool hasValidClock() {
  return time(nullptr) >= MINIMUM_VALID_TIME;
}

void onInternetTimeSync(struct timeval* timestamp) {
  rtcSyncPending = true;
}

void initializeRtc() {
  // RTC stores UTC; the screen and attendance payloads use Philippine time.
  setenv("TZ", "PHT-8", 1);
  tzset();
  sntp_set_time_sync_notification_cb(onInternetTimeSync);
  Wire.begin(RTC_SDA_PIN, RTC_SCL_PIN);
  rtcAvailable = rtc.begin(&Wire);
  if (!rtcAvailable) {
    Serial.println("DS3231 not detected. Check 3.3V, GND, SDA->D21, SCL->D22.");
    return;
  }
  if (rtc.lostPower()) {
    Serial.println("DS3231 needs internet time sync before offline attendance is available.");
    return;
  }
  DateTime storedTime = rtc.now();
  if (!storedTime.isValid() || storedTime.unixtime() < MINIMUM_VALID_TIME) {
    Serial.println("DS3231 time is invalid. Waiting for internet time sync.");
    return;
  }
  struct timeval restoredTime = {};
  restoredTime.tv_sec = storedTime.unixtime();
  settimeofday(&restoredTime, nullptr);
  Serial.println("DS3231 detected. System clock restored from battery-backed RTC (UTC).");
}

void syncRtcFromInternet() {
  if (!rtcSyncPending) return;
  rtcSyncPending = false;
  if (!hasValidClock()) return;
  if (!rtcAvailable) rtcAvailable = rtc.begin(&Wire);
  if (!rtcAvailable) return;
  rtc.adjust(DateTime(static_cast<uint32_t>(time(nullptr))));
  Serial.println("DS3231 updated from internet time. Offline clock is ready.");
}

uint32_t storedOfflineRecordCount() {
  if (!offlineStorageAvailable || !LittleFS.exists(OFFLINE_QUEUE_PATH)) return 0;
  File file = LittleFS.open(OFFLINE_QUEUE_PATH, FILE_READ);
  if (!file) return 0;
  size_t size = file.size();
  file.close();
  if (size % sizeof(OfflineAttendanceRecord) != 0) return 0;
  return size / sizeof(OfflineAttendanceRecord);
}

uint32_t pendingOfflineRecordCount() {
  uint32_t stored = storedOfflineRecordCount();
  return stored > offlineQueueHead ? stored - offlineQueueHead : 0;
}

void resetOfflineQueue() {
  if (LittleFS.exists(OFFLINE_QUEUE_PATH)) LittleFS.remove(OFFLINE_QUEUE_PATH);
  offlineQueueHead = 0;
  offlinePreferences.putUInt("head", 0);
}

void initializeOfflineStorage() {
  offlineStorageAvailable = LittleFS.begin(false);
  if (!offlineStorageAvailable && FORMAT_OFFLINE_STORAGE_ONCE) {
    Serial.println("Formatting the offline attendance partition by explicit configuration...");
    if (LittleFS.format()) offlineStorageAvailable = LittleFS.begin(false);
  }
  offlinePreferences.begin("attendance", false);
  offlineQueueHead = offlinePreferences.getUInt("head", 0);
  offlineEventSequence = offlinePreferences.getUInt("sequence", 0);

  if (!offlineStorageAvailable) {
    Serial.println("LittleFS mount failed. Offline attendance is unavailable; storage was not formatted.");
    return;
  }

  if (LittleFS.exists(OFFLINE_QUEUE_PATH)) {
    File file = LittleFS.open(OFFLINE_QUEUE_PATH, FILE_READ);
    size_t size = file ? file.size() : 0;
    if (file) file.close();
    if (size % sizeof(OfflineAttendanceRecord) != 0 || offlineQueueHead > size / sizeof(OfflineAttendanceRecord)) {
      Serial.println("Offline queue is invalid. Offline attendance is disabled to preserve stored data.");
      offlineStorageAvailable = false;
      return;
    }
  } else if (offlineQueueHead != 0) {
    offlineQueueHead = 0;
    offlinePreferences.putUInt("head", 0);
  }

  Serial.println("Offline attendance ready. Pending records: " + String(pendingOfflineRecordCount()));
  offlineUploadsPending = pendingOfflineRecordCount() > 0;
}

String createAttendanceEventId(time_t occurredAt) {
  uint64_t chipId = ESP.getEfuseMac();
  offlineEventSequence++;
  offlinePreferences.putUInt("sequence", offlineEventSequence);
  String eventId = "ESP" + String(static_cast<uint32_t>(chipId >> 32), HEX) +
                   String(static_cast<uint32_t>(chipId), HEX) + "-" +
                   String(static_cast<uint32_t>(occurredAt)) + "-" +
                   String(offlineEventSequence);
  eventId.toUpperCase();
  return eventId;
}

bool createAttendanceRecord(String identifier, String method, OfflineAttendanceRecord& record) {
  time_t occurredAt = time(nullptr);
  if (!hasValidClock()) return false;

  record = {};
  record.version = 1;
  record.occurredAt = static_cast<uint32_t>(occurredAt);
  createAttendanceEventId(occurredAt).toCharArray(record.eventId, sizeof(record.eventId));
  identifier.toCharArray(record.identifier, sizeof(record.identifier));
  method.toCharArray(record.method, sizeof(record.method));
  return true;
}

bool appendOfflineRecord(const OfflineAttendanceRecord& record) {
  if (!offlineStorageAvailable) return false;
  if (pendingOfflineRecordCount() >= MAX_OFFLINE_ATTENDANCE) return false;

  File file = LittleFS.open(OFFLINE_QUEUE_PATH, FILE_APPEND);
  if (!file) return false;
  bool saved = file.write(reinterpret_cast<const uint8_t*>(&record), sizeof(record)) == sizeof(record);
  file.close();
  if (saved) offlineUploadsPending = true;
  return saved;
}

bool readNextOfflineRecord(OfflineAttendanceRecord& record) {
  if (!offlineStorageAvailable || pendingOfflineRecordCount() == 0) return false;
  File file = LittleFS.open(OFFLINE_QUEUE_PATH, FILE_READ);
  if (!file || !file.seek(offlineQueueHead * sizeof(OfflineAttendanceRecord))) {
    if (file) file.close();
    return false;
  }
  bool read = file.read(reinterpret_cast<uint8_t*>(&record), sizeof(record)) == sizeof(record);
  file.close();
  return read && record.version == 1;
}

void acknowledgeOfflineRecord() {
  offlineQueueHead++;
  offlinePreferences.putUInt("head", offlineQueueHead);
  if (pendingOfflineRecordCount() == 0) resetOfflineQueue();
  offlineUploadsPending = pendingOfflineRecordCount() > 0;
}

String attendanceTimestamp(const OfflineAttendanceRecord& record) {
  time_t timestamp = static_cast<time_t>(record.occurredAt);
  struct tm info;
  localtime_r(&timestamp, &info);
  char text[20];
  strftime(text, sizeof(text), "%Y-%m-%d %H:%M:%S", &info);
  return String(text);
}

String attendancePayload(const OfflineAttendanceRecord& record) {
  return "{\"method\":\"" + String(record.method) +
         "\",\"identifier\":\"" + String(record.identifier) +
         "\",\"event_id\":\"" + String(record.eventId) +
         "\",\"occurred_at\":\"" + attendanceTimestamp(record) + "\"}";
}

void diagnoseConfiguredWiFi() {
  Serial.println("Scanning for the configured 2.4 GHz WiFi...");
  int networkCount = WiFi.scanNetworks(false, true);
  bool found = false;

  for (int index = 0; index < networkCount; index++) {
    if (WiFi.SSID(index) != WIFI_NAME) continue;

    found = true;
    Serial.print("Configured WiFi found. RSSI: ");
    Serial.print(WiFi.RSSI(index));
    Serial.print(" dBm, channel: ");
    Serial.print(WiFi.channel(index));
    Serial.print(", security code: ");
    Serial.println(static_cast<int>(WiFi.encryptionType(index)));
  }

  if (!found) {
    Serial.println("Configured WiFi was not found. Check the exact SSID and make sure 2.4 GHz is enabled.");
  }
  WiFi.scanDelete();
}

void connectWiFi() {
  lastWiFiAttempt = millis();
  digitalWrite(YELLOW_LED_PIN, HIGH);
  showMessage("Connecting WiFi", "Please wait");
  WiFi.disconnect();
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_NAME, WIFI_PASSWORD);
  for (int attempt = 0; WiFi.status() != WL_CONNECTED && attempt < 20; attempt++) delay(500);
  if (WiFi.status() == WL_CONNECTED) {
    wifiWasConnected = true;
    Serial.print("WiFi connected. IP: ");
    Serial.println(WiFi.localIP());
    successSignal();
    configTime(8 * 3600, 0, "pool.ntp.org", "time.nist.gov");
  } else {
    wifiWasConnected = false;
    Serial.print("WiFi connection failed. Status: ");
    Serial.println(WiFi.status());
    diagnoseConfiguredWiFi();
    errorSignal();
    showMessage("WiFi Failed", "Check settings");
  }
  digitalWrite(YELLOW_LED_PIN, LOW);
}

void maintainWiFi() {
  // Recover a WiFi association that still reports connected while HTTPS keeps
  // failing. Keep flash records intact and reconnect without blocking scans.
  if (serverReconnectRequested && !enrollmentBusy && !attendanceBusy &&
      millis() - lastServerReconnect >= 60000 && hardwareHttpMutex &&
      xSemaphoreTake(hardwareHttpMutex, 0) == pdTRUE) {
    serverReconnectRequested = false;
    serverTransportFailures = 0;
    lastServerReconnect = millis();
    lastWiFiAttempt = millis();
    Serial.println("Repeated server connection failures. Refreshing WiFi; queued records preserved.");
    WiFi.disconnect(false, false);
    WiFi.begin(WIFI_NAME, WIFI_PASSWORD);
    xSemaphoreGive(hardwareHttpMutex);
  }
  bool connected = WiFi.status() == WL_CONNECTED;
  if (connected && !wifiWasConnected) {
    Serial.print("WiFi reconnected. IP: ");
    Serial.println(WiFi.localIP());
    configTime(8 * 3600, 0, "pool.ntp.org", "time.nist.gov");
  } else if (!connected && wifiWasConnected) {
    Serial.println("WiFi disconnected. Attendance will be queued while the clock remains valid.");
  }
  wifiWasConnected = connected;

  if (!connected && millis() - lastWiFiAttempt >= WIFI_RETRY_MS) {
    lastWiFiAttempt = millis();
    Serial.println("Retrying WiFi in the background...");
    WiFi.reconnect();
  }
}

void showClock() {
  if (!displayShowingClock) {
    lastDisplayLines[0] = "\x01";
    lastDisplayLines[1] = "\x01";
  }
  displayShowingClock = true;
  time_t currentTime = time(nullptr);
  if (!hasValidClock()) {
    printLine(0, "Waiting for network time");
    printLine(1, "--:--:--");
    updateDisplayFooter();
    return;
  }

  struct tm info;
  localtime_r(&currentTime, &info);
  char dateText[32];
  char timeText[17];
  strftime(dateText, sizeof(dateText), "%a, %b %d, %Y", &info);
  strftime(timeText, sizeof(timeText), "%I:%M:%S %p", &info);
  printLine(0, dateText);
  printLine(1, timeText);
  updateDisplayFooter();
}

void setupFingerprint() {
  // Do not call finger.begin(): it begins UART again without explicit pins.
  // Keep AS608 on GPIO13/14; GPIO16/17 now drive the indicator LEDs.
  fingerSerial.end();
  fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);
  delay(1000);
  fingerprintAvailable = false;
  for (int attempt = 1; attempt <= 5 && !fingerprintAvailable; attempt++) {
    fingerprintAvailable = finger.verifyPassword() && finger.getParameters() == FINGERPRINT_OK;
    if (!fingerprintAvailable) {
      Serial.println("AS608 detection attempt " + String(attempt) + " of 5 failed.");
      delay(400);
    }
  }
  if (fingerprintAvailable) {
    if (finger.getParameters() == FINGERPRINT_OK && finger.capacity > 0) {
      fingerprintCapacity = finger.capacity;
    }
    if (finger.getTemplateCount() == FINGERPRINT_OK) {
      nextFingerprintSlotHint = finger.templateCount < fingerprintCapacity
        ? finger.templateCount + 1
        : 1;
    }
    Serial.println("AS608 fingerprint sensor detected.");
    Serial.println("Stored fingerprints: " + String(finger.templateCount));
  } else {
    Serial.println("AS608 fingerprint sensor NOT detected. Check 5V, GND, TX->D13 and RX->D14.");
  }
}

bool reconnectFingerprint() {
  if (fingerprintAvailable) return true;

  fingerSerial.end();
  fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);

  showMessage("AS608 reconnect", "Please wait...");
  fingerprintAvailable = false;
  for (int attempt = 1; attempt <= 5 && !fingerprintAvailable; attempt++) {
    Serial.println("Reconnecting AS608, attempt " + String(attempt) + " of 5...");
    fingerprintAvailable = finger.verifyPassword() && finger.getParameters() == FINGERPRINT_OK;
    if (!fingerprintAvailable) {
      updateRegistrationIndicator();
      delay(400);
    }
  }

  if (fingerprintAvailable) {
    if (finger.getParameters() == FINGERPRINT_OK && finger.capacity > 0) {
      fingerprintCapacity = finger.capacity;
    }
    if (finger.getTemplateCount() == FINGERPRINT_OK) {
      nextFingerprintSlotHint = finger.templateCount < fingerprintCapacity
        ? finger.templateCount + 1
        : 1;
    }
    Serial.println("AS608 reconnected. Stored fingerprints: " + String(finger.templateCount));
  }

  return fingerprintAvailable;
}

void addHardwareHeaders(HTTPClient& http) {
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("X-Hardware-Key", HARDWARE_API_KEY);
}

void noteServerConnectionFailure() {
  if (serverTransportFailures < 3) serverTransportFailures++;
  if (serverTransportFailures >= 3) serverReconnectRequested = true;
}

int requestHardwareServer(String path, String payload, String& response, bool background) {
  response = "";
  if (WiFi.status() != WL_CONNECTED) return -1000;
  if (!hardwareHttpMutex || xSemaphoreTake(hardwareHttpMutex,
      background ? 0 : pdMS_TO_TICKS(45000)) != pdTRUE) return -1002;

  // One TLS client at a time: simultaneous handshakes compete for ESP32 heap.
  if (background && (attendanceBusy || enrollmentBusy)) {
    xSemaphoreGive(hardwareHttpMutex);
    return -1002;
  }
  int code = -1001;
  const int maximumAttempts = background ? 1 : 2;
  for (int attempt = 0; attempt < maximumAttempts; attempt++) {
    if (attempt > 0) {
      Serial.println("Connection failed before sending. Retrying with a fresh TLS client...");
      delay(750);
    }
    if (WiFi.status() != WL_CONNECTED) {
      code = -1000;
      break;
    }
    // Both clients are destroyed at the end of each attempt, before retrying.
    WiFiClientSecure secureClient;
    secureClient.setInsecure();
    secureClient.setHandshakeTimeout(15);
    IPAddress serverIp;
    if (WiFi.hostByName(API_HOST, serverIp) != 1) {
      Serial.println("Server DNS lookup failed. Check WiFi internet access and DNS.");
      code = -1003;
      break;
    }
    Serial.println("Server request " + path + ": DNS=" + serverIp.toString() +
                   ", RSSI=" + String(WiFi.RSSI()) + ", free heap=" + String(ESP.getFreeHeap()) +
                   ", largest block=" + String(ESP.getMaxAllocHeap()));

    HTTPClient http;
    if (!http.begin(secureClient, String(API_BASE_URL) + path)) {
      code = -1001;
      break;
    }
    http.setReuse(false);
    http.setConnectTimeout(15000);
    http.setTimeout(15000);
    addHardwareHeaders(http);
    code = payload.length() ? http.POST(payload) : http.GET();
    response = http.getString();
    if (code < 0) {
      char tlsError[160] = {};
      int tlsCode = secureClient.lastError(tlsError, sizeof(tlsError));
      Serial.println("Server transport error " + String(code) + ": " + HTTPClient::errorToString(code));
      Serial.println("TLS error " + String(tlsCode) + ": " + String(tlsError));
    }
    http.end();
    secureClient.stop();
    // HTTPClient returns this only when connect() fails, before sending HTTP.
    // Do not blindly repeat requests whose response was lost after sending.
    if (code != HTTPC_ERROR_CONNECTION_REFUSED) break;
  }
  if (code < 0) {
    noteServerConnectionFailure();
  } else {
    serverTransportFailures = 0;
    serverReconnectRequested = false;
  }
  xSemaphoreGive(hardwareHttpMutex);
  return code;
}

bool sendEnrollmentResult(String id, String status, String identifier, String message) {
  String payload = "{\"status\":\"" + status + "\",\"identifier\":\"" + identifier + "\",\"message\":\"" + message + "\"}";
  String response;
  int code = requestHardwareServer("/enrollments/" + id + "/result", payload, response, false);
  Serial.println("Enrollment result HTTP " + String(code) + ": " + response);
  return code >= 200 && code < 300;
}

int fingerprintSlot(String code) {
  if (!code.startsWith("FP-")) return 0;
  return code.substring(3).toInt();
}

int findFreeFingerprintSlot(String currentIdentifier) {
  if (finger.getTemplateCount() != FINGERPRINT_OK) {
    fingerprintAvailable = false;
    Serial.println("Could not read AS608 storage information.");
    return -1;
  }

  String response;
  int code = requestHardwareServer("/fingerprint-slots", "", response, false);
  if (code != 200 || response.indexOf("\"reserved_slots\":\"") < 0) {
    Serial.println("Could not read fingerprint slots reserved on the server. HTTP " + String(code));
    return -2;
  }
  String reservedSlots = "," + jsonValue(response, "reserved_slots") + ",";
  int maximum = fingerprintCapacity > 0 ? fingerprintCapacity : 127;
  int oldSlot = fingerprintSlot(currentIdentifier);
  if (oldSlot >= 1 && oldSlot <= maximum) {
    uint8_t result = finger.loadModel(oldSlot);
    if (result == FINGERPRINT_DBRANGEFAIL || result == FINGERPRINT_NOTFOUND) {
      Serial.println("Reusing this faculty member's empty fingerprint slot " + String(oldSlot) + ".");
      return oldSlot;
    }
    if (result != FINGERPRINT_OK) {
      Serial.println("AS608 slot " + String(oldSlot) + " read error: 0x" + String(result, HEX));
      return -1;
    }
  }

  int firstSlot = nextFingerprintSlotHint;
  if (firstSlot < 1 || firstSlot > maximum) {
    firstSlot = finger.templateCount < maximum ? finger.templateCount + 1 : 1;
  }

  Serial.println("AS608 capacity: " + String(maximum) +
                 ", stored: " + String(finger.templateCount));
  for (int offset = 0; offset < maximum; offset++) {
    updateRegistrationIndicator();
    int slot = ((firstSlot - 1 + offset) % maximum) + 1;
    if (reservedSlots.indexOf("," + String(slot) + ",") >= 0) continue;
    uint8_t result = finger.loadModel(slot);
    if (result == FINGERPRINT_DBRANGEFAIL || result == FINGERPRINT_NOTFOUND) {
      nextFingerprintSlotHint = slot;
      Serial.println("Using free fingerprint slot " + String(slot) + ".");
      return slot;
    }
    if (result != FINGERPRINT_OK) {
      Serial.println("AS608 slot " + String(slot) + " read error: 0x" + String(result, HEX));
      return -1;
    }
  }
  return 0;
}

bool waitForFingerprintImage(unsigned long timeoutMs) {
  unsigned long started = millis();
  while (millis() - started < timeoutMs) {
    updateRegistrationIndicator();
    uint8_t result = finger.getImage();
    if (result == FINGERPRINT_OK) return true;
    if (result != FINGERPRINT_NOFINGER) return false;
    delay(80);
  }
  return false;
}

bool waitForFingerRemoval() {
  unsigned long started = millis();
  while (millis() - started < 15000) {
    updateRegistrationIndicator();
    uint8_t result = finger.getImage();
    if (result == FINGERPRINT_NOFINGER) return true;
    if (result == FINGERPRINT_PACKETRECIEVEERR) {
      fingerprintAvailable = false;
      return false;
    }
    delay(80);
  }
  return false;
}

bool captureFingerprintModel() {
  showMessage("Place finger", "First scan");
  if (!waitForFingerprintImage(30000) || finger.image2Tz(1) != FINGERPRINT_OK) return false;
  showMessage("Remove finger", "");
  if (!waitForFingerRemoval()) return false;
  delay(500);
  showMessage("Place same", "finger again");
  if (!waitForFingerprintImage(30000) || finger.image2Tz(2) != FINGERPRINT_OK) return false;
  return finger.createModel() == FINGERPRINT_OK;
}

void enrollFingerprint(String id, String currentIdentifier) {
  if (!reconnectFingerprint()) {
    sendEnrollmentResult(id, "failed", "", "AS608 is not available.");
    errorSignal();
    showMessage("AS608 Error", "Check wiring");
    delay(2500);
    return;
  }

  // loadModel() writes sensor buffer 1. Find a slot before capturing the
  // new template so occupied-slot checks cannot overwrite it before saving.
  showMessage("Checking finger memory", "Please wait...");
  int newSlot = findFreeFingerprintSlot(currentIdentifier);
  if (newSlot == -2) {
    sendEnrollmentResult(id, "failed", "", "Could not verify available fingerprint slots with the server.");
    errorSignal();
    showMessage("Server unavailable", "Try again later");
    delay(2500);
    return;
  }
  if (newSlot < 0) {
    sendEnrollmentResult(id, "failed", "", "Could not read AS608 fingerprint storage. Check sensor wiring and power.");
    errorSignal();
    showMessage("AS608 read error", "Check wiring");
    delay(2500);
    return;
  }
  if (newSlot == 0) {
    sendEnrollmentResult(id, "failed", "", "No free fingerprint slot not assigned to another faculty member.");
    errorSignal();
    showMessage("Finger memory", "is full");
    delay(2500);
    return;
  }

  if (!captureFingerprintModel()) {
    sendEnrollmentResult(id, "failed", "", "Fingerprint capture failed.");
    errorSignal();
    showMessage("Enroll failed", "Try again");
    delay(2500);
    return;
  }

  showMessage("Saving finger", "Please wait...");
  if (finger.storeModel(newSlot) != FINGERPRINT_OK) {
    sendEnrollmentResult(id, "failed", "", "Fingerprint could not be saved to the AS608.");
    errorSignal();
    showMessage("Save failed", "Try again");
    delay(2500);
    return;
  }

  String code = "FP-" + String(newSlot);
  if (sendEnrollmentResult(id, "completed", code, "")) {
    int oldSlot = fingerprintSlot(currentIdentifier);
    if (oldSlot > 0 && oldSlot != newSlot) {
      finger.deleteModel(oldSlot);
      nextFingerprintSlotHint = oldSlot;
    } else {
      nextFingerprintSlotHint = newSlot < fingerprintCapacity ? newSlot + 1 : 1;
    }
    successSignal();
    showMessage("Fingerprint", "Registered");
  } else {
    finger.deleteModel(newSlot);
    nextFingerprintSlotHint = newSlot;
    errorSignal();
    showMessage("Server rejected", "fingerprint");
  }
  delay(2500);
}

void enrollRfid(String id) {
  showMessage("Tap RFID card", "Waiting...");
  unsigned long started = millis();
  while (millis() - started < 60000) {
    updateRegistrationIndicator();
    if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
      String uid = rfidUid();
      rfid.PICC_HaltA();
      rfid.PCD_StopCrypto1();
      if (sendEnrollmentResult(id, "completed", uid, "")) {
        successSignal();
        showMessage("RFID card", "Registered");
      } else {
        errorSignal();
        showMessage("Server rejected", "RFID card");
      }
      delay(2500);
      return;
    }
    delay(60);
  }
  sendEnrollmentResult(id, "failed", "", "RFID scan timed out.");
  errorSignal();
  showMessage("RFID timeout", "Try again");
  delay(2500);
}

void enrollmentPollTask(void* parameter) {
  while (true) {
    unsigned long pollDelay = ENROLLMENT_POLL_MS;
    bool queueIsEmpty = enrollmentQueue && uxQueueMessagesWaiting(enrollmentQueue) == 0;
    if (ENABLE_REMOTE_ENROLLMENT &&
        !enrollmentBusy &&
        !attendanceBusy &&
        !offlineUploadsPending &&
        queueIsEmpty &&
        WiFi.status() == WL_CONNECTED) {
      {
        unsigned long pollStarted = millis();
        String response;
        int code = requestHardwareServer("/enrollment", "", response, true);
        unsigned long pollDuration = millis() - pollStarted;

        if (code != 204 && code != 200 && code != -1002) {
          Serial.println("Enrollment poll HTTP " + String(code) + " in " +
                         String(pollDuration) + " ms: " + response);
        }

        if (code == 200) {
          String id = jsonValue(response, "id");
          String method = jsonValue(response, "method");
          String currentIdentifier = jsonValue(response, "current_identifier");
          if (id != "") {
            Serial.println("Enrollment command received in " + String(pollDuration) +
                           " ms: " + method);
            EnrollmentRequest request = {};
            id.toCharArray(request.id, sizeof(request.id));
            method.toCharArray(request.method, sizeof(request.method));
            currentIdentifier.toCharArray(request.currentIdentifier, sizeof(request.currentIdentifier));
            xQueueSend(enrollmentQueue, &request, 0);
          }
        }

        if (code < 0) {
          if (code != -1002) pollDelay = 20000;
        }
      }
    }

    vTaskDelay(pdMS_TO_TICKS(pollDelay));
  }
}

bool processEnrollmentRequest() {
  if (!enrollmentQueue || enrollmentBusy) return false;

  EnrollmentRequest request;
  if (xQueueReceive(enrollmentQueue, &request, 0) != pdTRUE) return false;

  enrollmentBusy = true;
  startRegistrationIndicator();
  String method = String(request.method);
  showMessage(method == "rfid" ? "RFID register" : "Finger register", "Ready");
  registrationReadySignal();
  if (method == "rfid") enrollRfid(String(request.id));
  else if (method == "fingerprint") enrollFingerprint(String(request.id), String(request.currentIdentifier));
  stopRegistrationIndicator();
  enrollmentBusy = false;
  if (method == "fingerprint" && fingerprintAvailable) {
    waitForFingerRelease = true;
    fingerprintImageConfirmations = 0;
    fingerprintReleaseConfirmations = 0;
  }
  return true;
}

int postAttendanceRecord(const OfflineAttendanceRecord& record, String& response) {
  return requestHardwareServer("/tap", attendancePayload(record), response, false);
}

bool shouldQueueAttendance(int code) {
  return code < 0 || code == 408 || code == 429 || code >= 500;
}

void showAttendanceResponse(int code, const String& response) {
  if (code == 200) {
    String action = jsonValue(response, "action");
    String displayTime = jsonValue(response, "display_time");
    showMessage(jsonValue(response, "display_name"), attendanceDisplayLine(action, displayTime));
    if (action == "ALREADY OUT") doneSignal();
    else successSignal();
  } else if (code == 409) {
    errorSignal();
    showMessage(jsonValue(response, "display_name"), "NO SCHEDULE");
  } else {
    errorSignal();
    showMessage(code == 404 ? "Not registered" : "Server error", "Code " + String(code));
  }
}

void showOfflineSaved(const OfflineAttendanceRecord& record) {
  time_t timestamp = static_cast<time_t>(record.occurredAt);
  struct tm info;
  localtime_r(&timestamp, &info);
  char timeText[9];
  strftime(timeText, sizeof(timeText), "%I:%M %p", &info);
  String methodLabel = String(record.method) == "rfid" ? "RFID" : "FINGER";

  successSignal();
  showMessage("Saved for upload", methodLabel + " " + String(timeText));
  Serial.println("Attendance retained for upload. Pending records: " +
                 String(pendingOfflineRecordCount()));
}

void sendAttendance(String identifier, String method) {
  attendanceBusy = true;
  OfflineAttendanceRecord record = {};
  if (!createAttendanceRecord(identifier, method, record)) {
    attendanceBusy = false;
    errorSignal();
    showMessage("Time not synced", "WiFi required");
    delay(2500);
    return;
  }

  // WiFi-connected taps go directly to the server, even with an older backlog.
  if (WiFi.status() != WL_CONNECTED) {
    attendanceBusy = false;
    if (appendOfflineRecord(record)) showOfflineSaved(record);
    else {
      errorSignal();
      showMessage(offlineStorageAvailable ? "Offline full" : "Storage error", "Not recorded");
    }
    delay(2500);
    return;
  }

  unsigned long requestStarted = millis();
  digitalWrite(YELLOW_LED_PIN, HIGH);
  showMessage("Processing...", "Please wait");
  String response;
  int code = postAttendanceRecord(record, response);
  Serial.println("Attendance HTTP " + String(code) + ": " + response);
  Serial.println("Attendance response time: " + String(millis() - requestStarted) + " ms");
  digitalWrite(YELLOW_LED_PIN, LOW);
  attendanceBusy = false;

  if (shouldQueueAttendance(code)) {
    if (appendOfflineRecord(record)) showOfflineSaved(record);
    else {
      errorSignal();
      showMessage(offlineStorageAvailable ? "Offline full" : "Storage error", "Not recorded");
    }
  } else {
    showAttendanceResponse(code, response);
  }
  delay(2500);
}

void syncNextOfflineAttendance() {
  if (!offlineStorageAvailable || attendanceBusy || enrollmentBusy ||
      WiFi.status() != WL_CONNECTED || pendingOfflineRecordCount() == 0 ||
      millis() - lastOfflineSyncAttempt < offlineSyncRetryMs) return;

  lastOfflineSyncAttempt = millis();
  OfflineAttendanceRecord record = {};
  if (!readNextOfflineRecord(record)) {
    Serial.println("Could not read the next offline attendance record.");
    offlineSyncRetryMs = 60000;
    offlineSyncStatus = "SYNC: STORAGE READ ERROR / RECORDS PRESERVED";
    updateDisplayFooter();
    return;
  }

  attendanceBusy = true;
  digitalWrite(YELLOW_LED_PIN, HIGH);
  offlineSyncStatus = "SYNC: UPLOADING " + String(pendingOfflineRecordCount()) + " QUEUED SCANS";
  updateDisplayFooter();
  String response;
  int code = postAttendanceRecord(record, response);
  digitalWrite(YELLOW_LED_PIN, LOW);
  attendanceBusy = false;

  Serial.println("Offline sync HTTP " + String(code) + ": " + response);
  // A successful tap includes an action; never discard records for an HTML
  // response or an unrelated 2xx reply from a proxy or wrong endpoint.
  if (code == 200 && jsonValue(response, "action") != "") {
    acknowledgeOfflineRecord();
    offlineSyncRetryMs = OFFLINE_SYNC_INTERVAL_MS;
    offlineSyncStatus = pendingOfflineRecordCount() == 0 ? "SYNC: COMPLETE / ALL SCANS UPLOADED" : "SYNC: UPLOADED / CONTINUING QUEUE";
    Serial.println("Offline attendance synced. Remaining: " + String(pendingOfflineRecordCount()));
  } else {
    offlineSyncRetryMs = shouldQueueAttendance(code) ? 30000 : 60000;
    if (code < 0) offlineSyncStatus = "SYNC: CONNECTION FAILED (" + String(code) + ") / RETRY 30s";
    else if (code == 401 || code == 403) offlineSyncStatus = "SYNC: API KEY REJECTED / CHECK SERVER CONFIG";
    else if (code == 404) offlineSyncStatus = "SYNC: 404 / CREDENTIAL OR API NOT FOUND";
    else if (code == 422 && response.indexOf("future") >= 0) offlineSyncStatus = "SYNC: RECORD TIME IN FUTURE / CHECK CLOCK";
    else if (code == 422) offlineSyncStatus = "SYNC: 422 / SERVER REJECTED RECORD DATA";
    else if (code == 409) offlineSyncStatus = "SYNC: 409 / RECORD CONFLICT";
    else if (code >= 500) offlineSyncStatus = "SYNC: SERVER ERROR " + String(code) + " / RETRY 30s";
    else offlineSyncStatus = "SYNC: HTTP " + String(code) + " / UNEXPECTED RESPONSE";
    Serial.println("Queued record preserved: " + String(record.eventId) + " at " + attendanceTimestamp(record));
    if (code < 0) Serial.println("Transport error: " + HTTPClient::errorToString(code));
    Serial.println("Next sync attempt in " + String(offlineSyncRetryMs / 1000) + " seconds.");
  }
  // Start the backoff after the request finishes, not before its timeout.
  lastOfflineSyncAttempt = millis();
  updateDisplayFooter();
}

void processRfidAttendance() {
  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) return;
  String uid = rfidUid();
  Serial.println("RFID UID: " + uid);
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  sendAttendance(uid, "rfid");
}

void processFingerprintAttendance() {
  if (!fingerprintAvailable || waitForFingerRelease ||
      millis() - lastFingerprintScan < FINGER_SCAN_INTERVAL_MS) return;
  lastFingerprintScan = millis();
  uint8_t result = finger.getImage();
  lastFingerprintImageResult = result;
  updateDisplayFooter();
  if (result == FINGERPRINT_NOFINGER) {
    fingerprintCommunicationErrors = 0;
    fingerprintImageConfirmations = 0;
    return;
  }

  if (result != FINGERPRINT_OK) {
    fingerprintImageConfirmations = 0;
    Serial.println("Fingerprint getImage error: 0x" + String(result, HEX));
    if (result == FINGERPRINT_PACKETRECIEVEERR && ++fingerprintCommunicationErrors >= 3) {
      fingerprintAvailable = false;
      showMessage("Fingerprint disconnected", "Check power and TX/RX");
    } else if (result != FINGERPRINT_PACKETRECIEVEERR) {
      fingerprintCommunicationErrors = 0;
      errorSignal();
      showMessage("Finger image failed", "Sensor code " + String(result, HEX));
      waitForFingerRelease = true;
      fingerprintReleaseConfirmations = 0;
      delay(2000);
    }
    return;
  }
  fingerprintCommunicationErrors = 0;
  if (++fingerprintImageConfirmations < FINGER_CONFIRMATION_SAMPLES) return;
  fingerprintImageConfirmations = 0;

  waitForFingerRelease = true;
  fingerprintReleaseConfirmations = 0;
  showMessage("Reading fingerprint", "Please wait...");
  uint8_t conversion = finger.image2Tz(1);
  uint8_t match = conversion == FINGERPRINT_OK ? finger.fingerSearch(1) : conversion;
  if (conversion == FINGERPRINT_OK && match == FINGERPRINT_OK) {
    Serial.println("Fingerprint matched: FP-" + String(finger.fingerID));
    sendAttendance("FP-" + String(finger.fingerID), "fingerprint");
  } else {
    Serial.println("Fingerprint conversion: 0x" + String(conversion, HEX) +
                   ", search: 0x" + String(match, HEX));
    errorSignal();
    if (match == FINGERPRINT_PACKETRECIEVEERR) {
      fingerprintAvailable = false;
      showMessage("Sensor communication error", "Check power and TX/RX");
    } else if (conversion != FINGERPRINT_OK) {
      showMessage("Scan unclear", "Lift finger and try again");
    } else if (match == FINGERPRINT_NOTFOUND) {
      showMessage("Fingerprint not found", "Try registered finger");
    } else {
      showMessage("Fingerprint scan error", "Code " + String(match));
    }
    delay(2000);
  }
}

String attendanceDisplayLine(String action, String displayTime) {
  if (action == "ALREADY OUT") return "DONE " + displayTime;
  return action + " " + displayTime;
}

void setup() {
  Serial.begin(115200);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(YELLOW_LED_PIN, OUTPUT);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(YELLOW_LED_PIN, HIGH);
  pinMode(RFID_SS_PIN, OUTPUT);
  pinMode(TFT_CS_PIN, OUTPUT);
  digitalWrite(RFID_SS_PIN, HIGH);
  digitalWrite(TFT_CS_PIN, HIGH);
  SPI.begin(18, 19, 23);
  lcd.init();
  lcd.setRotation(1);
  // Halve the persistent sprite allocation to leave more heap for TLS.
  displayBuffer.setColorDepth(8);
  displayBufferReady = displayBuffer.createSprite(lcd.width(), 82) != nullptr;
  if (!displayBufferReady) Serial.println("Display buffer unavailable. Using opaque text updates.");
  drawDisplayFrame();
  rfid.PCD_Init();
  initializeRtc();
  rfid.PCD_SetAntennaGain(MFRC522::RxGain_max);
  byte rfidVersion = rfid.PCD_ReadRegister(rfid.VersionReg);
  Serial.print("RC522 version: 0x");
  Serial.println(rfidVersion, HEX);
  if (rfidVersion == 0x00 || rfidVersion == 0xFF) {
    Serial.println("RC522 NOT detected. Check 3.3V and SPI wiring.");
  }
  setupFingerprint();
  initializeOfflineStorage();
  hardwareHttpMutex = xSemaphoreCreateMutex();
  if (!hardwareHttpMutex) Serial.println("Could not create server request mutex. Records remain queued.");
  WiFi.setTxPower(WIFI_POWER_15dBm);
  connectWiFi();
  enrollmentQueue = xQueueCreate(1, sizeof(EnrollmentRequest));
  if (enrollmentQueue) {
    xTaskCreatePinnedToCore(enrollmentPollTask, "enrollment-poll", 12288, nullptr, 1, nullptr, 0);
    Serial.println("Background enrollment polling started.");
  } else {
    Serial.println("Could not create the enrollment request queue.");
  }
  showMessage("Payroll System", "Ready to tap");
  digitalWrite(YELLOW_LED_PIN, LOW);
  delay(1000);
}

void loop() {
  maintainWiFi();
  syncRtcFromInternet();

  if (millis() - lastClockUpdate >= 1000) {
    showClock();
    lastClockUpdate = millis();
  }

  if (!fingerprintAvailable && millis() - lastFingerprintReconnect >= 10000) {
    lastFingerprintReconnect = millis();
    fingerSerial.end();
    fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);
    fingerprintAvailable = finger.verifyPassword() && finger.getParameters() == FINGERPRINT_OK;
    fingerprintCommunicationErrors = 0;
    if (fingerprintAvailable) Serial.println("AS608 reconnected for attendance.");
  }

  if (processEnrollmentRequest()) return;

  if (waitForFingerRelease) {
    if (millis() - lastFingerprintScan >= FINGER_SCAN_INTERVAL_MS) {
      lastFingerprintScan = millis();
      uint8_t releaseResult = finger.getImage();
      lastFingerprintImageResult = releaseResult;
      if (releaseResult == FINGERPRINT_NOFINGER) {
        if (++fingerprintReleaseConfirmations >= FINGER_CONFIRMATION_SAMPLES) {
          waitForFingerRelease = false;
          fingerprintReleaseConfirmations = 0;
        }
      } else if (releaseResult == FINGERPRINT_PACKETRECIEVEERR) {
        fingerprintAvailable = false;
        waitForFingerRelease = false;
        fingerprintReleaseConfirmations = 0;
      } else {
        fingerprintReleaseConfirmations = 0;
      }
    }
  } else {
    processFingerprintAttendance();
  }

  processRfidAttendance();
  syncNextOfflineAttendance();
}

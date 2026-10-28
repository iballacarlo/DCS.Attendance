#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <RTClib.h>
#include <esp_sntp.h>
#include <sys/time.h>
#include <LiquidCrystal_I2C.h>
#include <Adafruit_Fingerprint.h>
#include <LittleFS.h>
#include <Preferences.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <time.h>
#include "device_config.h"

#ifndef HARDWARE_ROOT_CA
#error "Configure HARDWARE_ROOT_CA with the API server trusted root CA before uploading."
#endif

#ifndef FORMAT_OFFLINE_STORAGE_ONCE
#define FORMAT_OFFLINE_STORAGE_ONCE false
#endif

#define RFID_SS_PIN 5
#define RFID_RST_PIN 4
#define BUZZER_PIN 26
#define GREEN_LED_PIN 25
#define RED_LED_PIN 27
#define YELLOW_LED_PIN 32
#define FINGER_RX_PIN 13
#define FINGER_TX_PIN 14

const char* API_BASE_URL = "https://payroll-system.up.railway.app/api/hardware";

MFRC522 rfid(RFID_SS_PIN, RFID_RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);
HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);
WiFiClientSecure secureClient;
Preferences offlinePreferences;
RTC_DS3231 rtc;
bool rtcAvailable = false;
volatile bool rtcSyncPending = false;

bool fingerprintAvailable = false;
bool waitForFingerRelease = false;
unsigned long lastClockUpdate = 0;
unsigned long lastFingerprintScan = 0;
unsigned long lastWiFiAttempt = 0;
unsigned long fingerReleaseStarted = 0;
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

const unsigned long WIFI_RETRY_MS = 10000;
const unsigned long ENROLLMENT_POLL_MS = 600;
const unsigned long FINGER_RELEASE_TIMEOUT_MS = 5000;
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
QueueHandle_t enrollmentMonitorQueue = nullptr;
volatile bool enrollmentCancelled = false;
volatile uint32_t activeEnrollmentId = 0;
volatile bool enrollmentBusy = false;
volatile bool attendanceBusy = false;

void printLine(int row, String text) {
  text = text.substring(0, 16);
  while (text.length() < 16) text += " ";
  lcd.setCursor(0, row);
  lcd.print(text);
}

void showMessage(String first, String second) {
  printLine(0, first);
  printLine(1, second);
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
  // Store UTC in the RTC; LCD and attendance payloads use Philippine time.
  setenv("TZ", "PHT-8", 1);
  tzset();
  sntp_set_time_sync_notification_cb(onInternetTimeSync);
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
  time_t currentTime = time(nullptr);
  if (currentTime < 100000) {
    showMessage("Payroll System", "Time syncing...");
    return;
  }

  struct tm info;
  localtime_r(&currentTime, &info);
  char dateText[17];
  char timeText[17];
  strftime(dateText, sizeof(dateText), "%m/%d/%Y", &info);
  strftime(timeText, sizeof(timeText), "%I:%M:%S %p", &info);
  String dateLine = String(dateText);
  if (WiFi.status() != WL_CONNECTED) dateLine += " OFF";
  printLine(0, dateLine);
  printLine(1, timeText);
}

void setupFingerprint() {
  fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);
  finger.begin(57600);
  fingerprintAvailable = false;
  for (int attempt = 1; attempt <= 5 && !fingerprintAvailable; attempt++) {
    fingerprintAvailable = finger.verifyPassword();
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
  if (enrollmentCancelled) return false;
  if (fingerprintAvailable) return true;

  showMessage("AS608 reconnect", "Please wait...");
  fingerprintAvailable = false;
  for (int attempt = 1; attempt <= 5 && !fingerprintAvailable; attempt++) {
    Serial.println("Reconnecting AS608, attempt " + String(attempt) + " of 5...");
    if (enrollmentCancelled) return false;
    fingerprintAvailable = finger.verifyPassword();
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
  http.addHeader("X-Hardware-Key", HARDWARE_API_KEY);
}

bool sendEnrollmentResult(String id, String status, String identifier, String message) {
  if (enrollmentCancelled) return false;
  HTTPClient http;
  String url = String(API_BASE_URL) + "/enrollments/" + id + "/result";
  if (!http.begin(secureClient, url)) return false;
  http.setTimeout(10000);
  addHardwareHeaders(http);
  String payload = "{\"status\":\"" + status + "\",\"identifier\":\"" + identifier + "\",\"message\":\"" + message + "\"}";
  int code = http.POST(payload);
  Serial.println("Enrollment result HTTP " + String(code) + ": " + http.getString());
  http.end();
  return code >= 200 && code < 300;
}

int fingerprintSlot(String code) {
  if (!code.startsWith("FP-")) return 0;
  return code.substring(3).toInt();
}

int findFreeFingerprintSlot() {
  if (finger.getTemplateCount() != FINGERPRINT_OK) {
    fingerprintAvailable = false;
    Serial.println("Could not read AS608 storage information.");
    return -1;
  }

  int maximum = fingerprintCapacity > 0 ? fingerprintCapacity : 127;
  int firstSlot = nextFingerprintSlotHint;
  if (firstSlot < 1 || firstSlot > maximum) {
    firstSlot = finger.templateCount < maximum ? finger.templateCount + 1 : 1;
  }

  Serial.println("AS608 capacity: " + String(maximum) +
                 ", stored: " + String(finger.templateCount));
  for (int offset = 0; offset < maximum; offset++) {
    if (enrollmentCancelled) return -1;
    updateRegistrationIndicator();
    int slot = ((firstSlot - 1 + offset) % maximum) + 1;
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
    if (enrollmentCancelled) return false;
    updateRegistrationIndicator();
    uint8_t result = finger.getImage();
    if (result == FINGERPRINT_OK) return true;
    if (result != FINGERPRINT_NOFINGER) return false;
    delay(80);
  }
  return false;
}

void waitForFingerRemoval() {
  unsigned long started = millis();
  while (millis() - started < 15000 && finger.getImage() != FINGERPRINT_NOFINGER) {
    if (enrollmentCancelled) return;
    updateRegistrationIndicator();
    delay(80);
  }
}

bool captureFingerprintModel() {
  showMessage("Place finger", "First scan");
  if (!waitForFingerprintImage(30000) || finger.image2Tz(1) != FINGERPRINT_OK) return false;
  showMessage("Remove finger", "");
  waitForFingerRemoval();
  if (enrollmentCancelled) return false;
  delay(500);
  showMessage("Place same", "finger again");
  if (!waitForFingerprintImage(30000) || finger.image2Tz(2) != FINGERPRINT_OK) return false;
  return finger.createModel() == FINGERPRINT_OK;
}

void enrollFingerprint(String id, String currentIdentifier) {
  if (!reconnectFingerprint()) {
    if (enrollmentCancelled) return;
    sendEnrollmentResult(id, "failed", "", "AS608 is not available.");
    errorSignal();
    showMessage("AS608 Error", "Check wiring");
    delay(2500);
    return;
  }

  // Start the user-facing scan immediately. Slot lookup happens only after
  // both images are captured, so it cannot delay the LCD prompt.
  if (!captureFingerprintModel()) {
    if (enrollmentCancelled) return;
    sendEnrollmentResult(id, "failed", "", "Fingerprint capture failed.");
    errorSignal();
    showMessage("Enroll failed", "Try again");
    delay(2500);
    return;
  }

  showMessage("Saving finger", "Please wait...");
  int newSlot = findFreeFingerprintSlot();
  if (enrollmentCancelled) return;
  if (newSlot < 0) {
    sendEnrollmentResult(id, "failed", "", "Could not read AS608 fingerprint storage. Check sensor wiring and power.");
    errorSignal();
    showMessage("AS608 read error", "Check wiring");
    delay(2500);
    return;
  }
  if (newSlot == 0) {
    sendEnrollmentResult(id, "failed", "", "No free fingerprint slot.");
    errorSignal();
    showMessage("Finger memory", "is full");
    delay(2500);
    return;
  }

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
    if (enrollmentCancelled) return;
    errorSignal();
    showMessage("Server rejected", "fingerprint");
  }
  delay(2500);
}

void enrollRfid(String id) {
  showMessage("Tap RFID card", "Waiting...");
  unsigned long started = millis();
  while (millis() - started < 60000) {
    if (enrollmentCancelled) return;
    updateRegistrationIndicator();
    if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
      String uid = rfidUid();
      rfid.PICC_HaltA();
      rfid.PCD_StopCrypto1();
      if (sendEnrollmentResult(id, "completed", uid, "")) {
        successSignal();
        showMessage("RFID card", "Registered");
      } else {
        if (enrollmentCancelled) return;
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
  WiFiClientSecure enrollmentClient;
  enrollmentClient.setCACert(HARDWARE_ROOT_CA);
  enrollmentClient.setHandshakeTimeout(5);

  HTTPClient http;
  String url = String(API_BASE_URL) + "/enrollment";
  bool requestReady = false;
  EnrollmentRequest monitoredRequest = {};

  while (true) {
    if (enrollmentMonitorQueue) {
      xQueueReceive(enrollmentMonitorQueue, &monitoredRequest, 0);
    }
    if (enrollmentBusy && monitoredRequest.id[0] && WiFi.status() == WL_CONNECTED) {
      HTTPClient statusHttp;
      String statusUrl = String(API_BASE_URL) + "/enrollments/" + monitoredRequest.id + "/status";
      if (statusHttp.begin(enrollmentClient, statusUrl)) {
        statusHttp.setConnectTimeout(1500);
        statusHttp.setTimeout(2000);
        addHardwareHeaders(statusHttp);
        int statusCode = statusHttp.GET();
        String status = jsonValue(statusHttp.getString(), "status");
        statusHttp.end();
        if (activeEnrollmentId == String(monitoredRequest.id).toInt() &&
            (statusCode == 404 || (statusCode == 200 &&
            (status == "cancelled" || status == "expired" || status == "failed")))) {
          enrollmentCancelled = true;
        }
      }
    }
    bool queueIsEmpty = enrollmentQueue && uxQueueMessagesWaiting(enrollmentQueue) == 0;
    if (ENABLE_REMOTE_ENROLLMENT &&
        !enrollmentBusy &&
        !attendanceBusy &&
        queueIsEmpty &&
        WiFi.status() == WL_CONNECTED) {
      if (!requestReady && http.begin(enrollmentClient, url)) {
        http.setReuse(true);
        http.setConnectTimeout(3000);
        http.setTimeout(5000);
        addHardwareHeaders(http);
        requestReady = true;
      }

      if (requestReady) {
        unsigned long pollStarted = millis();
        int code = http.GET();
        String response = http.getString();
        unsigned long pollDuration = millis() - pollStarted;

        if (code != 204 && code != 200) {
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
          http.end();
          enrollmentClient.stop();
          requestReady = false;
        }
      }
    }

    vTaskDelay(pdMS_TO_TICKS(ENROLLMENT_POLL_MS));
  }
}

void processEnrollmentRequest() {
  if (!enrollmentQueue || enrollmentBusy) return;

  EnrollmentRequest request;
  if (xQueueReceive(enrollmentQueue, &request, 0) != pdTRUE) return;

  enrollmentCancelled = false;
  activeEnrollmentId = String(request.id).toInt();
  if (enrollmentMonitorQueue) xQueueOverwrite(enrollmentMonitorQueue, &request);
  enrollmentBusy = true;
  startRegistrationIndicator();
  String method = String(request.method);
  showMessage(method == "rfid" ? "RFID register" : "Finger register", "Ready");
  registrationReadySignal();
  if (method == "rfid") enrollRfid(String(request.id));
  else if (method == "fingerprint") enrollFingerprint(String(request.id), String(request.currentIdentifier));
  stopRegistrationIndicator();
  enrollmentBusy = false;
  if (enrollmentCancelled) {
    Serial.println("Registration cancelled or expired. Returning to clock.");
    waitForFingerRelease = true;
    fingerReleaseStarted = millis();
    showClock();
    lastClockUpdate = millis();
  }
}

int postAttendanceRecord(const OfflineAttendanceRecord& record, String& response) {
  if (WiFi.status() != WL_CONNECTED) return -1000;
  HTTPClient http;
  String url = String(API_BASE_URL) + "/tap";
  if (!http.begin(secureClient, url)) return -1001;
  http.setReuse(true);
  http.setConnectTimeout(1500);
  http.setTimeout(10000);
  addHardwareHeaders(http);
  int code = http.POST(attendancePayload(record));
  response = http.getString();
  http.end();
  return code;
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
  showMessage("Attendance Saved", methodLabel + " " + String(timeText));
  Serial.println("Attendance saved offline. Pending records: " +
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

  // Preserve tap order: new taps join the queue until every older event is synced.
  if (WiFi.status() != WL_CONNECTED || pendingOfflineRecordCount() > 0) {
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
      millis() - lastOfflineSyncAttempt < OFFLINE_SYNC_INTERVAL_MS) return;

  lastOfflineSyncAttempt = millis();
  OfflineAttendanceRecord record = {};
  if (!readNextOfflineRecord(record)) {
    Serial.println("Could not read the next offline attendance record.");
    return;
  }

  attendanceBusy = true;
  digitalWrite(YELLOW_LED_PIN, HIGH);
  showMessage("Syncing offline", "Please wait");
  String response;
  int code = postAttendanceRecord(record, response);
  digitalWrite(YELLOW_LED_PIN, LOW);
  attendanceBusy = false;

  Serial.println("Offline sync HTTP " + String(code) + ": " + response);
  if (code >= 200 && code < 300) {
    acknowledgeOfflineRecord();
    Serial.println("Offline attendance synced. Remaining: " + String(pendingOfflineRecordCount()));
  } else if (!shouldQueueAttendance(code)) {
    Serial.println("Offline sync paused because the server rejected the record.");
  }
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
  if (!fingerprintAvailable || waitForFingerRelease || millis() - lastFingerprintScan < 500) return;
  uint8_t result = finger.getImage();
  if (result == FINGERPRINT_NOFINGER) return;
  lastFingerprintScan = millis();

  if (result != FINGERPRINT_OK) {
    Serial.println("Fingerprint getImage error: 0x" + String(result, HEX));
    return;
  }

  waitForFingerRelease = true;
  fingerReleaseStarted = millis();
  if (finger.image2Tz() == FINGERPRINT_OK && finger.fingerSearch() == FINGERPRINT_OK) {
    Serial.println("Fingerprint matched: FP-" + String(finger.fingerID));
    sendAttendance("FP-" + String(finger.fingerID), "fingerprint");
  } else {
    Serial.println("Fingerprint not registered or could not be converted.");
    errorSignal();
    showMessage("Fingerprint", "Not registered");
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
  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();
  initializeRtc();
  SPI.begin(18, 19, 23, RFID_SS_PIN);
  rfid.PCD_Init();
  rfid.PCD_SetAntennaGain(MFRC522::RxGain_max);
  byte rfidVersion = rfid.PCD_ReadRegister(rfid.VersionReg);
  Serial.print("RC522 version: 0x");
  Serial.println(rfidVersion, HEX);
  if (rfidVersion == 0x00 || rfidVersion == 0xFF) {
    Serial.println("RC522 NOT detected. Check 3.3V and SPI wiring.");
  }
  setupFingerprint();
  initializeOfflineStorage();
  secureClient.setCACert(HARDWARE_ROOT_CA);
  WiFi.setTxPower(WIFI_POWER_15dBm);
  connectWiFi();
  enrollmentQueue = xQueueCreate(1, sizeof(EnrollmentRequest));
  enrollmentMonitorQueue = xQueueCreate(1, sizeof(EnrollmentRequest));
  if (enrollmentQueue && enrollmentMonitorQueue) {
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
    fingerprintAvailable = finger.verifyPassword();
    if (fingerprintAvailable) Serial.println("AS608 reconnected for attendance.");
  }

  if (waitForFingerRelease) {
    uint8_t releaseResult = finger.getImage();
    if (releaseResult == FINGERPRINT_NOFINGER ||
        releaseResult == FINGERPRINT_PACKETRECIEVEERR ||
        millis() - fingerReleaseStarted >= FINGER_RELEASE_TIMEOUT_MS) {
      waitForFingerRelease = false;
    }
  } else {
    processFingerprintAttendance();
  }

  processRfidAttendance();
  processEnrollmentRequest();
  syncNextOfflineAttendance();
}

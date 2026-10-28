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
#include "railway_root_ca.h"

#ifndef HARDWARE_ROOT_CA
#error "Configure HARDWARE_ROOT_CA with the API server trusted root CA before uploading."
#endif

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

class AttendanceDisplay : public lgfx::LGFX_Device {
  lgfx::Panel_ILI9488 panel;
  lgfx::Bus_SPI bus;

 public:
  AttendanceDisplay() {
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

const char* API_BASE_URL = "https://dcsattendance.up.railway.app/api/hardware";
const char* API_HOST = "dcsattendance.up.railway.app";
SemaphoreHandle_t hardwareHttpMutex = nullptr;
SemaphoreHandle_t rfidCacheMutex = nullptr;
String registeredRfidUids;
bool rfidCacheValid = false;
bool rfidCachePersisted = false;
volatile bool rfidCacheSyncRequested = true;
unsigned long lastRfidCacheSync = 0;
const char* RFID_CACHE_PATH = "/rfid-cache.txt";
const char* RFID_CACHE_TEMP = "/rfid-cache.tmp";
const char* RFID_CACHE_BACKUP = "/rfid-cache.bak";
const size_t MAX_RFID_CACHE_BYTES = 16000;

MFRC522 rfid(RFID_SS_PIN, RFID_RST_PIN);
AttendanceDisplay lcd;
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
volatile bool queuedUploadBlinking = false;
volatile bool queuedUploadLedOn = false;
uint32_t offlineQueueHead = 0;
uint32_t offlineEventSequence = 0;
unsigned long lastOfflineSyncAttempt = 0;
unsigned long offlineSyncRetryMs = 1500;
String offlineSyncStatus = "FACULTY ATTENDANCE";
volatile bool offlineUploadsPending = false;
volatile bool serverReconnectRequested = false;
uint8_t serverTransportFailures = 0;
volatile int lastServerHttpCode = 0;
volatile unsigned long lastServerResponseAt = 0;
uint32_t offlineSyncProcessed = 0;
unsigned long offlineSyncNoticeAt = 0;
time_t timeOutAvailableAt = 0;
unsigned long lastServerReconnect = 0;

const unsigned long WIFI_RETRY_MS = 10000;
const unsigned long DISPLAY_CLOCK_REFRESH_MS = 200;
const unsigned long ENROLLMENT_POLL_MS = 2500;
const unsigned long ENROLLMENT_STATUS_POLL_MS = 750;
const unsigned long FINGER_SCAN_INTERVAL_MS = 60;
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
  char operation[16];
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
volatile bool enrollmentCancelled = false;
volatile bool enrollmentExpired = false;
volatile uint32_t activeEnrollmentId = 0;

const uint16_t DISPLAY_BACKGROUND = 0xFFFF;
const uint16_t DISPLAY_GREEN = 0x0266;
const uint16_t DISPLAY_INK = 0x1082;
const uint16_t DISPLAY_MUTED = 0x632C;
const uint16_t DISPLAY_ERROR = 0xB104;
const uint16_t DISPLAY_WARNING = 0x9360;
const uint16_t DISPLAY_RULE = 0xD6BA;
const uint16_t DISPLAY_GOLD = 0xFEA8;
const uint16_t DISPLAY_FOOTER = 0xEF7D;
const int DISPLAY_FIRST_ROW_Y = 48;
const int DISPLAY_SECOND_ROW_Y = 142;
lgfx::LGFX_Sprite displayBuffer(&lcd);
bool displayBufferReady = false;
bool displayFrameReady = false;
bool displayShowingClock = false;
String lastDisplayLines[2];
String lastDisplayFooter;
String lastDisplaySyncStatus;
String lastDisplayHeading;
String lastDisplayServerStatus;
uint32_t displayMessageStarted = 0;
bool marqueeFacultyName = false;
String marqueeFacultyText;
unsigned long lastFacultyMarqueeDraw = 0;
unsigned long marqueeFacultyStarted = 0;
uint8_t fingerprintCommunicationErrors = 0;
uint8_t lastFingerprintImageResult = FINGERPRINT_TIMEOUT;

void drawTextBand(int y, String text, int textSize, uint16_t color,
                  bool compactFont = false, bool marquee = false) {
  lgfx::LGFXBase* target = displayBufferReady
    ? static_cast<lgfx::LGFXBase*>(&displayBuffer)
    : static_cast<lgfx::LGFXBase*>(&lcd);
  int top = displayBufferReady ? 0 : y;
  if (displayBufferReady) {
    displayBuffer.fillSprite(DISPLAY_BACKGROUND);
  } else {
    lcd.fillRect(0, y, lcd.width(), 82, DISPLAY_BACKGROUND);
  }
  target->setFont(compactFont ? &fonts::Font2 : &fonts::Font4);
  target->setTextDatum(marquee ? lgfx::middle_left : lgfx::middle_center);
  target->setTextColor(color, DISPLAY_BACKGROUND);
  target->setTextSize(textSize);
  // Keep every faculty name at the same size without taking over the
  // attendance-result row.
  if (marquee) {
    // Hold the name with a comfortable margin before one gentle pass to the
    // left. It stops with the ending visible instead of looping or reversing.
    int nameWidth = target->textWidth(text);
    int overflow = nameWidth - (lcd.width() - 40);
    unsigned long elapsed = millis() - marqueeFacultyStarted;
    int offset = elapsed <= 1500 ? 0 : min(overflow, static_cast<int>((elapsed - 1500) / 30));
    target->drawString(text, 20 - offset, top + 41);
  } else if (target->textWidth(text) > lcd.width() - 40) {
    while (text.length() && target->textWidth(text + "...") > lcd.width() - 40) {
      text.remove(text.length() - 1);
    }
    text += "...";
    target->drawString(text, lcd.width() / 2, top + 41);
  } else {
    target->drawString(text, lcd.width() / 2, top + 41);
  }
  if (displayBufferReady) displayBuffer.pushSprite(0, y);
}

void drawDisplayFrame() {
  if (displayFrameReady) return;
  lcd.fillScreen(DISPLAY_BACKGROUND);
  lcd.fillRect(0, 0, lcd.width(), 40, DISPLAY_GREEN);
  lcd.fillRect(0, 40, lcd.width(), 2, DISPLAY_GOLD);
  lcd.drawFastHLine(16, 284, lcd.width() - 32, DISPLAY_RULE);
  displayFrameReady = true;
}

void drawDisplayHeading(const String& heading) {
  if (heading == lastDisplayHeading) return;
  lastDisplayHeading = heading;
  lcd.fillRect(0, 0, lcd.width(), 40, DISPLAY_GREEN);
  lcd.drawCircle(25, 20, 8, DISPLAY_BACKGROUND);
  lcd.drawLine(25, 20, 25, 15, DISPLAY_BACKGROUND);
  lcd.drawLine(25, 20, 29, 22, DISPLAY_BACKGROUND);
  lcd.setFont(&fonts::Font2);
  lcd.setTextSize(1);
  lcd.setTextDatum(lgfx::middle_left);
  lcd.setTextColor(DISPLAY_BACKGROUND, DISPLAY_GREEN);
  lcd.drawString(heading, 44, 20);
}

void printLine(int row, String text, int fixedTextSize = 0,
               bool compactFont = false, bool marquee = false) {
  if (lastDisplayLines[row] == text && !marquee) return;
  lastDisplayLines[row] = text;
  int size = fixedTextSize > 0
    ? fixedTextSize
    : (displayShowingClock ? (row == 1 ? 2 : 1) : (row == 0 ? 2 : 1));
  uint16_t color = displayShowingClock
    ? (row == 0 ? DISPLAY_MUTED : DISPLAY_INK)
    : (row == 0 ? DISPLAY_INK : DISPLAY_GREEN);
  drawTextBand(row == 0 ? DISPLAY_FIRST_ROW_Y : DISPLAY_SECOND_ROW_Y,
               text, size, color, compactFont, marquee);
}

void refreshFacultyMarquee() {
  if (!marqueeFacultyName || displayShowingClock || millis() - lastFacultyMarqueeDraw < 35) return;
  lastFacultyMarqueeDraw = millis();
  drawTextBand(DISPLAY_FIRST_ROW_Y, marqueeFacultyText, 2, DISPLAY_INK, false, true);
}

void waitWithDisplayRefresh(unsigned long durationMs) {
  unsigned long started = millis();
  while (millis() - started < durationMs) {
    refreshFacultyMarquee();
    refreshTimeOutCountdown();
    delay(20);
  }
}

void updateDisplayFooter() {
  uint32_t pending = pendingOfflineRecordCount();
  bool connected = WiFi.status() == WL_CONNECTED;
  bool freshServerStatus = lastServerResponseAt != 0 &&
                          millis() - lastServerResponseAt < 30000;
  String serverText = !connected ? "SERVER / WAITING FOR WI-FI"
    : !freshServerStatus ? "SERVER / CHECKING CONNECTION"
    : lastServerHttpCode == 401 || lastServerHttpCode == 403 ? "SERVER / ACCESS DENIED"
    : lastServerHttpCode >= 500 ? "SERVER / TEMPORARILY UNAVAILABLE"
    : lastServerHttpCode > 0 ? "SERVER / ONLINE" : "SERVER / UNREACHABLE";
  if (serverText != lastDisplayServerStatus) {
    lastDisplayServerStatus = serverText;
    lcd.fillRect(0, 228, lcd.width(), 28, DISPLAY_BACKGROUND);
    lcd.setFont(&fonts::Font2);
    lcd.setTextSize(1);
    lcd.setTextDatum(lgfx::middle_center);
    lcd.setTextColor(connected && freshServerStatus && lastServerHttpCode > 0 &&
                     lastServerHttpCode < 400 ? DISPLAY_GREEN : DISPLAY_MUTED,
                     DISPLAY_BACKGROUND);
    lcd.drawString(serverText, lcd.width() / 2, 242);
  }
  String syncText;
  bool syncError = false;
  if (offlineSyncStatus.startsWith("SYNC:") &&
      (pending > 0 || (offlineSyncNoticeAt != 0 && millis() - offlineSyncNoticeAt < 5000))) {
    syncText = offlineSyncStatus.substring(6);
    syncError = offlineSyncStatus.indexOf("ERROR") >= 0 ||
                offlineSyncStatus.indexOf("FAILED") >= 0 ||
                offlineSyncStatus.indexOf("REJECTED") >= 0 ||
                offlineSyncStatus.indexOf("HTTP") >= 0;
  } else if (!fingerprintAvailable) {
    syncText = "FINGERPRINT SENSOR UNAVAILABLE";
    syncError = true;
  } else if (!connected && pending > 0) {
    syncText = "SCANS SAVED / WAITING FOR CONNECTION";
  }
  if (syncText != lastDisplaySyncStatus) {
    lastDisplaySyncStatus = syncText;
    lcd.fillRect(0, 260, lcd.width(), 20, DISPLAY_BACKGROUND);
    lcd.setTextDatum(lgfx::middle_center);
    lcd.setFont(&fonts::Font0);
    lcd.setTextSize(1);
    lcd.setTextColor(syncError ? DISPLAY_ERROR : DISPLAY_MUTED, DISPLAY_BACKGROUND);
    lcd.drawString(syncText.substring(0, 73), lcd.width() / 2, 270);
  }
  String text = String(connected ? "WI-FI CONNECTED" : "WI-FI OFFLINE") + String(pending);
  if (text == lastDisplayFooter) return;
  lastDisplayFooter = text;
  lcd.fillRect(0, 288, lcd.width(), 32, DISPLAY_FOOTER);
  lcd.fillCircle(20, 303, 4, connected ? DISPLAY_GREEN : DISPLAY_ERROR);
  lcd.setFont(&fonts::Font2);
  lcd.setTextSize(1);
  lcd.setTextColor(DISPLAY_MUTED, DISPLAY_FOOTER);
  lcd.setTextDatum(lgfx::middle_left);
  lcd.drawString(connected ? "WI-FI CONNECTED" : "WI-FI OFFLINE", 34, 303);
  lcd.drawFastVLine(lcd.width() / 2 + 20, 296, 15, DISPLAY_RULE);
  lcd.setTextDatum(lgfx::middle_right);
  lcd.setTextColor(pending ? DISPLAY_WARNING : DISPLAY_MUTED, DISPLAY_FOOTER);
  lcd.drawString("PENDING " + String(pending), lcd.width() - 18, 303);
}

void showMessage(String first, String second, bool firstIsFacultyName = false) {
  timeOutAvailableAt = 0;
  displayMessageStarted = millis();
  drawDisplayFrame();
  drawDisplayHeading(firstIsFacultyName ? "ATTENDANCE RESULT" : "DEVICE STATUS");
  if (displayShowingClock) {
    lastDisplayLines[0] = "\x01";
    lastDisplayLines[1] = "\x01";
  }
  displayShowingClock = false;
  lcd.setFont(&fonts::Font4);
  lcd.setTextSize(2);
  marqueeFacultyName = firstIsFacultyName && lcd.textWidth(first) > lcd.width() - 40;
  marqueeFacultyText = marqueeFacultyName ? first : "";
  marqueeFacultyStarted = millis();
  lastFacultyMarqueeDraw = 0;
  // Faculty names stay prominent and uniform. System messages use a smaller
  // compact font so messages such as "Fingerprint not found" remain complete.
  printLine(0, first, 2, !firstIsFacultyName, marqueeFacultyName);
  printLine(1, second, 2, true);
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

void queuedUploadBlinkTask(void* parameter) {
  while (true) {
    if (queuedUploadBlinking) {
      queuedUploadLedOn = !queuedUploadLedOn;
      digitalWrite(YELLOW_LED_PIN, queuedUploadLedOn ? HIGH : LOW);
      vTaskDelay(pdMS_TO_TICKS(250));
    } else {
      if (queuedUploadLedOn) {
        queuedUploadLedOn = false;
        digitalWrite(YELLOW_LED_PIN, LOW);
      }
      vTaskDelay(pdMS_TO_TICKS(50));
    }
  }
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

bool validRfidList(const String& uids) {
  if (uids.length() > MAX_RFID_CACHE_BYTES) return false;
  if (uids.isEmpty()) return true;
  unsigned int tokenLength = 0;
  for (unsigned int i = 0; i <= uids.length(); i++) {
    char c = i == uids.length() ? ',' : uids[i];
    if (c == ',') {
      if (tokenLength != 8 && tokenLength != 14 && tokenLength != 20) return false;
      tokenLength = 0;
    } else {
      if (!((c >= '0' && c <= '9') || (c >= 'A' && c <= 'F'))) return false;
      tokenLength++;
    }
  }
  return true;
}

uint32_t rfidListChecksum(const String& uids) {
  uint32_t checksum = 2166136261UL;
  for (unsigned int i = 0; i < uids.length(); i++) {
    checksum = (checksum ^ static_cast<uint8_t>(uids[i])) * 16777619UL;
  }
  return checksum;
}

bool readRfidCache(const char* path, String& uids) {
  File file = LittleFS.open(path, FILE_READ);
  if (!file) return false;
  if (file.size() < 9 || file.size() > MAX_RFID_CACHE_BYTES + 9) {
    file.close();
    return false;
  }
  String checksum = file.readStringUntil('\n');
  uids = file.readString();
  file.close();
  if (checksum.length() != 8 || !validRfidList(uids)) return false;
  char expected[9];
  snprintf(expected, sizeof(expected), "%08lX", static_cast<unsigned long>(rfidListChecksum(uids)));
  return checksum == expected;
}

// Caller holds rfidCacheMutex. Keep a verified old file until the new one is installed.
bool saveRfidCache(const String& uids) {
  if (!offlineStorageAvailable) return false;
  File file = LittleFS.open(RFID_CACHE_TEMP, FILE_WRITE);
  if (!file) return false;
  char checksum[10];
  snprintf(checksum, sizeof(checksum), "%08lX\n", static_cast<unsigned long>(rfidListChecksum(uids)));
  bool saved = file.print(checksum) == 9 && file.print(uids) == uids.length();
  file.close();
  String verified;
  if (!saved || !readRfidCache(RFID_CACHE_TEMP, verified) || verified != uids) return false;
  if (LittleFS.exists(RFID_CACHE_PATH)) {
    String previous;
    if (readRfidCache(RFID_CACHE_PATH, previous)) {
      if (LittleFS.exists(RFID_CACHE_BACKUP) && !LittleFS.remove(RFID_CACHE_BACKUP)) return false;
      if (!LittleFS.rename(RFID_CACHE_PATH, RFID_CACHE_BACKUP)) return false;
    } else if (!LittleFS.remove(RFID_CACHE_PATH)) return false;
  }
  if (!LittleFS.rename(RFID_CACHE_TEMP, RFID_CACHE_PATH)) return false;
  return true;
}

void initializeRfidCache() {
  rfidCacheMutex = xSemaphoreCreateMutex();
  if (!rfidCacheMutex || !offlineStorageAvailable) return;
  String uids;
  if (readRfidCache(RFID_CACHE_PATH, uids) || readRfidCache(RFID_CACHE_BACKUP, uids)) {
    registeredRfidUids = uids;
    rfidCacheValid = true;
    rfidCachePersisted = true;
    Serial.println("Registered RFID list restored from storage.");
  }
}

// -1 means no verified list: preserve the tap for server verification.
int cachedRfidRegistration(const String& uid) {
  if (!rfidCacheMutex || xSemaphoreTake(rfidCacheMutex, 0) != pdTRUE) return -1;
  int result = !rfidCacheValid ? -1 :
    (("," + registeredRfidUids + ",").indexOf("," + uid + ",") >= 0 ? 1 : 0);
  xSemaphoreGive(rfidCacheMutex);
  return result;
}

void updateCachedRfid(const String& oldUid, const String& newUid) {
  if (!rfidCacheMutex || xSemaphoreTake(rfidCacheMutex, pdMS_TO_TICKS(1000)) != pdTRUE) return;
  if (rfidCacheValid) {
    String updated = registeredRfidUids.isEmpty() ? "," : "," + registeredRfidUids + ",";
    if (oldUid.length()) updated.replace("," + oldUid + ",", ",");
    if (updated.indexOf("," + newUid + ",") < 0) updated += newUid + ",";
    updated = updated.substring(1, updated.length() - 1);
    if (validRfidList(updated)) {
      registeredRfidUids = updated;
      rfidCachePersisted = saveRfidCache(updated);
      if (!rfidCachePersisted) Serial.println("RFID list updated in RAM; storage update failed.");
    }
  }
  xSemaphoreGive(rfidCacheMutex);
  rfidCacheSyncRequested = true;
}

int requestHardwareServer(String path, String payload, String& response, bool background,
                          bool monitorEnrollment);

void syncRegisteredRfidCards() {
  if (!rfidCacheMutex || attendanceBusy || enrollmentBusy || WiFi.status() != WL_CONNECTED) return;
  unsigned long interval = rfidCacheSyncRequested ? 15000 : 60000;
  if (lastRfidCacheSync != 0 && millis() - lastRfidCacheSync < interval) return;
  lastRfidCacheSync = millis();
  rfidCacheSyncRequested = false;
  String response;
  int code = requestHardwareServer("/rfid-cards", "", response, true, true);
  if (enrollmentBusy) {
    rfidCacheSyncRequested = true;
    return;
  }
  if (code != 200 || jsonValue(response, "complete") != "true") {
    rfidCacheSyncRequested = true;
    if (code != -1002) Serial.println("RFID list sync HTTP " + String(code) + "; previous list retained.");
    return;
  }
  String uids = jsonValue(response, "uids");
  // Require the field itself even when a valid server list is empty.
  if (response.indexOf("\"uids\":\"") < 0 || !validRfidList(uids)) return;
  if (xSemaphoreTake(rfidCacheMutex, pdMS_TO_TICKS(1000)) != pdTRUE) return;
  if (!rfidCacheValid || registeredRfidUids != uids) {
    registeredRfidUids = uids;
    rfidCacheValid = true;
    rfidCachePersisted = false;
  }
  if (!rfidCachePersisted) {
    rfidCachePersisted = saveRfidCache(uids);
    if (!rfidCachePersisted) Serial.println("RFID list synced in RAM; storage update failed.");
  }
  xSemaphoreGive(rfidCacheMutex);
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

void startWiFiInBackground() {
  lastWiFiAttempt = millis();
  WiFi.disconnect();
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.begin(WIFI_NAME, WIFI_PASSWORD);
  Serial.println("Connecting WiFi in the background.");
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
    rfidCacheSyncRequested = true;
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
  if (fingerprintImageConfirmations > 0) return;
  if (!displayShowingClock && millis() - displayMessageStarted < 3000) return;
  drawDisplayHeading(hasValidClock() ? "READY TO SCAN" : "SYNCING CLOCK");
  if (!displayShowingClock) {
    lastDisplayLines[0] = "\x01";
    lastDisplayLines[1] = "\x01";
  }
  displayShowingClock = true;
  timeOutAvailableAt = 0;
  marqueeFacultyName = false;
  marqueeFacultyText = "";
  time_t currentTime = time(nullptr);
  if (!hasValidClock()) {
    printLine(0, "Clock not set", 2, true);
    printLine(1, "Attendance unavailable", 2, true);
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
  if (enrollmentCancelled || enrollmentExpired) return false;
  if (fingerprintAvailable) return true;

  fingerSerial.end();
  fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);

  showMessage("AS608 reconnect", "Please wait...");
  fingerprintAvailable = false;
  for (int attempt = 1; attempt <= 5 && !fingerprintAvailable; attempt++) {
    if (enrollmentCancelled || enrollmentExpired) return false;
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

int requestHardwareServer(String path, String payload, String& response, bool background,
                          bool monitorEnrollment = false) {
  response = "";
  if (WiFi.status() != WL_CONNECTED) return -1000;
  if (!hardwareHttpMutex || xSemaphoreTake(hardwareHttpMutex,
      background ? 0 : pdMS_TO_TICKS(45000)) != pdTRUE) return -1002;

  // One TLS client at a time: simultaneous handshakes compete for ESP32 heap.
  if (background && (attendanceBusy || (enrollmentBusy && !monitorEnrollment))) {
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
    secureClient.setCACert(HARDWARE_ROOT_CA);
    secureClient.setHandshakeTimeout(monitorEnrollment ? 2 : 15);
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
    http.setConnectTimeout(monitorEnrollment ? 2000 : 15000);
    http.setTimeout(monitorEnrollment ? 2000 : 15000);
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
  lastServerHttpCode = code;
  lastServerResponseAt = millis();
  return code;
}

int lastEnrollmentHttpCode = 0;
String lastEnrollmentError;
bool enrollmentResultRejected = false;

bool sendEnrollmentResult(String id, String status, String identifier, String message) {
  String payload = "{\"status\":\"" + status + "\",\"identifier\":\"" + identifier + "\",\"message\":\"" + message + "\"}";
  String response;
  enrollmentResultRejected = false;
  lastEnrollmentError = "Server unconfirmed";
  for (int attempt = 0; attempt < 2; attempt++) {
    if (enrollmentCancelled || enrollmentExpired) return false;
    int code = requestHardwareServer("/enrollments/" + id + "/result", payload, response, false);
    lastEnrollmentHttpCode = code;
    Serial.println("Enrollment result HTTP " + String(code) + ": " + response);
    if (code == 200 && jsonValue(response, "status") == status &&
        (status != "completed" || jsonValue(response, "identifier") == identifier)) return true;
    String error = jsonValue(response, "message");
    if (error.length()) lastEnrollmentError = error;

    // Reconcile a lost POST reply before retrying or removing a local template.
    String stateResponse;
    int stateCode = requestHardwareServer("/enrollments/" + id + "/status", "", stateResponse, false);
    String state = jsonValue(stateResponse, "status");
    if (stateCode == 200 && state == status &&
        (status != "completed" || jsonValue(stateResponse, "identifier") == identifier)) return true;
    if (stateCode == 200 && state == "cancelled") enrollmentCancelled = true;
    if (stateCode == 200 && state == "expired") enrollmentExpired = true;
    if (stateCode == 200 && (state == "failed" || state == "cancelled" ||
                            state == "expired" || state == "completed")) {
      enrollmentResultRejected = true;
      error = jsonValue(stateResponse, "message");
      if (error.length()) lastEnrollmentError = error;
      return false;
    }
    if (code >= 400 && code < 500 && code != 408 && code != 429 && code != 409) {
      enrollmentResultRejected = true;
      return false;
    }
    delay(500);
  }
  return false;
}

int fingerprintSlot(String code) {
  if (!code.startsWith("FP-") || code.length() <= 3 || code.length() > 8) return 0;
  for (unsigned int i = 3; i < code.length(); i++) {
    if (code[i] < '0' || code[i] > '9') return 0;
  }
  if (code.substring(3).toInt() > 65535) return 0;
  return code.substring(3).toInt();
}

uint8_t readFingerprintIndexPage(uint8_t page, uint8_t* occupied) {
  uint8_t command[] = {0x1F, page};
  Adafruit_Fingerprint_Packet packet(FINGERPRINT_COMMANDPACKET, sizeof(command), command);
  finger.writeStructuredPacket(packet);
  uint8_t result = finger.getStructuredPacket(&packet);
  if (result != FINGERPRINT_OK) return result;
  if (packet.type != FINGERPRINT_ACKPACKET) return FINGERPRINT_PACKETRECIEVEERR;
  if (packet.data[0] != FINGERPRINT_OK) return packet.data[0];
  if (packet.length != 35) return FINGERPRINT_PACKETRECIEVEERR;
  memcpy(occupied, packet.data + 1, 32);
  return FINGERPRINT_OK;
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
  int maximum = fingerprintCapacity > 1 ? fingerprintCapacity - 1 : 127;
  int oldSlot = fingerprintSlot(currentIdentifier);
  int firstSlot = nextFingerprintSlotHint;
  if (oldSlot >= 1 && oldSlot <= maximum) firstSlot = oldSlot;
  if (firstSlot < 1 || firstSlot > maximum) {
    firstSlot = finger.templateCount < maximum ? finger.templateCount + 1 : 1;
  }

  Serial.println("AS608 capacity: " + String(maximum) +
                 ", stored: " + String(finger.templateCount));
  uint8_t occupied[32] = {};
  int loadedPage = -1;
  for (int offset = 0; offset < maximum; offset++) {
    if (enrollmentCancelled || enrollmentExpired) return -1;
    updateRegistrationIndicator();
    int slot = ((firstSlot - 1 + offset) % maximum) + 1;
    if (slot != oldSlot && reservedSlots.indexOf("," + String(slot) + ",") >= 0) continue;
    int page = slot / 256;
    if (finger.templateCount > 0 && loadedPage != page) {
      uint8_t result = readFingerprintIndexPage(page, occupied);
      if (result != FINGERPRINT_OK) {
        Serial.println("AS608 index read error: 0x" + String(result, HEX));
        return -1;
      }
      loadedPage = page;
    }
    bool used = finger.templateCount > 0 && (occupied[(slot % 256) / 8] & (1 << (slot % 8)));
    if (!used) {
      nextFingerprintSlotHint = slot;
      Serial.println("Using free fingerprint slot " + String(slot) + ".");
      return slot;
    }
  }
  return 0;
}

bool waitForFingerprintImage(unsigned long timeoutMs) {
  unsigned long started = millis();
  while (millis() - started < timeoutMs) {
    if (enrollmentCancelled || enrollmentExpired) return false;
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
    if (enrollmentCancelled || enrollmentExpired) return false;
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
  if (!waitForFingerprintImage(30000) || enrollmentCancelled || enrollmentExpired ||
      finger.image2Tz(1) != FINGERPRINT_OK) return false;
  showMessage("Remove finger", "");
  if (!waitForFingerRemoval()) return false;
  for (int i = 0; i < 10; i++) {
    if (enrollmentCancelled || enrollmentExpired) return false;
    delay(50);
  }
  showMessage("Place same", "finger again");
  if (!waitForFingerprintImage(30000) || enrollmentCancelled || enrollmentExpired ||
      finger.image2Tz(2) != FINGERPRINT_OK) return false;
  if (enrollmentCancelled || enrollmentExpired) return false;
  return finger.createModel() == FINGERPRINT_OK;
}

void registrationFeedbackDelay(unsigned long durationMs) {
  unsigned long started = millis();
  while (!enrollmentCancelled && !enrollmentExpired && millis() - started < durationMs) {
    delay(50);
  }
}

void enrollFingerprint(String id, String currentIdentifier) {
  if (!reconnectFingerprint()) {
    if (enrollmentCancelled || enrollmentExpired) return;
    sendEnrollmentResult(id, "failed", "", "AS608 is not available.");
    errorSignal();
    showMessage("AS608 Error", "Check wiring");
    registrationFeedbackDelay(2500);
    return;
  }

  // loadModel() writes sensor buffer 1. Find a slot before capturing the
  // new template so occupied-slot checks cannot overwrite it before saving.
  int newSlot = findFreeFingerprintSlot(currentIdentifier);
  if (enrollmentCancelled || enrollmentExpired) return;
  if (newSlot == -2) {
    sendEnrollmentResult(id, "failed", "", "Could not verify available fingerprint slots with the server.");
    errorSignal();
    showMessage("Server unavailable", "Try again later");
    registrationFeedbackDelay(2500);
    return;
  }
  if (newSlot < 0) {
    sendEnrollmentResult(id, "failed", "", "Could not read AS608 fingerprint storage. Check sensor wiring and power.");
    errorSignal();
    showMessage("AS608 read error", "Check wiring");
    registrationFeedbackDelay(2500);
    return;
  }
  if (newSlot == 0) {
    sendEnrollmentResult(id, "failed", "", "No free fingerprint slot not assigned to another faculty member.");
    errorSignal();
    showMessage("Finger memory", "is full");
    registrationFeedbackDelay(2500);
    return;
  }

  if (!captureFingerprintModel()) {
    if (enrollmentCancelled || enrollmentExpired) return;
    sendEnrollmentResult(id, "failed", "", "Fingerprint capture failed.");
    errorSignal();
    showMessage("Enroll failed", "Try again");
    registrationFeedbackDelay(2500);
    return;
  }

  if (enrollmentCancelled || enrollmentExpired) return;
  showMessage("Saving finger", "Please wait...");
  uint8_t stored = finger.storeModel(newSlot);
  if (enrollmentCancelled || enrollmentExpired) {
    if (stored == FINGERPRINT_OK) finger.deleteModel(newSlot);
    return;
  }
  if (stored != FINGERPRINT_OK) {
    sendEnrollmentResult(id, "failed", "", "Fingerprint could not be saved to the AS608.");
    errorSignal();
    showMessage("Save failed", "Try again");
    registrationFeedbackDelay(2500);
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
    if (enrollmentCancelled || enrollmentExpired || enrollmentResultRejected) {
      finger.deleteModel(newSlot);
      nextFingerprintSlotHint = newSlot;
    }
    if (enrollmentCancelled || enrollmentExpired) return;
    errorSignal();
    showMessage((enrollmentResultRejected ? "Server rejected " : "Unconfirmed HTTP ") +
                String(lastEnrollmentHttpCode), lastEnrollmentError);
  }
  registrationFeedbackDelay(2500);
}

void unlinkFingerprint(String id, String currentIdentifier) {
  if (!reconnectFingerprint()) {
    if (enrollmentCancelled || enrollmentExpired) return;
    sendEnrollmentResult(id, "failed", "", "AS608 is not available to remove the fingerprint.");
    errorSignal();
    showMessage("AS608 Error", "Check wiring");
    registrationFeedbackDelay(2500);
    return;
  }

  int slot = fingerprintSlot(currentIdentifier);
  if (slot <= 0) {
    sendEnrollmentResult(id, "failed", "", "The fingerprint template identifier is invalid.");
    errorSignal();
    showMessage("Remove failed", "Invalid template ID");
    registrationFeedbackDelay(2500);
    return;
  }

  showMessage("Removing fingerprint", "Please wait...");
  uint8_t result = finger.deleteModel(slot);
  if (result != FINGERPRINT_OK) {
    sendEnrollmentResult(id, "failed", "", "AS608 could not delete the fingerprint template.");
    errorSignal();
    showMessage("Remove failed", "Sensor code " + String(result, HEX));
    registrationFeedbackDelay(2500);
    return;
  }

  if (sendEnrollmentResult(id, "completed", currentIdentifier, "")) {
    successSignal();
    showMessage("Fingerprint", "Unlinked");
  } else {
    errorSignal();
    showMessage("Server unconfirmed", lastEnrollmentError);
  }
  registrationFeedbackDelay(2500);
}

void enrollRfid(String id, String currentIdentifier) {
  showMessage("Tap RFID card", "Waiting...");
  unsigned long started = millis();
  while (millis() - started < 60000) {
    if (enrollmentCancelled || enrollmentExpired) return;
    updateRegistrationIndicator();
    if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
      String uid = rfidUid();
      rfid.PICC_HaltA();
      rfid.PCD_StopCrypto1();
      if (enrollmentCancelled || enrollmentExpired) return;
      if (sendEnrollmentResult(id, "completed", uid, "")) {
        updateCachedRfid(currentIdentifier, uid);
        successSignal();
        showMessage("RFID card", "Registered");
      } else {
        if (enrollmentCancelled || enrollmentExpired) return;
        errorSignal();
        showMessage("Server rejected", "RFID card");
      }
      registrationFeedbackDelay(2500);
      return;
    }
    delay(60);
  }
  if (enrollmentCancelled || enrollmentExpired) return;
  sendEnrollmentResult(id, "failed", "", "RFID scan timed out.");
  errorSignal();
  showMessage("RFID timeout", "Try again");
  registrationFeedbackDelay(2500);
}

void enrollmentPollTask(void* parameter) {
  while (true) {
    syncRegisteredRfidCards();
    unsigned long pollDelay = ENROLLMENT_POLL_MS;
    uint32_t enrollmentId = activeEnrollmentId;
    if (enrollmentBusy && enrollmentId != 0 && !enrollmentCancelled && !enrollmentExpired &&
        WiFi.status() == WL_CONNECTED) {
      String response;
      int code = requestHardwareServer("/enrollments/" + String(enrollmentId) + "/status",
                                       "", response, true, true);
      if (code == 200 && enrollmentBusy && activeEnrollmentId == enrollmentId) {
        String status = jsonValue(response, "status");
        if (status == "cancelled" || status == "expired") {
          enrollmentExpired = status == "expired";
          enrollmentCancelled = status == "cancelled";
          Serial.println("Enrollment " + String(enrollmentId) + " " + status + " on server.");
        }
      }
      pollDelay = ENROLLMENT_STATUS_POLL_MS;
    }
    bool queueIsEmpty = enrollmentQueue && uxQueueMessagesWaiting(enrollmentQueue) == 0;
    if (ENABLE_REMOTE_ENROLLMENT &&
        !enrollmentBusy &&
        !attendanceBusy &&
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
          String operation = jsonValue(response, "operation");
          String currentIdentifier = jsonValue(response, "current_identifier");
          if (id != "") {
            Serial.println("Enrollment command received in " + String(pollDuration) +
                           " ms: " + method);
            EnrollmentRequest request = {};
            id.toCharArray(request.id, sizeof(request.id));
            method.toCharArray(request.method, sizeof(request.method));
            operation.toCharArray(request.operation, sizeof(request.operation));
            currentIdentifier.toCharArray(request.currentIdentifier, sizeof(request.currentIdentifier));
            if (xQueueSend(enrollmentQueue, &request, 0) == pdTRUE) {
              pollDelay = ENROLLMENT_STATUS_POLL_MS;
            }
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

  enrollmentCancelled = false;
  enrollmentExpired = false;
  activeEnrollmentId = strtoul(request.id, nullptr, 10);
  enrollmentBusy = true;
  startRegistrationIndicator();
  String method = String(request.method);
  String operation = String(request.operation);
  if (operation == "unlink") {
    unlinkFingerprint(String(request.id), String(request.currentIdentifier));
  } else if (method == "rfid") {
    showMessage("RFID register", "Ready");
    registrationReadySignal();
    if (!enrollmentCancelled && !enrollmentExpired) enrollRfid(String(request.id), String(request.currentIdentifier));
  } else if (!enrollmentCancelled && !enrollmentExpired && method == "fingerprint") {
    enrollFingerprint(String(request.id), String(request.currentIdentifier));
  }
  stopRegistrationIndicator();
  bool wasCancelled = enrollmentCancelled;
  bool wasExpired = enrollmentExpired;
  activeEnrollmentId = 0;
  enrollmentBusy = false;
  if (wasCancelled || wasExpired) {
    showMessage(wasCancelled ? "Registration cancelled" : "Registration expired",
                "Returning to clock");
    Serial.println("Enrollment stopped. Returning to attendance.");
  }
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

void refreshTimeOutCountdown() {
  if (timeOutAvailableAt == 0 || displayShowingClock) return;
  time_t now = time(nullptr);
  unsigned long remaining = timeOutAvailableAt > now ? timeOutAvailableAt - now : 0;
  char countdown[32];
  snprintf(countdown, sizeof(countdown), "Time-out in %02lu:%02lu", remaining / 60, remaining % 60);
  printLine(1, remaining ? String(countdown) : "You may scan again", 2, true);
}

void showAttendanceResponse(int code, const String& response, const String& method) {
  if (code == 200) {
    String action = jsonValue(response, "action");
    String displayTime = jsonValue(response, "display_time");
    showMessage(jsonValue(response, "display_name"), attendanceDisplayLine(action, displayTime), true);
    if (action == "WAIT") {
      timeOutAvailableAt = static_cast<time_t>(jsonValue(response, "time_out_available_at").toInt());
      if (timeOutAvailableAt > 0) refreshTimeOutCountdown();
      else printLine(1, "Time-out at " + displayTime, 2, true);
      doneSignal();
      return;
    }
    if (action == "IN" || action == "OUT") successSignal();
    else if (action == "ALREADY OUT") doneSignal();
    else {
      errorSignal();
      showMessage(jsonValue(response, "display_name"),
                  action == "NO CLASS" ? "No eligible class" : "Unexpected response", true);
    }
  } else if (code == 409) {
    errorSignal();
    showMessage("Record conflict", "See administrator");
  } else if (code == 404) {
    errorSignal();
    showMessage(method == "rfid" ? "Card not registered" : "Fingerprint not registered",
                "See administrator");
  } else {
    errorSignal();
    showMessage("Server error", "Please try again");
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
  bool needsVerification = String(record.method) == "rfid" &&
                           cachedRfidRegistration(String(record.identifier)) < 0;
  showMessage("Saved on device", needsVerification ? "Awaiting verification" : "Awaiting upload");
  Serial.println("Attendance retained for upload. Pending records: " +
                 String(pendingOfflineRecordCount()));
}

void sendAttendance(String identifier, String method) {
  attendanceBusy = true;
  OfflineAttendanceRecord record = {};
  if (!createAttendanceRecord(identifier, method, record)) {
    attendanceBusy = false;
    errorSignal();
    showMessage("Clock not set", "Attendance unavailable");
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
    // WiFi association alone does not mean the attendance server is reachable.
    if (method == "rfid" && cachedRfidRegistration(identifier) == 0) {
      showMessage("Card not registered", "See administrator");
      errorSignal();
    } else if (appendOfflineRecord(record)) showOfflineSaved(record);
    else {
      errorSignal();
      showMessage(offlineStorageAvailable ? "Offline full" : "Storage error", "Not recorded");
    }
  } else {
    showAttendanceResponse(code, response, method);
  }
  waitWithDisplayRefresh(2500);
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
  queuedUploadBlinking = true;
  offlineSyncStatus = "SYNC: UPLOADING " + String(offlineSyncProcessed + 1) + " OF " +
                      String(offlineSyncProcessed + pendingOfflineRecordCount());
  updateDisplayFooter();
  String response;
  int code = postAttendanceRecord(record, response);
  queuedUploadBlinking = false;
  attendanceBusy = false;

  Serial.println("Offline sync HTTP " + String(code) + ": " + response);
  String rejectionMessage = jsonValue(response, "message");
  // Laravel JSON may escape the slash in RFID/fingerprint as RFID\/fingerprint.
  rejectionMessage.replace("\\/", "/");
  // A successful tap includes an action; never discard records for an HTML
  // response or an unrelated 2xx reply from a proxy or wrong endpoint.
  if (code == 200 && jsonValue(response, "action") != "") {
    acknowledgeOfflineRecord();
    offlineSyncProcessed++;
    offlineSyncRetryMs = OFFLINE_SYNC_INTERVAL_MS;
    offlineSyncStatus = pendingOfflineRecordCount() == 0 ? "SYNC: UPLOAD COMPLETE / QUEUE CLEARED" : "SYNC: UPLOADING / CONTINUING QUEUE";
    offlineSyncNoticeAt = millis();
    if (pendingOfflineRecordCount() == 0) offlineSyncProcessed = 0;
    Serial.println("Offline attendance synced. Remaining: " + String(pendingOfflineRecordCount()));
  } else if (code == 404 && rejectionMessage ==
             "RFID/fingerprint is not registered to an active faculty member.") {
    // Only discard a confirmed credential rejection, never a proxy/API 404.
    acknowledgeOfflineRecord();
    offlineSyncProcessed++;
    offlineSyncRetryMs = OFFLINE_SYNC_INTERVAL_MS;
    offlineSyncStatus = "SYNC: UNREGISTERED SCAN REMOVED";
    offlineSyncNoticeAt = millis();
    if (pendingOfflineRecordCount() == 0) offlineSyncProcessed = 0;
    Serial.println("Unregistered queued scan removed: " + String(record.eventId) +
                   ". Remaining: " + String(pendingOfflineRecordCount()));
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
  if (WiFi.status() != WL_CONNECTED && cachedRfidRegistration(uid) == 0) {
    showMessage("Card not registered", "See administrator");
    errorSignal();
    waitWithDisplayRefresh(2500);
    return;
  }
  sendAttendance(uid, "rfid");
}

void processFingerprintAttendance() {
  if (!fingerprintAvailable || waitForFingerRelease ||
      millis() - lastFingerprintScan < FINGER_SCAN_INTERVAL_MS) return;
  lastFingerprintScan = millis();
  unsigned long scanStarted = millis();
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
      showMessage("Finger image failed", "Sensor code " + String(result, HEX));
      errorSignal();
      waitForFingerRelease = true;
      fingerprintReleaseConfirmations = 0;
      delay(2000);
    }
    return;
  }
  fingerprintCommunicationErrors = 0;
  if (fingerprintImageConfirmations == 0) {
    showMessage("Reading fingerprint", "Hold finger steady");
  }
  if (++fingerprintImageConfirmations < FINGER_CONFIRMATION_SAMPLES) return;
  fingerprintImageConfirmations = 0;

  waitForFingerRelease = true;
  fingerprintReleaseConfirmations = 0;
  showMessage("Reading fingerprint", "Please wait...");
  uint8_t conversion = finger.image2Tz(1);
  uint8_t match = conversion == FINGERPRINT_OK ? finger.fingerSearch(1) : conversion;
  Serial.println("Fingerprint capture + match: " + String(millis() - scanStarted) +
                 " ms / conversion: " + String(conversion, HEX) +
                 " / search: " + String(match, HEX));
  if (conversion == FINGERPRINT_OK && match == FINGERPRINT_OK) {
    Serial.println("Fingerprint matched: FP-" + String(finger.fingerID) +
                   " / score: " + String(finger.confidence));
    sendAttendance("FP-" + String(finger.fingerID), "fingerprint");
  } else {
    Serial.println("Fingerprint conversion: 0x" + String(conversion, HEX) +
                   ", search: 0x" + String(match, HEX));
    // Signal the rejection before updating the TFT. This keeps the red LED
    // independent of any slow display redraw after an unknown fingerprint.
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
  xTaskCreatePinnedToCore(queuedUploadBlinkTask, "queue-led", 2048, nullptr, 1, nullptr, 0);
  pinMode(RFID_SS_PIN, OUTPUT);
  pinMode(TFT_CS_PIN, OUTPUT);
  digitalWrite(RFID_SS_PIN, HIGH);
  digitalWrite(TFT_CS_PIN, HIGH);
  SPI.begin(18, 19, 23);
  lcd.init();
  // Landscape, with the TFT's header pins on the left-hand side.
  lcd.setRotation(1);
  // Halve the persistent sprite allocation to leave more heap for TLS.
  displayBuffer.setColorDepth(8);
  displayBufferReady = displayBuffer.createSprite(lcd.width(), 82) != nullptr;
  if (!displayBufferReady) Serial.println("Display buffer unavailable. Using opaque text updates.");
  drawDisplayFrame();
  drawDisplayHeading("STARTING UP");
  printLine(0, "Starting device", 2, true);
  printLine(1, "Initializing sensors...", 2, true);
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
  initializeRfidCache();
  hardwareHttpMutex = xSemaphoreCreateMutex();
  if (!hardwareHttpMutex) Serial.println("Could not create server request mutex. Records remain queued.");
  WiFi.setTxPower(WIFI_POWER_15dBm);
  // Do not hold the startup screen while WiFi negotiates. The RTC and local
  // attendance queue are ready immediately; maintainWiFi() completes this.
  startWiFiInBackground();
  enrollmentQueue = xQueueCreate(1, sizeof(EnrollmentRequest));
  if (enrollmentQueue) {
    xTaskCreatePinnedToCore(enrollmentPollTask, "enrollment-poll", 12288, nullptr, 1, nullptr, 0);
    Serial.println("Background enrollment polling started.");
  } else {
    Serial.println("Could not create the enrollment request queue.");
  }
  showMessage("DCS Attendance & DTR", "Ready to tap");
  tone(BUZZER_PIN, 1400, 180);
  digitalWrite(YELLOW_LED_PIN, LOW);
  delay(1000);
}

void loop() {
  maintainWiFi();
  syncRtcFromInternet();

  if (millis() - lastClockUpdate >= DISPLAY_CLOCK_REFRESH_MS) {
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

#define LGFX_USE_V1
#include <LovyanGFX.hpp>
#include <Adafruit_Fingerprint.h>

class TestDisplay : public lgfx::LGFX_Device {
  lgfx::Panel_ILI9488 panel;
  lgfx::Bus_SPI bus;

 public:
  TestDisplay() {
    auto spi = bus.config();
    spi.spi_host = HSPI_HOST;
    spi.spi_mode = 0;
    spi.freq_write = 20000000;
    spi.freq_read = 16000000;
    spi.spi_3wire = false;
    spi.use_lock = true;
    spi.dma_channel = 1;
    spi.pin_sclk = 32;
    spi.pin_mosi = 33;
    spi.pin_miso = -1;
    spi.pin_dc = 26;
    bus.config(spi);
    panel.setBus(&bus);

    auto screen = panel.config();
    screen.pin_cs = 25;
    screen.pin_rst = 27;
    screen.pin_busy = -1;
    screen.memory_width = 320;
    screen.memory_height = 480;
    screen.panel_width = 320;
    screen.panel_height = 480;
    screen.offset_x = 0;
    screen.offset_y = 0;
    screen.offset_rotation = 0;
    screen.readable = false;
    screen.invert = false;
    screen.rgb_order = false;
    screen.dlen_16bit = false;
    screen.bus_shared = false;
    panel.config(screen);
    setPanel(&panel);
  }
};

TestDisplay lcd;
HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);
bool sensorReady = false;
bool waitingForRemoval = false;
uint32_t samples = 0;
unsigned long lastPoll = 0;
unsigned long lastDetection = 0;
unsigned long lastProbe = 0;
String lastState;

String codeText(uint8_t code) {
  char text[5];
  snprintf(text, sizeof(text), "%02X", code);
  return String(text);
}

void showState(const String& state) {
  if (state == lastState) return;
  lastState = state;
  lcd.fillRect(0, 95, lcd.width(), 100, 0xFFFF);
  lcd.setTextColor(0x0247, 0xFFFF);
  lcd.setTextSize(2);
  lcd.setTextDatum(lgfx::middle_center);
  lcd.drawString(state, lcd.width() / 2, 140);
  Serial.println(state);
}

void probeSensor() {
  sensorReady = false;
  waitingForRemoval = false;
  fingerSerial.end();
  fingerSerial.begin(57600, SERIAL_8N1, 13, 14);
  delay(2000);
  for (int attempt = 1; attempt <= 5 && !sensorReady; attempt++) {
    // Discard stale/late replies before starting another command sequence.
    while (fingerSerial.available()) fingerSerial.read();
    showState("CHECKING SENSOR " + String(attempt) + "/5");
    bool verified = finger.verifyPassword();
    uint8_t parameters = FINGERPRINT_TIMEOUT;
    if (verified) parameters = finger.getParameters();
    lcd.setTextDatum(lgfx::middle_center);
    lcd.setTextColor(0x0247, 0xFFFF);
    lcd.setTextSize(2);
    lcd.setTextPadding(lcd.width());
    lcd.drawString(String("VERIFY: ") + (verified ? "OK" : "FAIL") + " / PARAM: " +
                   (verified ? codeText(parameters) : "--"),
                   lcd.width() / 2, 220);
    lcd.drawString("UART RX13 / TX14 / 57600", lcd.width() / 2, 260);
    lcd.setTextPadding(0);
    sensorReady = verified && parameters == FINGERPRINT_OK;
    if (!sensorReady) delay(400);
  }
  lastProbe = millis();
  if (!sensorReady) {
    showState("NO SENSOR / RETRY 10s");
    return;
  }
  uint8_t result = finger.getTemplateCount();
  lcd.setTextColor(0x0247, 0xFFFF);
  lcd.setTextSize(2);
  lcd.setTextPadding(lcd.width());
  lcd.drawString(result == FINGERPRINT_OK
                   ? "Templates: " + String(finger.templateCount)
                   : "Template count error: " + codeText(result),
                 lcd.width() / 2, 70);
  lcd.setTextPadding(0);
  showState("SENSOR READY");
}

void setup() {
  Serial.begin(115200);
  pinMode(5, OUTPUT);
  digitalWrite(5, HIGH);
  lcd.init();
  lcd.setRotation(1);
  lcd.fillScreen(0xFFFF);
  lcd.fillRect(0, 0, lcd.width(), 48, 0x0247);
  lcd.setTextDatum(lgfx::middle_center);
  lcd.setTextColor(0xFFFF, 0x0247);
  lcd.setTextSize(2);
  lcd.drawString("AS608 SENSOR TEST", lcd.width() / 2, 24);

  // Keep the same UART pins as the payroll firmware.
  probeSensor();
}

void loop() {
  if (!sensorReady && millis() - lastProbe >= 10000) probeSensor();
  if (!sensorReady || millis() - lastPoll < 120) {
    delay(5);
    return;
  }
  lastPoll = millis();
  uint8_t image = finger.getImage();
  samples++;
  lcd.setTextDatum(lgfx::middle_center);
  lcd.setTextColor(0x0247, 0xFFFF);
  lcd.setTextSize(2);
  lcd.setTextPadding(lcd.width());
  lcd.drawString("GETIMAGE: " + codeText(image), lcd.width() / 2, 220);
  lcd.drawString("Samples: " + String(samples), lcd.width() / 2, 260);
  lcd.setTextPadding(0);

  if (waitingForRemoval) {
    if (image == FINGERPRINT_NOFINGER && millis() - lastDetection >= 2000) {
      waitingForRemoval = false;
    }
    return;
  }
  if (image == FINGERPRINT_NOFINGER) {
    showState("NO FINGER DETECTED (02)");
    return;
  }
  if (image != FINGERPRINT_OK) {
    showState("IMAGE ERROR: " + codeText(image));
    return;
  }

  showState("IMAGE CAPTURED (00)");
  waitingForRemoval = true;
  lastDetection = millis();
  uint8_t conversion = finger.image2Tz(1);
  if (conversion != FINGERPRINT_OK) {
    showState("CONVERSION ERROR: " + codeText(conversion));
    return;
  }
  uint8_t match = finger.fingerSearch(1);
  if (match == FINGERPRINT_OK) showState("MATCH: FP-" + String(finger.fingerID));
  else if (match == FINGERPRINT_NOTFOUND) showState("IMAGE OK / NO STORED MATCH");
  else showState("SEARCH ERROR: " + codeText(match));
}

#define TFT_SCK_PIN 32
#define TFT_MOSI_PIN 33
#define TFT_CS_PIN 25
#define TFT_DC_PIN 26
#define TFT_RST_PIN 27

void writeBus(uint8_t value) {
  for (uint8_t mask = 0x80; mask; mask >>= 1) {
    digitalWrite(TFT_MOSI_PIN, value & mask ? HIGH : LOW);
    digitalWrite(TFT_SCK_PIN, LOW);
    digitalWrite(TFT_SCK_PIN, HIGH);
  }
}

void writeCommand(uint8_t command) {
  digitalWrite(TFT_DC_PIN, LOW);
  writeBus(command);
}

void writeData(uint8_t data) {
  digitalWrite(TFT_DC_PIN, HIGH);
  writeBus(data);
}

void writeCommandData(uint8_t command, const uint8_t* data, size_t length) {
  writeCommand(command);
  for (size_t index = 0; index < length; index++) writeData(data[index]);
}

void initializeDisplay() {
  digitalWrite(TFT_RST_PIN, HIGH);
  delay(5);
  digitalWrite(TFT_RST_PIN, LOW);
  delay(20);
  digitalWrite(TFT_RST_PIN, HIGH);
  delay(150);
  digitalWrite(TFT_CS_PIN, LOW);

  const uint8_t adjustControl[] = {0xA9, 0x51, 0x2C, 0x82};
  const uint8_t powerControl1[] = {0x11, 0x09};
  const uint8_t powerControl2[] = {0x41};
  const uint8_t powerControl3[] = {0x00, 0x0A, 0x80};
  const uint8_t frameRate[] = {0xB0, 0x11};
  const uint8_t inversionControl[] = {0x02};
  const uint8_t displayFunction[] = {0x02, 0x22};
  const uint8_t entryModeSet[] = {0xC6};
  const uint8_t entryMode[] = {0x00, 0x04};
  const uint8_t imageFunction[] = {0x00};
  const uint8_t memoryAccess[] = {0x08};
  const uint8_t pixelFormat[] = {0x66};
  const uint8_t positiveGamma[] = {
      0x00, 0x07, 0x10, 0x09, 0x17, 0x0B, 0x41, 0x89,
      0x4B, 0x0A, 0x0C, 0x0E, 0x18, 0x1B, 0x0F};
  const uint8_t negativeGamma[] = {
      0x00, 0x17, 0x1A, 0x04, 0x0E, 0x06, 0x2F, 0x45,
      0x43, 0x02, 0x0A, 0x09, 0x32, 0x36, 0x0F};

  writeCommandData(0xF7, adjustControl, sizeof(adjustControl));
  writeCommandData(0xC0, powerControl1, sizeof(powerControl1));
  writeCommandData(0xC1, powerControl2, sizeof(powerControl2));
  writeCommandData(0xC5, powerControl3, sizeof(powerControl3));
  writeCommandData(0xB1, frameRate, sizeof(frameRate));
  writeCommandData(0xB4, inversionControl, sizeof(inversionControl));
  writeCommandData(0xB6, displayFunction, sizeof(displayFunction));
  writeCommandData(0xB7, entryModeSet, sizeof(entryModeSet));
  writeCommandData(0xBE, entryMode, sizeof(entryMode));
  writeCommandData(0xE9, imageFunction, sizeof(imageFunction));
  writeCommandData(0x36, memoryAccess, sizeof(memoryAccess));
  writeCommandData(0x3A, pixelFormat, sizeof(pixelFormat));
  writeCommandData(0xE0, positiveGamma, sizeof(positiveGamma));
  writeCommandData(0xE1, negativeGamma, sizeof(negativeGamma));

  writeCommand(0x11);
  delay(120);
  writeCommand(0x29);
  delay(20);
  digitalWrite(TFT_CS_PIN, HIGH);
}

void setAddressWindow(uint16_t x1, uint16_t y1, uint16_t x2, uint16_t y2) {
  writeCommand(0x2A);
  writeData(x1 >> 8);
  writeData(x1);
  writeData(x2 >> 8);
  writeData(x2);
  writeCommand(0x2B);
  writeData(y1 >> 8);
  writeData(y1);
  writeData(y2 >> 8);
  writeData(y2);
  writeCommand(0x2C);
}

void fillScreen(uint16_t color) {
  const uint8_t red = (color >> 8) & 0xF8;
  const uint8_t green = (color >> 3) & 0xFC;
  const uint8_t blue = (color << 3) & 0xF8;

  digitalWrite(TFT_CS_PIN, LOW);
  setAddressWindow(0, 0, 319, 479);
  for (uint32_t pixel = 0; pixel < 320UL * 480UL; pixel++) {
    writeData(red);
    writeData(green);
    writeData(blue);
  }
  digitalWrite(TFT_CS_PIN, HIGH);
}

void setup() {
  Serial.begin(115200);
  pinMode(TFT_SCK_PIN, OUTPUT);
  pinMode(TFT_MOSI_PIN, OUTPUT);
  pinMode(TFT_CS_PIN, OUTPUT);
  pinMode(TFT_DC_PIN, OUTPUT);
  pinMode(TFT_RST_PIN, OUTPUT);
  digitalWrite(TFT_SCK_PIN, HIGH);
  digitalWrite(TFT_MOSI_PIN, HIGH);
  digitalWrite(TFT_CS_PIN, HIGH);
  digitalWrite(TFT_DC_PIN, HIGH);
  digitalWrite(TFT_RST_PIN, HIGH);

  Serial.println("Starting seller-compatible ILI9488 test...");
  initializeDisplay();
  Serial.println("ILI9488 initialized. Starting color test.");
}

void loop() {
  fillScreen(0xF800);
  delay(1000);
  fillScreen(0x07E0);
  delay(1000);
  fillScreen(0x001F);
  delay(1000);
  fillScreen(0x0000);
  delay(1000);
  fillScreen(0xFFE0);
  delay(1000);
}

#define LGFX_USE_V1
#include <LovyanGFX.hpp>
#include "../DCSAttendanceTFT/cvsu_logo.h"
#include "../DCSAttendanceTFT/dcs_logo.h"

constexpr int TFT_SCK_PIN = 32;
constexpr int TFT_MOSI_PIN = 33;
constexpr int TFT_CS_PIN = 25;
constexpr int TFT_DC_PIN = 26;
constexpr int TFT_RST_PIN = 27;

constexpr uint16_t COLOR_GREEN = 0x0266;
constexpr uint16_t COLOR_GOLD = 0xFEA8;
constexpr uint16_t COLOR_WHITE = 0xFFFF;
constexpr uint16_t COLOR_INK = 0x1082;
constexpr uint16_t COLOR_MUTED = 0x632C;
constexpr uint16_t COLOR_RULE = 0xD6BA;

class PreviewDisplay : public lgfx::LGFX_Device {
  lgfx::Panel_ILI9488 panel;
  lgfx::Bus_SPI bus;

 public:
  PreviewDisplay() {
    auto spi = bus.config();
    spi.spi_host = HSPI_HOST;
    spi.spi_mode = 0;
    spi.freq_write = 20000000;
    spi.freq_read = 16000000;
    spi.spi_3wire = false;
    spi.use_lock = true;
    spi.dma_channel = 1;
    spi.pin_sclk = TFT_SCK_PIN;
    spi.pin_mosi = TFT_MOSI_PIN;
    spi.pin_miso = -1;
    spi.pin_dc = TFT_DC_PIN;
    bus.config(spi);
    panel.setBus(&bus);

    auto screen = panel.config();
    screen.pin_cs = TFT_CS_PIN;
    screen.pin_rst = TFT_RST_PIN;
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

PreviewDisplay lcd;

void drawLogo(const uint16_t* logo, int x, int y) {
  // Draw horizontal color runs instead of pushImage. This avoids byte-order
  // differences that can make a logo's transparent background look blue.
  lcd.startWrite();
  for (int row = 0; row < 64; row++) {
    int runStart = 0;
    uint16_t runColor = pgm_read_word(&logo[row * 64]);
    for (int column = 1; column <= 64; column++) {
      uint16_t color = column < 64
        ? pgm_read_word(&logo[row * 64 + column])
        : runColor;
      if (column == 64 || color != runColor) {
        if (runColor != COLOR_GREEN) {
          lcd.drawFastHLine(x + runStart, y + row, column - runStart, runColor);
        }
        runStart = column;
        runColor = color;
      }
    }
  }
  lcd.endWrite();
}

void drawCentered(String text, int x, int y, uint16_t color, const lgfx::IFont* font) {
  lcd.setFont(font);
  lcd.setTextDatum(lgfx::middle_center);
  lcd.setTextColor(color, COLOR_WHITE);
  lcd.drawString(text, x, y);
}

void setup() {
  lcd.init();
  // Landscape, with the TFT's header pins on the left-hand side.
  lcd.setRotation(1);
  lcd.fillScreen(COLOR_WHITE);

  lcd.fillRect(0, 0, lcd.width(), 76, COLOR_GREEN);
  lcd.fillRect(0, 76, lcd.width(), 2, COLOR_GOLD);
  drawLogo(CVSU_LOGO, 12, 6);
  drawLogo(DCS_LOGO, lcd.width() - 76, 6);

  lcd.setTextDatum(lgfx::top_left);
  lcd.setFont(&fonts::Font4);
  lcd.setTextColor(COLOR_WHITE, COLOR_GREEN);
  lcd.drawString("CvSU IMUS", 92, 13);
  lcd.setFont(&fonts::Font2);
  lcd.setTextColor(COLOR_GOLD, COLOR_GREEN);
  lcd.drawString("DCS / FACULTY ATTENDANCE", 94, 46);

  drawCentered("Mon, Oct 12, 2026", lcd.width() / 2, 130, COLOR_MUTED, &fonts::Font4);
  drawCentered("08:42:16 AM", lcd.width() / 2, 202, COLOR_INK, &fonts::Font7);

  lcd.drawFastHLine(16, 257, lcd.width() - 32, COLOR_RULE);
  lcd.fillCircle(20, 281, 4, COLOR_GREEN);
  lcd.setTextDatum(lgfx::middle_left);
  lcd.setFont(&fonts::Font2);
  lcd.setTextColor(COLOR_MUTED, COLOR_WHITE);
  lcd.drawString("WI-FI CONNECTED", 34, 281);
  lcd.setTextDatum(lgfx::middle_right);
  lcd.drawString("PENDING 0", lcd.width() - 18, 281);
}

void loop() {
  delay(1000);
}

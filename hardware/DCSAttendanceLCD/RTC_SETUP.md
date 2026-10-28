# DS3231 with the I2C LCD

Install **RTClib by Adafruit** using the Arduino Library Manager. Install its
Adafruit BusIO dependency when prompted. Upload `DCSAttendanceLCD.ino`.

| DS3231 | ESP32 |
| --- | --- |
| VCC | Dedicated 3V3 |
| GND | Common GND |
| SDA | GPIO 21 |
| SCL | GPIO 22 |
| SQW, 32K | Disconnected |

The RTC and I2C LCD share SDA/SCL. For a ZS-042 with a CR2032, disable the
board's battery charging circuit. The expansion board's 5V VCC rail must not
be used for the RTC in this setup.

## First use

1. Power on with WiFi and internet access.
2. Wait for `DS3231 updated from internet time. Offline clock is ready.` in
   Serial Monitor at 115200 baud. WiFi connection alone does not confirm time sync.
3. Verify the LCD shows Philippine time.
4. Disconnect WiFi, turn the device off, then power it on again without WiFi.
5. The RTC restores the clock. Scan a registered card or fingerprint, then
   restore WiFi to upload queued attendance with the original scan time.

The firmware stores UTC in the RTC and converts it to Philippine time for the
LCD and attendance payloads. Do not manually set the RTC to Philippine local
time using a separate test sketch. A new RTC or one reporting battery power
loss must be synchronized online before attendance can be recorded.

The CR2032 keeps only the RTC running during a power outage. The ESP32 and
scanners still need the main power supply to record attendance.

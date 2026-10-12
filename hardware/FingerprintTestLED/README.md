# AS608 Sensor-Only LED Test

This temporary sketch tests raw image capture without TFT, WiFi, RFID, RTC,
buzzer, enrollment, or attendance uploads. It never stores/deletes fingerprint
templates, changes sensor configuration, or accesses LittleFS/Preferences.
Green confirms image capture, not a fingerprint match or attendance recording.

## Wiring

Disconnect adapter, USB, and battery/UPS power before changing connections.
Disconnect every TFT wire (including signal wires, VCC, GND, and LED), and
disconnect the RFID, RTC, and buzzer for this test. Keep only the ESP32,
AS608, and three indicator LEDs attached.

| Connection | Destination |
| --- | --- |
| Sensor TX | ESP32 D13 (RX) |
| Sensor RX | ESP32 D14 (TX) |
| Sensor VCC | Existing verified regulated 5V rail for this sensor |
| Sensor GND | Common GND |
| ESP32 5V/VIN | Existing verified 5V supply |
| ESP32 GND | Common GND |
| D16 | 220-330 ohm resistor -> green LED anode |
| D17 | 220-330 ohm resistor -> red LED anode |
| D2 | 220-330 ohm resistor -> yellow LED anode |
| All LED cathodes | Common GND |

Use actual sensor pin labels; wire colors are not universal. Each LED needs
its own resistor. D2 is a boot-strapping pin: do not wire its LED to 5V or add
an external pull-up. Do not connect the 5V rail to the ESP32 3V3 pin.

## Upload And Test

1. Open `FingerprintTestLED.ino`. Keep the same board/partition settings used
   for the payroll firmware, with flash erase disabled.
2. Upload with USB only and external power disconnected. If needed, disconnect
   peripheral wiring during upload to reduce USB load. Do not combine USB and
   adapter/UPS power without verified power isolation.
3. Disconnect USB and all power before reconnecting the test wiring. Power
   the test from the normal adapter/UPS supply.
4. Wait for startup verification to finish, then place a finger flat on the
   sensor for 5-10 seconds. Try another finger as well.

| LED | Meaning |
| --- | --- |
| Yellow during startup, blinking on failed attempts | Sensor verification in progress |
| Yellow steady after verification | Sensor returns NOFINGER (02) |
| Green | Sensor returned image OK (00); held for 2 seconds after removal |
| Red | Verification, UART, or image capture error; not evidence of an unregistered finger |

Failed verification and repeated UART errors trigger another probe after
10 seconds. Optional Serial Monitor at 115200 baud reports verification,
GETIMAGE codes, and an increasing sample counter. The library maps packet
receive errors to 01; image failure is 03. Do not connect laptop USB alongside
external power merely to obtain logs.

If green appears without the TFT, power off before reconnecting the TFT and
use the matching grouped-pin TFT test for comparison. This distinguishes
capture behavior under different loads; it does not by itself prove a specific
power, wiring, or sensor fault. If only yellow persists, compare against a
known-working sensor/controller rather than erasing the nine stored templates.

The payroll firmware must be restored after testing. No attendance is recorded
while this sketch is running. The main TFT sketch must contain the working
code before it can be restored; do not upload an empty file.

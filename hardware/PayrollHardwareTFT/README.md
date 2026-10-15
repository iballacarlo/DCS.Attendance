# Payroll Hardware - 3.5-inch ILI9488 TFT

This is the TFT variant of the payroll attendance firmware. The original
16x2 I2C version remains in `hardware/PayrollHardware`.

## Grouped wiring revision

This pinout replaces the earlier TFT wiring. With the expansion-board power
connectors at the bottom, TFT signals occupy the five consecutive left rows
D32, D33, D25, D26, D27. AS608 stays on D13/D14 below them (leave D12 unused).
RC522 signals remain on the right. The RTC uses the nearby D21/D22 sockets,
including the duplicate four-pin header. Indicator LEDs use D16/D17/D2 and the
buzzer uses D15. Some groups cannot be fully consecutive without using reserved
or boot-sensitive pins. Leave EN, VP/VN, D34/D35, D12, TX0/RX0 unconnected.

Disconnect adapter, battery/UPS, and USB before moving wires. Move all old LED
and buzzer wires off the new TFT pins before powering on. Upload matching code
with the same partition scheme and flash erase disabled. Both TFT test sketches
now use this grouped pinout; the original I2C sketch keeps its original wiring.
This is a wiring cleanup, not a confirmed fix for fingerprint GETIMAGE 02.

Use the labeled signal column, not the VCC/GND columns. Duplicate GPIO sockets
are the same electrical signal. Do not join different GPIO pins together.
Verify the selectable VCC rail with a meter; use dedicated 3V3 for 3.3V devices.

GPIO2, GPIO5, and GPIO15 are boot-strapping pins. Yellow LED must be wired
active-high through its resistor to GND, not to 5V or 3V3. Do not add external
pull-ups to GPIO2. If upload/boot fails, power off and disconnect loads on these
pins for diagnosis. Use a suitable driver for a buzzer that exceeds GPIO current
limits; never connect a 5V signal to the ESP32 GPIO.

## Arduino libraries

Install these through **Tools > Manage Libraries**:

- LovyanGFX by lovyan03
- MFRC522 by GithubCommunity
- Adafruit Fingerprint Sensor Library by Adafruit
- RTClib by Adafruit (and its Adafruit BusIO dependency)

The ESP32 board package supplies WiFi, HTTPClient, LittleFS, Preferences,
FreeRTOS, SPI, and time support.

## Power distribution

Use the regulated 5V 3A supply. Do not connect its 5V output to the ESP32
`3V3` pin.

| Power connection | Destination |
| --- | --- |
| Supply `+5V` | ESP32 `VIN/5V`, TFT `VCC`, AS608 `VCC` |
| Supply `GND` | ESP32 `GND` and the GND of every module |
| ESP32 `3V3` | RC522 `3.3V`, TFT `LED`, DS3231 `VCC` |

Every module must use the same common ground. Disconnect the external 5V
supply while uploading through USB to avoid powering the ESP32 from two
sources at the same time.

## TFT wiring (ILI9488 SPI)

| TFT pin | ESP32 pin |
| --- | --- |
| VCC | 5V (this MSP3520-style module accepts 3.3V to 5V) |
| GND | GND |
| SCK / CLK | GPIO 32 |
| SDO / MISO | Not connected |
| SDI / MOSI | GPIO 33 |
| CS | GPIO 25 |
| DC / RS | GPIO 26 |
| RESET / RST | GPIO 27 |
| LED / BL | ESP32 3V3 (backlight always on) |

## DS3231 RTC wiring

| RTC pin | ESP32 pin |
| --- | --- |
| VCC | Dedicated 3V3 |
| GND | Common GND |
| SDA | GPIO 21 |
| SCL | GPIO 22 |
| SQW, 32K | Not connected |

GPIO 21/22 are now dedicated to the RTC. Duplicate expansion-board sockets
are electrically the same GPIO, not independent pins. For a ZS-042 using a
CR2032, disable its battery charging circuit.

The RTC stores UTC and restores the system clock on startup. Screen and scan
timestamps use Philippine time. On first use, connect to the internet and wait
for `DS3231 updated from internet time. Offline clock is ready.` in Serial
Monitor at 115200 baud. Then test restarting without WiFi and scanning offline.

## RC522 RFID wiring

| RC522 pin | ESP32 pin |
| --- | --- |
| `3.3V` | `3V3` |
| `GND` | `GND` |
| `SDA / SS` | GPIO 5 |
| `SCK` | GPIO 18 |
| `MOSI` | GPIO 23 |
| `MISO` | GPIO 19 |
| `RST` | GPIO 4 |
| `IRQ` | Not connected |

Never connect the RC522 to 5V. The RC522 and TFT now use separate SPI buses,
so none of their SPI signal wires need to be joined together.

## AS608 fingerprint wiring

| AS608 wire/pin | ESP32 connection |
| --- | --- |
| `VCC` | External regulated 5V |
| `GND` | Common GND |
| Sensor `TX` | GPIO 13 (ESP32 RX) |
| Sensor `RX` | GPIO 14 (ESP32 TX) |

TX and RX are crossed: sensor TX goes to the ESP32 receive pin.

## Indicators and buzzer

Use a 220-ohm to 330-ohm series resistor for every bare LED.

| Component | ESP32 connection |
| --- | --- |
| Green LED anode | GPIO 16 through resistor |
| Red LED anode | GPIO 17 through resistor |
| Yellow LED anode | GPIO 2 through resistor |
| All LED cathodes | Common GND |
| Passive buzzer positive / signal | GPIO 15 |
| Passive buzzer negative | Common GND |

For a three-pin buzzer module, connect `S` to GPIO 15, `-` to GND, and `+`
to the voltage printed on that module.

## Unused TFT connections

Touch is intentionally disabled in this first version. Leave `T_CS` and
`T_IRQ` disconnected. Also leave `T_CLK`, `T_DIN`, and `T_DO` disconnected.
The MicroSD pins `SD_SCK`, `SD_MISO`, `SD_MOSI`, and `SD_CS` are also unused.

Do not connect the `LED` pin directly to 5V. The module documentation calls
for a 3.3V high level on this backlight-control pin. ESP32 control signals are
also 3.3V logic even though the module's `VCC` is connected to 5V.

If the expansion board VCC selector is set to 5V, use that VCC row only for
the TFT `VCC` and AS608 `VCC`. Power the RC522 from the dedicated ESP32 `3V3`
pin, and connect the TFT `LED` to that same dedicated `3V3` supply.

Before uploading, confirm that the board is the 3.5-inch SPI `ILI9488`
variant. Do not use this pinout for a parallel 8-bit display.

## White-screen test

If the backlight is on but the screen stays white, upload
`hardware/TFTTest/TFTTest.ino` first. Disconnect the RC522, AS608, LEDs, and
buzzer during this test. The test uses the seller's ILI9488 18-bit
initialization sequence and software SPI. A correct connection repeatedly
shows red, green, blue, black, and yellow. If it remains white, recheck `CS`,
`RESET`, `DC/RS`, `SDI/MOSI`, `SCK`, and the J1 state; the problem is before
the payroll firmware starts.

The ESP32 uses 3.3V GPIO logic, so leave the display's `J1` solder jumper in
its factory-default open state. Do not bridge J1. The seller guide instructs
bridging J1 only for 5V-logic boards such as the Arduino Uno or Mega.

## Complete GPIO map

| ESP32 GPIO | Assigned device |
| --- | --- |
| GPIO 2 | Yellow LED |
| GPIO 15 | Buzzer |
| GPIO 4 | RC522 RST |
| GPIO 5 | RC522 SS/SDA |
| GPIO 13 | AS608 TX to ESP32 RX |
| GPIO 14 | ESP32 TX to AS608 RX |
| GPIO 16 | Green LED |
| GPIO 17 | Red LED |
| GPIO 18 | RC522 SCK |
| GPIO 19 | RC522 MISO |
| GPIO 21 | DS3231 SDA |
| GPIO 22 | DS3231 SCL |
| GPIO 23 | RC522 MOSI |
| GPIO 25 | TFT CS |
| GPIO 26 | TFT DC/RS |
| GPIO 27 | TFT RESET |
| GPIO 32 | TFT SCK |
| GPIO 33 | TFT MOSI |

## Display and fingerprint checks

New scans go directly to the server whenever WiFi is connected, even if older
scans remain queued. Offline scans and temporary upload failures are retained
for retry and displayed as `Saved for upload`, not confirmed server attendance.
Existing queued scans still retry independently. This can deliver scans out of
timestamp order; review same-day time-in/out when older scans arrive late, since
the current backend processes taps in arrival order.

Server requests use a fresh mutex-protected TLS client per attempt, with
15-second connection, handshake, and response timeouts. Each request closes its
socket before another request can begin. Enrollment polling pauses while scans
remain queued. Foreground requests retry a failed connection (`-1`) once with
a fresh TLS client before falling back to storage; requests with a lost response
after sending are not immediately repeated. The display buffer uses 8-bit color
instead of 16-bit, saving about 39 KB of heap for HTTPS without changing layout.
Enrollment polling resumes when the queue is empty. Three consecutive DNS/HTTPS
failures schedule an asynchronous WiFi reconnect, at most once per minute.
WiFi power saving is disabled. Queue timestamps and event IDs do not change on
retry; records are acknowledged only after a successful server response.
An invalid API key or rejected record still needs correction on the server;
automatic reconnect cannot override those errors or fix a brownout power supply.

For connection failures, Serial Monitor reports DNS resolution, WiFi RSSI,
free heap, largest allocatable heap block, and the TLS error. No credentials
are printed. A successful firmware build does not verify the device's network
or power supply; retained scans upload only after the server accepts them.

When queued scans cannot upload, the status line above the footer shows the last
sync error (connection, API key, HTTP status, or invalid record time). Failed
requests retry after 30 seconds for temporary errors or 60 seconds for rejected
records. Records remain queued; fix the reported cause rather than formatting
storage. A future-dated queued record retains its original timestamp even after
the live clock is corrected. Do not change historical scan times automatically.

The display uses a reusable 480x82 pixel buffer instead of clearing the screen
every second. The header stays fixed, the date updates only when it changes,
and the footer shows WiFi connectivity and queued scans. Sensor failures appear
on the status line above the footer. The CvSU logo is embedded in the sketch.
Offline synchronization does not replace the clock with a full-screen message.

AS608 UART is explicitly initialized on GPIO13/14 at 57600 baud, separate from
the TFT GPIO32/33. Sensor parameters must be readable before matching is enabled.
The firmware does not erase existing fingerprint templates.

When replacing the AS608, deploy the server API and this firmware together.
During enrollment, the device reads fingerprint slots already assigned in the
server. An existing faculty member can reuse their own slot if it is empty on
the new sensor; new registrations skip slots assigned to other faculty. Re-enroll
each faculty member on the replacement sensor. Old server records alone do not
restore fingerprint templates to the new sensor.

After uploading, check these on the actual hardware:

1. Leave the clock visible for a minute. It should update without blank flashes.
2. Confirm `AS608 fingerprint sensor detected.` and the template count in Serial
   Monitor at 115200 baud. Check that the logo colors match the green header.
3. Scan a registered finger, lift it, then try an unregistered finger. Unclear
   images and communication failures must not be labeled as unregistered.
4. Disconnect WiFi, scan, then restore WiFi and confirm the queue decreases.
5. Start fingerprint and RFID registrations, then cancel each from the admin form
   while the device waits for a finger or card. The screen should show
   `Registration cancelled` and return to the clock.
6. If the screen says `FINGERPRINT SENSOR UNAVAILABLE`, check sensor TX to D13, sensor RX
   to D14, common ground, and regulated sensor power. Send the Serial Monitor
   output if detection or matching still fails; compiling cannot verify wiring.

# DCS Attendance TFT - local LAN testing

Open `DCSAttendanceTFTLocal.ino` in this complete folder. Keep the folder name
`DCSAttendanceTFTLocal` so it matches the sketch filename. The ILI9488 TFT,
RFID, fingerprint, LEDs, buzzer, RTC wiring, per-class attendance and two-minute
OUT wait are the same as the Railway sketch.

## Configure and start

1. Keep the computer and ESP32 on the same WiFi/LAN. Use the computer IPv4
   shown by `ipconfig`, not localhost or 127.0.0.1. `local_server.h` initially
   targets `192.168.1.13:8000`; update it if the computer IP changes.
2. For a new copy, copy `device_config.example.h` to `device_config.h` and set
   the WiFi details and the hardware key from the LOCAL Laravel `.env`.
   An existing private configuration is not included in downloadable ZIPs.
3. In the Laravel project root, run:

   ```powershell
   php artisan serve --host=0.0.0.0 --port=8000
   ```

   PHP needs the application's usual extensions, including mbstring, openssl
   and pdo_mysql when the local database is MySQL. Keep the local database
   server running. If assets need rebuilding, run `npm run build` first.
4. Open `http://192.168.1.13:8000` from another device on that LAN to check
   reachability. Allow PHP through Windows Firewall on the Private network
   if prompted. Guest WiFi/client isolation can block device-to-computer access.
5. For browser login over local HTTP, use LOCAL `.env` settings:

   ```dotenv
   APP_ENV=local
   APP_URL=http://192.168.1.13:8000
   SESSION_SECURE_COOKIE=false
   ```

   Then run `php artisan config:clear` and restart the local server. Do not
   copy these HTTP settings into Railway. Leave the local APP_KEY unchanged.
6. Compile/upload with the SAME board, partition scheme and flash erase
   disabled. Keep `local_server.h`, `cvsu_logo.h`, `dcs_logo.h` and the private
   `device_config.h` next to the `.ino`. Serial Monitor at 115200 baud prints
   the local API target; the TFT header always begins with `LOCAL /`.
7. Test a registered faculty's eligible class: IN, WAIT before two minutes,
   then OUT. Check Class Attendance in the local website. School Attendance
   is a separate flow. Test NO CLASS and offline/reconnection as well.

## Switching between local testing and Railway

Upload `DCSAttendanceTFTLocal` for local tests. Upload `DCSAttendanceTFT` again
for Railway. The online sketch keeps its original HTTPS certificate checks.

The local firmware uses `/attendance-local.bin`, NVS namespace `attendance-lcl`,
local RFID cache files and LCL event IDs. Railway keeps `/attendance.bin`, its
`attendance` namespace, RFID cache and ESP event IDs. Uploading the other sketch
with flash erase disabled preserves both queues; each sketch resumes only its
own pending scans. Never erase flash, upload a filesystem image, or change the
partition layout while either queue has pending records. Local mode never
formats LittleFS. A failed mount disables offline storage instead of erasing it.
Before changing the local target to another computer/database, finish the local
queue first. Local test records do not automatically appear on Railway.

Both variants use the SAME physical fingerprint sensor. Enrollment/unlinking
in local mode changes templates on that sensor too; use existing enrolled IDs
for attendance-only tests when preserving the live device's registrations.
A previously synchronized, battery-backed RTC lets scans retain valid time
without internet. An unset RTC still needs an initial time synchronization.

HTTP is deliberately for private LAN testing only; public/loopback destinations
and HTTP redirects are rejected by this variant.

## OUT waiting indication

A tap before the two-minute minimum returns WAIT and does not save an OUT.
The TFT shows `WAIT / OUT NOT RECORDED` with an amber countdown and a yellow
LED pulse. The wait screen remains until the countdown ends or another scan
replaces it. Scan again after the wait; expiry never creates an OUT by itself.
WAIT never uses the green success signal. A recorded IN/OUT keeps its normal
green indication; queued offline scans retain their separate saved-on-device
message until uploaded.

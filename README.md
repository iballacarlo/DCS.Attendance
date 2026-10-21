# CvSU - Imus Payroll System

Payroll and attendance management system for Cavite State University - Imus Campus, Department of Computer Studies.

## Main features

- Faculty account onboarding and profile management
- RFID and fingerprint registration
- Schedule-based attendance and online DTR
- Payroll period generation and A4 payslips
- Email verification and password recovery
- Administrative backup and restore
- Responsive light and dark modes

## Per-class attendance

Each class has its own time-in/time-out pair, subject, and section. The administrator creates separate blocks even for adjacent classes. Faculty can view their own read-only plotting under **My Schedule**.

Hourly pay is the employee's hourly rate multiplied by completed class attendance within the selected cutoff. Count only the overlap between each tap pair and its class schedule; consultation, breaks, gaps, and time outside the class are unpaid. There is no additional late/undertime penalty and no automatic holiday, activity, or leave credit. An incomplete pair remains unpaid until corrected by payroll staff.

Time-in opens 30 minutes before class. Time-out keeps the existing three-minute cooldown. At an exact class-end boundary, the tap closes the open class; a separate tap starts the adjacent class. If a previous class has already ended and the next class is eligible, its first tap starts the new class and leaves the previous session incomplete for staff review.

Attendance filters default to the current Asia/Manila cutoff (days 1–15 or 16–month end). Faculty filters are restricted to their own records. Dates display as **MM/DD/YYYY**; API/database dates remain ISO. Month-only employment selectors continue to select a month and year.

### Upgrade existing attendance

Back up the database before applying the schema. For an existing installation with unrelated pending migrations, apply this feature's schema directly:

```sh
php artisan migrate --path=database/migrations/2026_10_21_000001_add_class_attendance_sessions.php --force
php artisan attendance:convert-classes --preview
php artisan attendance:convert-classes
```

The conversion prints a JSON report. Save it in private storage for review. It keeps the original daily logs, labels reconstructed class sessions, preserves manual earnings/deductions, recalculates draft payroll, and reports differences for approved/released or finalized payroll without changing those protected records. Repeating conversion does not duplicate previously reconstructed sources. Missing or conflicting schedules are reported for manual review; incomplete pairs receive no paid hours.

The legacy database has no schedule version history. Conversion therefore uses the blocks currently assigned, and cannot prove which historical subject/section or class arrangement applied. Review the preview before changing old plotting. Legacy class blocks containing breaks must first be split into class-only blocks. New attendance stores class snapshots so subsequent schedule edits/deletions do not change recorded hours or class details.

The DTR includes daily totals and a paginated class-session PDF appendix. Use the session breakdown for individual class pairs; the daily form displays daily arrival/departure summaries.

## Faculty archive

Administrators can use **Faculty > Archive** for an individual faculty member or select rows and choose **Archive Selected**. **Faculty Management > Archived Faculty** provides a separate searchable list with Restore actions. Nothing is archived automatically.

Archived faculty and their related records are hidden from normal pages, searches, totals, reports, and direct record URLs. Their accounts cannot log in or record attendance; existing sessions are revoked and pending hardware enrollment is cancelled. Schedules, credentials, accounts, attendance, payroll amounts/statuses, and the previous active/inactive status are preserved. Restore makes those records visible again with their original identifiers and previous status. Archive/restore actions are audited; administrators cannot archive their own linked faculty profile.

Database backups intentionally retain archived records so they remain recoverable. To deploy the archive schema without running unrelated pending migrations:

```sh
php artisan migrate --path=database/migrations/2026_10_22_000002_add_faculty_archives.php --force
```

## Attendance hardware

The firmware in `hardware/PayrollHardware/PayrollHardware.ino` supports:

- ESP32 DevKit V1
- MFRC522 RFID reader
- AS608 fingerprint sensor
- 16x2 I2C LCD
- Buzzer and status LEDs

Before uploading the sketch, copy `device_config.example.h` as `device_config.h`, then configure `WIFI_NAME`, `WIFI_PASSWORD`, and `HARDWARE_API_KEY`. The firmware key must exactly match the Railway `HARDWARE_API_KEY` variable. The real configuration file is ignored by Git.

The configured API base is `https://payroll-system.up.railway.app/api/hardware`. Update it if the Railway public domain changes.

### Enrollment flow

1. Save the faculty profile.
2. Open **Faculty -> Profile & IDs**.
3. Select **Register** or **Re-register** for RFID or fingerprint.
4. Follow the prompts shown on the attendance device.
5. The ESP32 sends the captured RFID UID or fingerprint template ID to the system.

Enrollment requests expire after five minutes. Queued attendance does not block enrollment polling. The TFT reports the enrollment HTTP code and server error when registration cannot be confirmed.

The one-time `2026_10_12_000001_clear_fingerprint_registrations_for_sensor_replacement` migration removes all server fingerprint credentials and cancels active fingerprint enrollments, as requested for sensor replacement. Run `php artisan migrate --force` on the deployed server. RFID credentials, employee records, attendance history, and payroll are preserved. This does not erase templates stored inside a physical sensor; faculty must enroll again after the server reset.

### ESP32 pin map

| Device | ESP32 pin |
| --- | --- |
| RC522 SS/SDA | GPIO 5 |
| RC522 RST | GPIO 4 |
| RC522 SCK/MISO/MOSI | GPIO 18/19/23 |
| AS608 RX/TX | GPIO 13/14 |
| I2C SDA/SCL | GPIO 21/22 |
| Green/Red/Yellow LED | GPIO 25/27/32 |
| Buzzer | GPIO 26 |

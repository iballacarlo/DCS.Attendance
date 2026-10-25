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

Both Manage Schedule and My Schedule open with a Monday-Saturday **Weekly Plotting** matrix. Blocks show subject, section, lecture/laboratory, consultation, and breaks, followed by weekly subject/section hour totals. Administrators use **Manage by Day** to edit; faculty have a read-only **Daily View**. Plotting contains schedule information only, without institutional headings or faculty profile details. Total no. of contract hours per week includes class and consultation hours. The summary omits the unspecified-class-hours column while retaining those hours in the class total. Room, student-count, credit-unit, and signature data are not invented when they are absent from the database.

Hourly pay is the employee's hourly rate multiplied by completed class and consultation attendance within the selected cutoff. Count only the overlap between each tap pair and its assigned class or consultation schedule. Breaks, gaps, and time outside payable schedules are unpaid. There is no additional late/undertime penalty and no automatic holiday, activity, or leave credit. An incomplete pair remains unpaid.

Consultation requires its own time-in/time-out pair, including when it is adjacent to a class. Consultation sessions retain their type in attendance snapshots and are included in DTR and payroll totals. Existing class records retain their original type. Deploy the consultation snapshot field with the targeted migration below; it does not create attendance or credit historical hours automatically:

```sh
php artisan migrate --path=database/migrations/2026_10_25_000001_add_attendance_schedule_type.php --force
```

Manual attendance entry and editing have been removed from the UI and routes, including the browser's identifier-based Record Tap form. Actual attendance taps are accepted through the hardware API. Existing records remain intact. The test log generator is available only to payroll staff, not administrators or faculty. It creates complete RFID IN/OUT pairs for class and consultation schedules. At most one in five newly generated days receives a late/early-out example; the rest cover their full schedules, with a mixture of exact, early-arrival, and late-departure taps. Adjacent sessions keep separate pairs at the scheduled boundary. Generation respects retained neighboring taps, never generates future time-outs, and fill-missing mode preserves existing records. It does not retroactively rewrite previously generated logs unless overwrite mode is explicitly selected.

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

### Form 48 and editable Excel

The A4 Form 48 preview, PDF/print, and single-sheet Excel export follow the supplied form: Day, AM/PM arrival/departure, and UNDER TIME hours/minutes. All 31 day rows are printed, including blank rows for nonexistent dates and days outside the selected cutoff. Select the month, year, and cutoff using the compact controls; new calendar periods do not require manual setup. The form's month/cutoff heading follows the reference wording (for example, October 1-15, 2026).

Form 48 displays only the earliest actual IN and latest actual OUT each day, placed in their AM/PM columns using 24-hour H:mm. The schedule range sits outside the table on the left. For days with taps, the reporting shortfall is total scheduled class/consultation seconds minus completed payable seconds, floored at zero, then rounded once to whole minutes. A positive shortfall appears in the UNDER TIME columns; the Hours cell stays blank below 60 minutes. Otherwise completed worked hours appear outside the table on the right with two decimal places. Days without taps have blank shortfall and worked-hour fields. This is reporting only and does not introduce any payroll deduction.

**Download Excel** creates native editable time, number, and text cells, with A4 one-page print settings and no added worksheets. Corrections are manual: editing first/last taps does not infer per-class payable hours, and edited Excel files do not update system attendance or payroll. Correct the exported shortfall and worked-hour cells as needed for the document.

The DTR controls offer **Print DTR** and **Download Excel**; there are no PDF download actions. Printing uses an internally generated PDF with the same vector cell layout as the preview and Excel layout specification. The class-session breakdown remains available on screen with its own **Print Breakdown** action and is not automatically appended to Form 48.

Verification: run the PHP test suite and `node --test tests/js/form48.test.mjs`, then `npm run build`.

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

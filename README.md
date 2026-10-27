# DCS Attendance & DTR

**RFID-BASED CLASS ATTENDANCE MONITORING WITH DAILY TIME RECORD GENERATION SYSTEM FOR CONTRACT OF SERVICE FACULTY MEMBERS OF THE DEPARTMENT OF COMPUTER STUDIES**

Laravel, Inertia, and React application for Cavite State University - Imus Campus. Two independent attendance flows are enforced:

| Flow | Capture | Output |
| --- | --- | --- |
| School attendance | Separate guard entrance kiosk, one school IN and one school OUT per faculty/day | Civil Service Form 48 / DTR |
| Class attendance | Existing RFID/fingerprint hardware, class sessions only | Admin reports and analytics |

Hardware class taps never create or change school attendance or DTR hours. Consultation contributes to the DTR schedule basis but does not accept hardware attendance taps.

## School attendance and DTR

The entrance simulator is a standalone guard kiosk at `/entrance-kiosk`, outside the Admin dashboard. An administrator creates a **Guard** account through **Settings → Accounts → Add Account**, without linking a faculty profile. Sign in with that account on the entrance computer; login always opens the kiosk. Guards cannot access admin pages, reports, DTR, faculty profiles, or settings. Sign out ends the kiosk session. Admin and Faculty accounts cannot record at the guard kiosk.

On a compatible NFC-enabled Android phone, open the kiosk in Chrome using a trusted HTTPS address reachable from the phone, then select **Scan RFID** and allow NFC access. The scanner reads one NDEF-compatible card's serial number, normalizes it to the existing hardware's uppercase hexadecimal UID format, and previews the registered faculty. It ignores NDEF links/text (including Instagram URLs); nothing on the card is changed. The guard confirms with **Record School IN/OUT**. **Stop scanning** cancels a pending read. A card without an exposed serial number cannot be used through this browser scanner. Manual UID entry remains available for simulation. A computer's `127.0.0.1` address does not point to that computer from a phone; deploy/configure a reachable HTTPS address before phone testing. Test actual card/phone compatibility before relying on this flow. Records remain marked simulated under the current entrance testing setup.

Before testing, the administrator registers the faculty RFID through **Faculty → Profile & IDs** and provides the UID for the demo. At the compact kiosk, enter/scan the UID. The server previews the next action for that faculty/date: IN if no record exists, OUT if IN exists, and complete if both exist. Only the next action is highlighted; direction cannot be selected manually. Submit the record. Optional test date/time remains visible; leaving it blank uses the current Asia/Manila time. Record IN first, then a later OUT on the same date. All simulator records are identified as simulated and store the recording guard account. Future test times are allowed for demonstration. Admin reviews the results in **School Attendance** (`/entrance`) and generates DTR; Faculty see only their own school attendance and DTR.

Only one IN/OUT pair is accepted per day. OUT requires an earlier same-day IN. The server determines direction inside the recording transaction and checks the previewed action as a precondition, so a stale/retried IN submission cannot become an accidental OUT. Duplicate IN, repeated OUT, and third taps are rejected without overwriting records. Employee row locks and a database unique key serialize concurrent taps. Unknown, lost/inactive, inactive-faculty, and archived-faculty cards are rejected.

DTR hours are the intersection of school IN/OUT with the day's class and consultation schedules. Overlapping intervals are merged; schedule gaps and explicit lunch breaks are excluded. IN captures the schedule snapshot, which remains authoritative after schedule edits or deletion. An incomplete pair has no computed hours or undertime. An unscheduled school visit keeps its actual times without crediting scheduled hours.

**Daily Time Record** keeps the existing 1-15 and 16-month-end cutoffs, 31-row Form 48, historical signatory selection, print/PDF, and editable Excel export. Each real school tap appears once in its corresponding AM/PM column. Faculty can view only their own records. Exported workbook edits do not update application data.

## Class reports and analytics

Regular Philippine holidays for 2026 and 2027 are automatic, offline presets in `RegularHolidayService`. They appear in School Calendar and exclude affected class sessions even when the calendar page has never been opened. They do not create database rows, duplicate manual nationwide regular-holiday entries in the calendar display, or credit DTR hours. Sources retained in the service: Proclamation 1006 plus the confirmed 2026 Eid proclamations, and Proclamation 1427 for 2027. The two 2027 Eid dates remain pending until officially declared; add confirmed dates manually or update the versioned preset. Unverified years require manual entries instead of copying or guessing dates. Local holidays, suspensions, and school activities remain manual.

Administrators can open **Class Reports & Analytics** and filter by date range (up to 366 days), faculty, subject, section, and lecture/laboratory mode. Filters update the graphs automatically; visible pages refresh their data every 15 seconds. Select a time bar or faculty/subject name to narrow the report; Reset view restores the initial filters. Select a donut category to inspect its finished-class records in Session Details. The details table defaults to 10 rows with 25/50-row options and Previous/Next/page controls. Pagination and the table's status selector do not alter chart totals or exports. Print, PDF, and Excel include all sessions in the applied report filters, not just the visible table page. Excel includes the summary, session details, daily trend, faculty breakdown, and subject breakdown.

Expected sessions come from current weekly class schedules, supplemented by saved session snapshots. Saved snapshots preserve the subject, mode, and scheduled times of recorded classes after edits. There are no historical schedule versions for days without recorded class sessions; expectations for those days use the current schedule. Inactive faculty do not acquire new expected sessions, but their saved sessions remain reportable. Archived faculty are hidden.

Calendar holidays, suspensions, official school activities, asynchronous dates, and approved leave exclude affected sessions from the denominator. Coverage respects campus, department, and selected-faculty scopes. Excluded recorded taps remain visible with an exclusion reason. Only ended sessions can be missed or enter the completion denominator. A started session without a tap remains in progress until its scheduled end; future sessions are upcoming. IN without OUT is incomplete. Lateness and early departure are measured against scheduled times with no grace period.

Completion rate = complete eligible ended sessions / all eligible ended sessions. With no eligible ended sessions, the rate is blank. No salary calculation uses these reports.

## Accounts and retained history

Admin, Faculty, and Guard are active roles. Guard is restricted to the separate entrance kiosk. Legacy Payroll Staff accounts are retained, rejected at login, and signed out on their next request. An administrator can assign them an active role through Accounts. Calendar, leave review, DTR signatories, and attendance administration belong to Admin.

Payroll pages, routes, payslips, generators, salary editing, and monetary dashboard cards are removed. Historical financial database tables remain private for recovery, and salary fields are excluded from normal model serialization. Profile/rank updates do not modify retained salary values. New profiles initialize required legacy rate columns to zero.

Historical migrations and migration-only salary computation dependencies remain available for clean installations. `LegacyPayrollDtrService` freezes their former data source; the live `DtrService` reads only `entrance_attendances`. The optional `attendance:convert-classes` maintenance command reconstructs class history only and never changes payroll or school entrance records. It does not run automatically. Old consultation attendance remains stored and is excluded from class reports.

Full administrative backups retain historical financial tables for disaster recovery. Custom attendance backups include both independent attendance stores. Both legacy `CvSU Payroll System` and new `DCS Attendance & DTR` JSON formats are accepted; old backups lacking entrance records never synthesize school taps. Restored legacy staff accounts remain disabled by the same access middleware. Custom recovery adds missing records and keeps existing records; full recovery replaces stored system data.

## Installation and upgrade

For a new installation, configure `.env`, install Composer/npm dependencies, run `php artisan key:generate`, `php artisan migrate`, and `npm run build`. Run `php artisan db:seed` only when demonstration faculty/accounts are wanted; no obsolete staff account is seeded.

For an existing installation that already has the attendance, calendar, leave, schedule-type, and archive migrations, apply the new additive migration:

```sh
php artisan migrate --path=database/migrations/2026_10_27_000001_create_entrance_attendances_table.php --force
npm run build
```

Review other pending historical migrations separately. In particular, the old sensor-replacement migration clears fingerprint registrations; it is unrelated to this upgrade. No existing class logs are converted into entrance attendance, so historical DTR actual times remain empty until school attendance is recorded.

## Verification

```sh
php artisan test
node --test tests/js/*.test.mjs
npm run build
```

PHP requires mbstring, OpenSSL, fileinfo, and the appropriate PDO driver. Tests use an isolated in-memory SQLite database configured in `phpunit.xml`. `AttendanceSeparationTest` covers school tap validation, DTR snapshots and isolation, class analytics, access control, and legacy backup compatibility. JavaScript tests round-trip Excel exports and verify PDF pagination.

## Existing attendance hardware

The existing ESP32/RC522/AS608 firmware and enrollment protocol remain compatible. No new entrance hardware is needed. `/api/hardware/tap` remains protected by `X-Hardware-Key`, retains device event retry/idempotency behavior, and records classes only. Its event IDs and responses are separate from the entrance simulator's authenticated web endpoint.

Save a faculty profile, open **Profile & IDs**, and register or re-register the RFID/fingerprint through the existing device. Enrollment requests expire after five minutes. Keep the firmware's private `device_config.h` ignored by Git, with its `HARDWARE_API_KEY` matching the server. Existing firmware directory names are retained to avoid breaking device builds.

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

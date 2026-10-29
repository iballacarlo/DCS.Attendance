# DCS Attendance & DTR

See [Security rollout](docs/security-rollout.md) for account protections, production configuration checks, and coordinated hardware credential/certificate deployment.

**RFID-BASED CLASS ATTENDANCE MONITORING WITH DAILY TIME RECORD GENERATION SYSTEM FOR CONTRACT OF SERVICE FACULTY MEMBERS OF THE DEPARTMENT OF COMPUTER STUDIES**

Laravel, Inertia, and React application for Cavite State University - Imus Campus. Two independent attendance flows are enforced:

The maintained TFT firmware is in [`hardware/DCSAttendanceTFT`](hardware/DCSAttendanceTFT/README.md).
Open `DCSAttendanceTFT.ino` with all companion headers in the same folder.
The other retained sketches are `hardware/DCSAttendanceLCD` and
`hardware/DCSAttendanceTFTBeforeCredentialFlow`; each main `.ino` filename
matches its folder. The WiFi test and logo preview use the renamed paths.
For LAN-only testing, use the separate [`DCSAttendanceTFTLocal`](hardware/DCSAttendanceTFTLocal/README.md)
sketch. Its HTTP connection, queue and RFID cache are isolated from Railway.


| Flow | Capture | Output |
| --- | --- | --- |
| School attendance | Separate guard entrance kiosk, one school IN and one school OUT per faculty/day | Civil Service Form 48 / DTR |
| Class attendance | Existing RFID/fingerprint hardware, class sessions only | Admin reports and analytics |

Hardware class taps never create or change school attendance or DTR hours. Consultation contributes to the DTR schedule basis but does not accept hardware attendance taps.

## School attendance and DTR

The entrance simulator is a standalone guard kiosk at `/entrance-kiosk`, outside the Admin dashboard. An administrator creates a **Guard** account through **Settings → Accounts → Add Account**, without linking a faculty profile. Sign in with that account on the entrance computer; login opens the attendance kiosk directly, including on mobile. Guard accounts have no Dashboard page. Admin and Faculty also always land on Dashboard after login, including on mobile; saved intended URLs are cleared. Guards cannot access admin pages, reports, DTR, faculty profiles, or settings. Sign out ends the kiosk session. Admin and Faculty accounts cannot record at the guard kiosk.

On a compatible NFC-enabled Android phone, open the kiosk in Chrome using a trusted HTTPS address reachable from the phone, then select **Scan RFID** and allow NFC access. The scanner reads one NDEF-compatible card's serial number, normalizes it to the existing hardware's uppercase hexadecimal UID format, and previews the registered faculty. It ignores NDEF links/text (including Instagram URLs); nothing on the card is changed. The guard confirms with **Record School IN/OUT**. **Stop scanning** cancels a pending read. A card without an exposed serial number cannot be used through this browser scanner. Manual UID entry remains available for simulation. A computer's `127.0.0.1` address does not point to that computer from a phone; deploy/configure a reachable HTTPS address before phone testing. Test actual card/phone compatibility before relying on this flow. Records remain marked simulated under the current entrance testing setup.

Before testing, the administrator registers the faculty RFID through **Faculty → Profile & IDs** and provides the UID for the demo. At the compact kiosk, enter/scan the UID. The server previews the next action for that faculty/date: IN if no record exists, OUT if IN exists, and complete if both exist. Only the next action is highlighted; direction cannot be selected manually. Submit the record. Optional test date/time remains visible; leaving it blank uses the current Asia/Manila time. Record IN first, then a later OUT on the same date. All simulator records are identified as simulated and store the recording guard account. Future dates are rejected by both preview and recording using the Asia/Manila calendar date. Later times on the current day are allowed for demonstration; past dates remain available. The date/time picker is capped at 23:59 of the current Manila day. Admin reviews the results in **School Attendance** (`/entrance`) and generates DTR; Faculty see only their own school attendance and DTR.

School Attendance uses the same **Month / Year / Cutoff** picker as Class Attendance and DTR. Admin can combine faculty and complete/incomplete status filters. The Date column identifies each daily record; pagination shows 15 records per page and retains all filters. Faculty are always restricted to their own records. Old `?date=` links select the containing half-month cutoff. View DTR carries the selected cutoff and faculty.

Only one IN/OUT pair is accepted per day. OUT requires an earlier same-day IN. The server determines direction inside the recording transaction and checks the previewed action as a precondition, so a stale/retried IN submission cannot become an accidental OUT. Duplicate IN, repeated OUT, and third taps are rejected without overwriting records. Employee row locks and a database unique key serialize concurrent taps. Unknown, lost/inactive, inactive-faculty, and archived-faculty cards are rejected.

DTR hours are the intersection of school IN/OUT with the day's class and consultation schedules. Overlapping intervals are merged; schedule gaps and explicit lunch breaks are excluded. IN captures the schedule snapshot, which remains authoritative after schedule edits or deletion. An incomplete pair has no computed hours or undertime. An unscheduled school visit remains in School Attendance history, but its IN/OUT, source, attendance counts, and hours stay blank in the DTR. An empty saved schedule snapshot remains authoritative even if a schedule is added later. Consultation-only scheduled days remain eligible for DTR hours.

**Daily Time Record** keeps the existing 1-15 and 16-month-end cutoffs, 31-row Form 48, historical signatory selection, Print DTR, and editable Excel export. The DTR has no separate Download PDF action. Each real school tap appears once in its corresponding AM/PM column. Faculty can view only their own records. Exported workbook edits do not update application data.

## Faculty Today's Schedule

The faculty Dashboard shows a chronological timeline before School Attendance. Each class has actual IN, OUT and Finished steps, a current-session highlight, and a Finished/required-classes count. States are Upcoming, Awaiting IN, Checked in (including early IN), Finished, For review, Absent and Not required. Finished requires OUT after IN, not just an elapsed scheduled end; valid pairs may still carry Late and Undertime badges. Partial/invalid pairs become For review when the class ends. Report exclusions and saved class snapshots are reused. Consultation and lunch break cards have no attendance steps and are excluded from the completion count.

Only the signed-in faculty's linked records are returned; an unlinked account gets an empty timeline. School IN/OUT never marks a class attended. The Dashboard refreshes schedule, Manila date/as-of timestamp and school attendance every 15 seconds while visible, and when returning online or to the tab. The timeline only displays records; it never creates taps or changes DTR computations.

## Class reports and analytics

Regular Philippine holidays for 2026 and 2027 are automatic, offline presets in `RegularHolidayService`. They appear in Holidays & Suspensions and exclude affected class sessions even when the calendar page has never been opened. They do not create database rows, duplicate manual nationwide regular-holiday entries in the calendar display, or credit DTR hours. Sources retained in the service: Proclamation 1006 plus the confirmed 2026 Eid proclamations, and Proclamation 1427 for 2027. The two 2027 Eid dates remain pending until officially declared; add confirmed dates manually or update the versioned preset. Unverified years require manual entries instead of copying or guessing dates. Local holidays, suspensions, and school activities remain manual.

**Holidays & Suspensions** replaces the monthly calendar grid with a searchable year list, type filters, and 10-entry pagination. Admin manages this page under **Settings ? Holidays & Suspensions** (`/settings/holidays-suspensions`). New suspensions and regular/special holidays cover all DCS faculty; there are no coverage, department, or selected-faculty inputs. Existing scoped entries retain their original coverage when edited, so historical attendance exclusions are not broadened. Faculty keep the read-only list at `/school-calendar`. Regular holiday presets remain automatic and read-only. Existing activity/asynchronous entries remain available for historical exclusions and edits, while new entries focus on holidays and suspensions. Existing `/school-calendar` links and legacy `month` parameters remain compatible.

Administrators can open **Class Reports & Analytics** with Month, Year, Cutoff, Faculty, Subject, Section and Class mode always visible in the main toolbar. Graph Faculty/Subject filters are also always visible; only Export report uses a menu. Four static summary cards show On time & full class, Late, Undertime and Absent as count/finished classes (for example, Late 1/12). Finished eligible classes include For review in the denominator; future, ongoing and excluded sessions do not count. Subject choices follow the faculty and period, and sections follow the subject. A separate For review link opens those records in the initially collapsed Session details. The details keep status filters and 10/25/50-row pagination. Print, PDF and Excel are grouped under Export report and follow page filters plus the explicit details status, never graph selections.

The Reports page uses one graph with Overall, By Faculty, By Subject and Daily Trend views. Exactly three graph types are offered: Bar for overall/faculty/subject comparisons, Line for daily trends, and Donut for a selected status share. Choosing a type automatically selects its appropriate view without leaving the panel; comparison grouping appears only for Bar. Donut uses Overall with a visible status selector defaulting to Late. Column was removed because it duplicated Bar comparisons. It shows the chosen status versus Other classes, using the same finished-class denominator including For review. Its center shows count/total; an empty result has a neutral 0/0 ring and no percentage. Clicking the colored segment or status legend opens the faculty breakdown locally; the neutral segment is informational. Chart type is part of Back history, retained on refresh, and reset to Overall/Bar by Reset graph. The same count/total and percentage scale apply to every type. Line points use actual calendar spacing without adding zeroes for missing dates; a single observation has one selectable point and no connecting path. Status-bar clicks open faculty comparisons inside the graph; faculty/subject bars open their daily trend. Daily points only highlight/show counts. View, status, faculty, subject, Back, Reset graph and five-row group pagination are local controls: they do not navigate, change the URL, scroll, change the summary or filter the table. Page filter changes reset the graph; 15-second background refresh preserves valid graph selections. The Dashboard keeps its existing layout. Group bar lengths use each group's percentage of finished classes, with count/total labels; Late and Undertime can overlap. Empty values show 0/0 without a fabricated percentage.

Expected sessions come from current weekly class schedules, supplemented by saved session snapshots. Saved snapshots preserve the subject, mode, and scheduled times of recorded classes after edits. There are no historical schedule versions for days without recorded class sessions; expectations for those days use the current schedule. Inactive faculty do not acquire new expected sessions, but their saved sessions remain reportable. Archived faculty are hidden.

Calendar holidays, suspensions, official school activities, asynchronous dates, and approved leave exclude affected sessions from the denominator. Coverage respects campus, department, and selected-faculty scopes. Excluded recorded taps remain visible with an exclusion reason. Only ended sessions can be missed or enter the completion denominator. A started session without a tap remains in progress until its scheduled end; future sessions are upcoming. IN without OUT is incomplete; missing OUT never invents undertime minutes. Late arrivals and undertime (early departure) are measured against saved scheduled class times with no grace period, rounding each positive difference up to minutes. One class may be both late and undertime. On time & full class requires a complete pair with no lateness or early departure. Finished-class counts and total minutes are available per faculty, subject, and date. The monitoring chart ranks faculty or subjects by late, undertime, absent/no taps, or incomplete counts and shows five entries per page. Excluded and unfinished sessions do not enter these punctuality metrics.

Completion rate = complete eligible ended sessions / all eligible ended sessions. With no eligible ended sessions, the rate is blank. No salary calculation uses these reports.

## RFID registration by phone

Admin can open **Faculty ? Profile & IDs ? RFID card ? Register via NFC** on a compatible NFC-enabled Android phone using Chrome and a secure connection. Save new faculty records first. Tap the card, review the faculty name and UID, and confirm registration. Registration is saved immediately without submitting unrelated profile edits. The card's Instagram URL or other NDEF content is not changed. Existing cards stay assigned until a replacement succeeds. Duplicate cards (including archived assignments), stale replacements, and active RFID hardware enrollments are rejected. Guard and Faculty accounts cannot register cards. Hardware enrollment remains available separately. NFC-read UIDs use the same uppercase hexadecimal format as the existing RFID hardware; cards without an exposed serial cannot use this browser flow.

## Accounts and retained history

Admin, Faculty, and Guard are active roles. Guard is restricted to its own Dashboard and the separate entrance kiosk. Legacy Payroll Staff accounts are retained, rejected at login, and signed out on their next request. An administrator can assign them an active role through Accounts. Calendar, leave review, DTR signatories, and attendance administration belong to Admin.

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

Class-attendance graphs use four main measures: On time & full class, Late, Undertime, and Absent. Unpaired class records become **For review** only after the session ends; they stay separate from these measures and do not add late/undertime counts or minutes. The internal `incomplete` key remains for compatibility, and no historical taps are deleted or invented. Reports, status filters, PDF and Excel display **For review** and **Absent** consistently. School attendance and DTR incomplete-pair handling are unchanged.

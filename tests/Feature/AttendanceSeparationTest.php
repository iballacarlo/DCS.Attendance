<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ClassAttendanceService;
use App\Services\ClassReportService;
use App\Services\DtrService;
use App\Services\EntranceAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number = 'ONE'): Employee
    {
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'rate_amount' => 100, 'status' => 'active']);
        $employee->rfidCards()->create(['rfid_uid' => 'RFID-'.$number, 'status' => 'active']);

        return $employee;
    }

    private function schedule(Employee $employee, string $start = '08:00', string $end = '10:00', string $type = 'class')
    {
        return $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => $type, 'start_time' => $start, 'end_time' => $end, 'subject' => 'DCIT 21', 'section' => 'BSIT 1A', 'class_mode' => 'lecture']);
    }

    private function tap(string $action, string $at = '2026-10-05T08:00', string $uid = 'RFID-ONE')
    {
        return $this->post('/entrance-kiosk', ['identifier' => $uid, 'expected_action' => $action, 'occurred_at' => $at]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function guard(): User
    {
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard);

        return $guard;
    }

    public function test_school_pair_is_unique_ordered_and_never_overwritten(): void
    {
        $this->faculty();
        $this->guard();
        $this->tap('OUT')->assertSessionHasErrors('action');
        $this->tap('IN')->assertSessionHasNoErrors();
        $this->tap('IN', '2026-10-05T08:05')->assertSessionHasErrors('action');
        $this->tap('OUT', '2026-10-05T07:59')->assertSessionHasErrors('action');
        $this->tap('OUT', '2026-10-06T17:00')->assertSessionHasErrors('action');
        $this->tap('OUT', '2026-10-05T17:00')->assertSessionHasNoErrors();
        $this->tap('OUT', '2026-10-05T18:00')->assertSessionHasErrors('action');
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->assertDatabaseHas('entrance_attendances', ['time_in' => '08:00:00', 'time_out' => '17:00:00', 'source' => 'simulator']);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_unknown_inactive_and_archived_cards_are_rejected(): void
    {
        $employee = $this->faculty();
        $this->guard();
        $this->tap('IN', uid: 'UNKNOWN')->assertSessionHasErrors('identifier');
        $employee->rfidCards()->update(['status' => 'inactive']);
        $this->tap('IN')->assertSessionHasErrors('identifier');
        $employee->rfidCards()->update(['status' => 'active']);
        $employee->update(['status' => 'inactive']);
        $this->tap('IN')->assertSessionHasErrors('identifier');
        $employee->forceFill(['status' => 'active', 'archived_at' => now()])->save();
        $this->tap('IN')->assertSessionHasErrors('identifier');
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_server_time_defaults_to_manila_and_records_actor(): void
    {
        $employee = $this->faculty();
        $guard = $this->guard();
        $this->travelTo(Carbon::parse('2026-10-04 17:00:00', 'UTC'));
        $this->post('/entrance-kiosk', ['identifier' => 'rfid-one', 'expected_action' => 'IN'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('entrance_attendances', ['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '01:00:00', 'recorded_by' => $guard->id]);
    }

    public function test_dtr_uses_only_school_taps_and_snapshot_intervals_excluding_lunch_and_gaps(): void
    {
        $employee = $this->faculty();
        $this->guard();
        $this->schedule($employee, '08:00', '12:30');
        $this->schedule($employee, '10:00', '12:00'); // Overlap must not be double counted.
        $this->schedule($employee, '13:00', '14:00', 'consultation');
        $this->schedule($employee, '15:00', '17:00');
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);
        $this->tap('IN', '2026-10-05T08:15')->assertSessionHasNoErrors();
        $incomplete = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $this->assertNull($incomplete['form_rows'][4]['worked_hours']);
        $this->assertNull($incomplete['form_rows'][4]['shortfall_minutes']);
        $this->tap('OUT', '2026-10-05T16:45')->assertSessionHasNoErrors();
        $employee->schedules()->delete();
        $employee->scheduleBreaks()->delete();
        $dtr = app(DtrService::class)->build($employee->fresh(), '2026-10-01', '2026-10-15');
        $row = $dtr['form_rows'][4];
        $this->assertEquals(6.5, $row['worked_hours']); // 7 scheduled hours less 30 minutes.
        $this->assertSame(30, $row['shortfall_minutes']);
        $this->assertSame('08:15', $row['am_arrival']);
        $this->assertSame('16:45', $row['pm_departure']);
        $this->assertNull($row['am_departure']);
        $this->assertNull($row['pm_arrival']);
        $this->assertCount(31, $dtr['form_rows']);
    }

    public function test_hardware_class_logs_never_create_dtr_hours_and_consultation_is_not_eligible(): void
    {
        $employee = $this->faculty();
        $this->schedule($employee);
        $this->schedule($employee, '13:00', '14:00', 'consultation');
        $service = app(ClassAttendanceService::class);
        $service->record($employee, 'rfid', Carbon::parse('2026-10-05 08:00', 'Asia/Manila'));
        $service->record($employee, 'rfid', Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));
        $result = $service->record($employee, 'rfid', Carbon::parse('2026-10-05 13:00', 'Asia/Manila'));
        $this->assertSame('NO CLASS', $result['action']);
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $this->assertNull($dtr['form_rows'][4]['am_arrival']);
        $this->assertEquals(0, $dtr['summary']['total_hours']);
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_unscheduled_school_taps_are_retained_but_blank_in_dtr_even_after_schedule_edits(): void
    {
        $employee = $this->faculty();
        $guard = $this->guard();
        $this->schedule($employee); // Monday only; October 28 is a Wednesday.
        $service = app(EntranceAttendanceService::class);
        $service->record('RFID-ONE', 'IN', Carbon::parse('2026-10-28 13:36:08', 'Asia/Manila'), $guard->id);
        $assertBlank = function () use ($employee) {
            $dtr = app(DtrService::class)->build($employee->fresh(), '2026-10-16', '2026-10-31');
            $row = $dtr['rows']->firstWhere('date', '2026-10-28');
            $this->assertFalse($row['has_schedule']);
            $this->assertSame('not_scheduled', $row['status']);
            foreach (['time_in', 'time_out', 'source', 'worked_hours', 'shortfall_minutes'] as $field) {
                $this->assertNull($row[$field], $field);
            }
            foreach (['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure', 'worked_seconds', 'margin_hours', 'undertime_hours', 'undertime_minutes'] as $field) {
                $this->assertNull($dtr['form_rows'][27][$field], $field);
            }
            $this->assertSame(0, $dtr['summary']['days_present']);
            $this->assertSame(0, $dtr['summary']['incomplete_days']);
            $this->assertEquals(0, $dtr['summary']['total_hours']);
        };
        $assertBlank(); // An unscheduled IN alone is not an incomplete DTR day.
        $service->record('RFID-ONE', 'OUT', Carbon::parse('2026-10-28 16:04', 'Asia/Manila'), $guard->id);
        $assertBlank();
        $employee->schedules()->create(['day_of_week' => 3, 'schedule_type' => 'consultation', 'start_time' => '13:00', 'end_time' => '17:00']);
        $assertBlank(); // A later schedule must not reinterpret the empty snapshot.
        $this->assertDatabaseHas('entrance_attendances', ['employee_id' => $employee->id, 'attendance_date' => '2026-10-28', 'time_in' => '13:36:08', 'time_out' => '16:04:00']);
        $this->admin();
        $this->get('/entrance?date=2026-10-28')->assertInertia(fn (Assert $p) => $p->has('logs.data', 1)->where('logs.data.0.time_in', '13:36:08'));
    }

    public function test_consultation_only_day_still_appears_in_dtr_without_class_hardware_taps(): void
    {
        $employee = $this->faculty();
        $guard = $this->guard();
        $employee->schedules()->create(['day_of_week' => 3, 'schedule_type' => 'consultation', 'start_time' => '13:00', 'end_time' => '15:00']);
        $service = app(EntranceAttendanceService::class);
        $service->record('RFID-ONE', 'IN', Carbon::parse('2026-10-28 12:50', 'Asia/Manila'), $guard->id);
        $service->record('RFID-ONE', 'OUT', Carbon::parse('2026-10-28 15:10', 'Asia/Manila'), $guard->id);
        $dtr = app(DtrService::class)->build($employee->fresh(), '2026-10-16', '2026-10-31');
        $this->assertSame('12:50', $dtr['form_rows'][27]['pm_arrival']);
        $this->assertSame('15:10', $dtr['form_rows'][27]['pm_departure']);
        $this->assertEquals(2, $dtr['summary']['total_hours']);
        $this->assertSame(1, $dtr['summary']['days_present']);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_report_distinguishes_missed_incomplete_future_and_calendar_exclusions(): void
    {
        $employee = $this->faculty();
        $complete = $this->schedule($employee, '08:00', '10:00');
        $incomplete = $this->schedule($employee, '10:00', '11:00');
        $this->schedule($employee, '11:00', '12:00');
        $this->schedule($employee, '13:00', '15:00');
        $consultation = $this->schedule($employee, '16:00', '17:00', 'consultation');
        foreach ([[$complete, '08:05', '09:50'], [$incomplete, '10:00', null], [$consultation, '16:00', '17:00']] as [$s, $in, $out]) {
            AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($s), 'attendance_date' => '2026-10-05', 'time_in' => $in, 'time_out' => $out]);
        }
        $this->travelTo(Carbon::parse('2026-10-05 12:30', 'Asia/Manila'));
        $filters = ['start_date' => '2026-10-05', 'end_date' => '2026-10-05'];
        $report = app(ClassReportService::class)->build($filters);
        $this->assertSame($employee->id, $report['faculty_breakdown'][0]['value']);
        $this->assertSame('DCIT 21', $report['subject_breakdown'][0]['value']);
        $this->assertSame(['expected' => 4, 'ended' => 3, 'complete' => 1, 'incomplete' => 1, 'missed' => 1,
            'late' => 1, 'undertime' => 1, 'on_time' => 0, 'total_late_minutes' => 5, 'total_undertime_minutes' => 10,
            'excluded' => 0, 'completion_rate' => 33.3], $report['summary']);
        $this->assertSame(5, $report['rows'][0]['late_minutes']);
        $this->assertSame(10, $report['rows'][0]['early_departure_minutes']);
        $this->assertSame('upcoming', $report['rows'][3]['status']);
        CalendarEvent::create(['title' => 'Suspension', 'type' => 'suspension', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'scope_type' => 'campus', 'is_paid' => false]);
        $excluded = app(ClassReportService::class)->build($filters);
        $this->assertSame(4, $excluded['summary']['excluded']);
        $this->assertSame(0, $excluded['summary']['expected']);
        $this->assertNull($excluded['summary']['completion_rate']);
    }

    public function test_report_filters_and_snapshot_after_schedule_edit(): void
    {
        $employee = $this->faculty();
        $other = $this->faculty('TWO');
        $schedule = $this->schedule($employee);
        $this->schedule($other);
        AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($schedule), 'attendance_date' => '2026-10-05', 'time_in' => '08:00', 'time_out' => '10:00']);
        $schedule->update(['subject' => 'CHANGED', 'start_time' => '09:00']);
        $report = app(ClassReportService::class)->build(['start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'employee_id' => $employee->id, 'subject' => 'DCIT 21', 'section' => 'BSIT 1A', 'class_mode' => 'lecture']);
        $this->assertCount(1, $report['rows']);
        $this->assertStringStartsWith('08:00', $report['rows'][0]['scheduled_start']);
        $this->admin();
        $this->get('/class-reports?start_date=2026-10-05&end_date=2026-10-04')->assertSessionHasErrors('end_date');
    }

    public function test_class_punctuality_metrics_use_ended_eligible_sessions_and_saved_scheduled_times(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));
        $first = $this->faculty();
        $second = $this->faculty('TWO');
        $excluded = $this->faculty('EXCLUDED');
        $late = $this->schedule($first);
        $incomplete = $this->schedule($first, '10:00', '11:00');
        $this->schedule($first, '11:00', '12:00');
        $future = $this->schedule($first, '13:00', '14:00');
        $this->schedule($first, '15:00', '16:00', 'consultation');
        $onTime = $this->schedule($second);
        $excludedSchedule = $this->schedule($excluded);
        foreach ([[$first, $late, '08:05:01', '09:49:59'], [$first, $incomplete, '10:05:00', null],
            [$first, $future, '13:20:00', '13:40:00'], [$second, $onTime, '07:55:00', '10:03:00'],
            [$excluded, $excludedSchedule, '08:30:00', '09:00:00']] as [$employee, $schedule, $in, $out]) {
            AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($schedule),
                'attendance_date' => '2026-10-05', 'time_in' => $in, 'time_out' => $out]);
        }
        $event = CalendarEvent::create(['title' => 'Faculty activity', 'type' => 'official_school_activity',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'scope_type' => 'faculty', 'is_paid' => false]);
        $event->employees()->attach($excluded);
        $late->update(['start_time' => '09:00', 'end_time' => '09:30']);
        $filters = ['start_date' => '2026-10-05', 'end_date' => '2026-10-05'];
        $report = app(ClassReportService::class)->build($filters);
        foreach (['expected' => 5, 'ended' => 4, 'complete' => 2, 'late' => 1, 'undertime' => 1,
            'on_time' => 1, 'missed' => 1, 'incomplete' => 1, 'excluded' => 1,
            'total_late_minutes' => 6, 'total_undertime_minutes' => 11] as $key => $expected) {
            $this->assertSame($expected, $report['summary'][$key], $key);
        }
        $this->assertSame(50.0, $report['summary']['completion_rate']);
        $faculty = $report['faculty_breakdown']->firstWhere('value', $first->id);
        $this->assertSame(1, $faculty['late']);
        $this->assertSame(1, $faculty['undertime']);
        $this->assertSame(6, $faculty['total_late_minutes']);
        $this->assertSame(11, $report['subject_breakdown'][0]['total_undertime_minutes']);
        $this->assertSame(1, $report['trend'][0]['late']);
        $filtered = app(ClassReportService::class)->build([...$filters, 'employee_id' => $second->id]);
        $this->assertSame(1, $filtered['summary']['on_time']);
        $this->assertSame(0, $filtered['summary']['late']);
        $this->assertSame(0, $filtered['summary']['undertime']);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_faculty_access_is_private_and_admin_routes_are_forbidden(): void
    {
        $employee = $this->faculty();
        $other = $this->faculty('TWO');
        $admin = $this->admin();
        app(EntranceAttendanceService::class)->record('RFID-ONE', 'IN', Carbon::parse('2026-10-05 08:00'), $admin->id);
        app(EntranceAttendanceService::class)->record('RFID-TWO', 'IN', Carbon::parse('2026-10-05 08:00'), $admin->id);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]));
        $this->get('/entrance?date=2026-10-05')->assertInertia(fn (Assert $p) => $p->has('logs.data', 1)->where('logs.data.0.employee_id', $employee->id)->where('canRecord', false));
        $this->get('/attendance/dtr?employee_id='.$other->id.'&start_date=2026-10-01')->assertInertia(fn (Assert $p) => $p->where('dtr.employee.id', $employee->id)->missing('dtr.employee.rate_amount'));
        $this->tap('IN')->assertForbidden();
        $this->get('/class-reports')->assertForbidden();
        $this->get('/settings/dtr-signatories')->assertForbidden();
    }

    public function test_staff_login_and_existing_sessions_are_disabled_and_payroll_routes_are_gone(): void
    {
        $staff = User::factory()->create(['role' => 'payroll_staff', 'password' => 'password']);
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($staff)->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->admin();
        $this->get('/payroll')->assertNotFound();
        $this->post('/payroll/periods')->assertNotFound();
        $this->post('/attendance/generate-test')->assertNotFound();
        $this->get('/settings/dtr-signatories')->assertOk();
        $this->get('/faculty-ranks')->assertInertia(fn (Assert $p) => $p->missing('ranks.0.rate_amount')->missing('ranks.0.monthly_salary'));
    }

    public function test_entrance_backup_roundtrip_and_legacy_backup_without_entrance_records(): void
    {
        $this->faculty();
        $this->guard();
        $this->tap('IN')->assertSessionHasNoErrors();
        $this->admin();
        $backup = json_decode($this->get('/settings/backup/download?mode=custom&faculty_scope=all&groups[]=attendance')->assertOk()->streamedContent(), true);
        $this->assertCount(1, $backup['tables']['entrance_attendances']);
        EntranceAttendance::query()->delete();
        $file = fn ($data) => UploadedFile::fake()->createWithContent('backup.json', json_encode($data));
        $this->post('/settings/backup/restore', ['backup_file' => $file($backup)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('entrance_attendances', 1);
        $legacy = $backup;
        $legacy['application'] = 'CvSU Payroll System';
        unset($legacy['tables']['entrance_attendances']);
        $this->admin();
        $this->post('/settings/backup/restore', ['backup_file' => $file($legacy)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('entrance_attendances', 1);
    }

    public function test_class_hardware_retries_are_idempotent_without_school_records(): void
    {
        $employee = $this->faculty();
        $this->schedule($employee);
        config(['services.hardware.api_key' => 'separation-test']);
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));
        $tap = ['identifier' => 'RFID-ONE', 'method' => 'rfid', 'event_id' => 'CLASS-ONLY-IN', 'occurred_at' => '2026-10-05 08:00:00'];
        $this->withHeader('X-Hardware-Key', 'separation-test')->postJson('/api/hardware/tap', $tap)->assertOk()->assertJsonPath('action', 'IN');
        $this->postJson('/api/hardware/tap', $tap)->assertOk()->assertJsonPath('action', 'IN');
        $this->postJson('/api/hardware/tap', [...$tap, 'event_id' => 'CLASS-WAIT', 'occurred_at' => '2026-10-05 08:01:00'])->assertOk()->assertJsonPath('action', 'WAIT');
        $out = [...$tap, 'event_id' => 'CLASS-ONLY-OUT', 'occurred_at' => '2026-10-05 10:00:00'];
        $this->postJson('/api/hardware/tap', $out)->assertOk()->assertJsonPath('action', 'OUT');
        $this->postJson('/api/hardware/tap', $out)->assertOk()->assertJsonPath('action', 'OUT');
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_selected_faculty_calendar_coverage_and_approved_leave_only_exclude_affected_sessions(): void
    {
        $first = $this->faculty();
        $second = $this->faculty('TWO');
        $this->schedule($first);
        $this->schedule($second);
        $event = CalendarEvent::create(['title' => 'Selected activity', 'type' => 'official_school_activity', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'scope_type' => 'faculty', 'is_paid' => true]);
        $event->employees()->attach($first);
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));
        $filters = ['start_date' => '2026-10-05', 'end_date' => '2026-10-05'];
        $report = app(ClassReportService::class)->build($filters);
        $this->assertSame(1, $report['summary']['excluded']);
        $this->assertSame(1, $report['summary']['missed']);
        $leave = LeaveRequest::create(['employee_id' => $second->id, 'leave_type_id' => LeaveType::firstOrFail()->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Approved leave', 'status' => 'pending']);
        $this->assertSame(1, app(ClassReportService::class)->build($filters)['summary']['missed']);
        $leave->update(['status' => 'approved']);
        $this->assertSame(2, app(ClassReportService::class)->build($filters)['summary']['excluded']);
        $this->assertNull(app(ClassReportService::class)->build($filters)['summary']['completion_rate']);
    }

    public function test_archiving_hides_entrance_history_and_restore_returns_original_times(): void
    {
        $employee = $this->faculty();
        $this->guard();
        $this->tap('IN')->assertSessionHasNoErrors();
        $this->admin();
        $this->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertSessionHasNoErrors();
        $this->get('/entrance?date=2026-10-05')->assertInertia(fn (Assert $p) => $p->has('logs.data', 0));
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->get('/attendance/dtr?employee_id='.$employee->id)->assertNotFound();
        $this->post('/archived-faculty/'.$employee->id.'/restore')->assertSessionHasNoErrors();
        $this->get('/entrance?date=2026-10-05')->assertInertia(fn (Assert $p) => $p->has('logs.data', 1)->where('logs.data.0.time_in', '08:00:00'));
    }

    public function test_admin_can_reassign_disabled_staff_and_restored_staff_stays_disabled(): void
    {
        $staff = User::factory()->create(['role' => 'payroll_staff']);
        $this->admin();
        $backup = json_decode($this->get('/settings/backup/download')->assertOk()->streamedContent(), true);
        $this->put('/settings/accounts/'.$staff->id, ['name' => $staff->name, 'email' => $staff->email, 'role' => 'admin'])->assertSessionHasNoErrors();
        $this->withSession(['account_security_version' => $staff->fresh()->security_version])->actingAs($staff->fresh())->get('/')->assertOk();
        $this->post('/settings/backup/restore', ['backup_file' => UploadedFile::fake()->createWithContent('backup.json', json_encode($backup))])->assertSessionHasNoErrors();
        $this->actingAs($staff->fresh())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }
}

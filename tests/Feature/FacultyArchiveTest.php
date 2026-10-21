<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\HardwareEnrollment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Services\ClassAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FacultyArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number = 'COS-0001', string $status = 'active'): array
    {
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Saved', 'last_name' => 'Faculty',
            'email' => $number.'@example.test', 'status' => $status, 'rate_type' => 'hourly', 'rate_amount' => 100]);
        $user = User::factory()->create(['employee_id' => $employee->id, 'role' => 'faculty', 'email' => $employee->email]);
        $schedule = $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21', 'section' => 'BSIT 1A', 'class_mode' => 'laboratory', 'break_minutes' => 0]);
        $log = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', ...ClassAttendanceService::snapshot($schedule), 'time_in' => '08:00', 'time_out' => '10:00', 'status' => 'present', 'total_hours' => 2, 'worked_seconds' => 7200]);

        return [$employee, $user, $schedule, $log];
    }

    private function period(string $start = '2026-10-01', string $end = '2026-10-15'): PayrollPeriod
    {
        return PayrollPeriod::create(['period_name' => 'Saved period', 'start_date' => $start, 'end_date' => $end, 'status' => 'processing']);
    }

    private function payroll(Employee $employee, PayrollPeriod $period): PayrollRecord
    {
        return PayrollRecord::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'total_hours_worked' => 2, 'gross_pay' => 200, 'total_earnings' => 200, 'net_pay' => 200, 'status' => 'released']);
    }

    public function test_archive_hides_history_and_direct_urls_without_deleting_data(): void
    {
        [$employee, $faculty, $schedule, $log] = $this->faculty();
        [$visible, $visibleUser, $visibleSchedule, $visibleLog] = $this->faculty('COS-0002');
        $period = $this->period();
        $record = $this->payroll($employee, $period);
        $visibleRecord = $this->payroll($visible, $period);
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::firstOrFail()->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Saved leave', 'status' => 'pending']);
        $employee->rfidCards()->create(['rfid_uid' => 'AABBCCDD', 'status' => 'active']);
        $enrollment = HardwareEnrollment::create(['employee_id' => $employee->id, 'method' => 'rfid', 'status' => 'pending', 'expires_at' => now()->addMinutes(5)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/employees')->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertRedirect('/employees')->assertSessionHasNoErrors();
        $this->assertNull(Employee::find($employee->id));
        $this->assertNull(AttendanceLog::find($log->id));
        $this->assertNull(PayrollRecord::find($record->id));
        $this->assertNull(LeaveRequest::find($leave->id));
        $this->assertNull(User::find($faculty->id));
        $this->assertNull(FacultySchedule::find($schedule->id));
        $this->assertDatabaseHas('faculty_schedules', ['id' => $schedule->id, 'subject' => 'DCIT 21', 'class_mode' => 'laboratory']);
        $this->assertDatabaseHas('payroll_records', ['id' => $record->id, 'net_pay' => 200, 'status' => 'released']);
        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id, 'total_hours' => 2]);
        $this->assertDatabaseHas('hardware_enrollments', ['id' => $enrollment->id, 'status' => 'cancelled']);
        $this->get('/employees?sort=name')->assertOk()->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $visible->id));
        $this->get('/attendance?cutoff_start=2026-10-01')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.id', $visibleLog->id));
        $this->get('/payroll')->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.id', $visibleRecord->id)->where('periods.0.records_count', 1));
        $this->get('/leave')->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('employeeCount', 1)->has('latestPayrolls', 1)->has('recentAttendance', 1));
        foreach (['/employees/'.$employee->id.'/edit', '/employees/'.$employee->id.'/schedule', '/attendance/dtr?employee_id='.$employee->id, '/payroll/records/'.$record->id, '/settings/accounts/'.$faculty->id.'/edit'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->put('/leave/'.$leave->id.'/review', ['status' => 'approved'])->assertNotFound();
        $this->get('/archived-faculty?search=COS-0001')->assertInertia(fn (Assert $page) => $page->component('Employees/Archived')->has('employees.data', 1)->where('employees.data.0.id', $employee->id)->where('employees.data.0.schedules_count', 1));
    }

    public function test_restore_returns_original_ids_schedules_status_and_financial_values(): void
    {
        [$employee, $user, $schedule, $log] = $this->faculty(status: 'inactive');
        $period = $this->period();
        $record = $this->payroll($employee, $period);
        $savedSchedule = (array) DB::table('faculty_schedules')->find($schedule->id);
        $savedPayroll = (array) DB::table('payroll_records')->find($record->id);
        $savedLog = (array) DB::table('attendance_logs')->find($log->id);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertSessionHasNoErrors();
        $this->assertNull(PayrollPeriod::find($period->id));
        $this->from('/archived-faculty')->post('/archived-faculty/'.$employee->id.'/restore')->assertRedirect('/archived-faculty')->assertSessionHasNoErrors();
        $this->assertSame('inactive', Employee::findOrFail($employee->id)->status);
        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(PayrollPeriod::find($period->id));
        $this->assertSame($savedSchedule, (array) DB::table('faculty_schedules')->find($schedule->id));
        $this->assertSame($savedPayroll, (array) DB::table('payroll_records')->find($record->id));
        $this->assertSame($savedLog, (array) DB::table('attendance_logs')->find($log->id));
        $this->get('/archived-faculty')->assertInertia(fn (Assert $page) => $page->has('employees.data', 0));
        $this->assertDatabaseHas('audit_trails', ['record_id' => $employee->id, 'action' => 'faculty.restored']);
    }

    public function test_archive_operations_are_admin_only(): void
    {
        [$employee, $faculty] = $this->faculty();
        $staff = User::factory()->create(['role' => 'payroll_staff']);
        foreach ([$staff, $faculty] as $user) {
            $this->actingAs($user)->get('/archived-faculty')->assertForbidden();
            $this->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertForbidden();
            $this->post('/archived-faculty/'.$employee->id.'/restore')->assertForbidden();
        }
        $this->assertNull($employee->fresh()->archived_at);
    }

    public function test_archived_account_cannot_login_or_keep_an_existing_session(): void
    {
        [$employee, $faculty] = $this->faculty();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertSessionHasNoErrors();
        Auth::logout();
        $this->post('/login', ['email' => $faculty->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($faculty)->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_archived_credentials_cannot_tap_and_return_after_restore(): void
    {
        [$employee] = $this->faculty();
        $employee->rfidCards()->create(['rfid_uid' => 'AABBCCDD', 'status' => 'active']);
        config(['services.hardware.api_key' => 'archive-key']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertSessionHasNoErrors();
        $this->withHeader('X-Hardware-Key', 'archive-key')->getJson('/api/hardware/rfid-cards')->assertJsonPath('uids', '');
        $this->postJson('/api/hardware/tap', ['identifier' => 'AABBCCDD', 'method' => 'rfid'])->assertNotFound();
        $this->post('/archived-faculty/'.$employee->id.'/restore')->assertSessionHasNoErrors();
        $this->getJson('/api/hardware/rfid-cards')->assertJsonPath('uids', 'AABBCCDD');
    }

    public function test_bulk_archive_only_hides_selected_faculty_and_protects_own_account(): void
    {
        [$first] = $this->faculty();
        [$second] = $this->faculty('COS-0002');
        [$untouched] = $this->faculty('COS-0003');
        $admin = User::factory()->create(['role' => 'admin', 'employee_id' => $untouched->id]);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$first->id, $untouched->id]])->assertSessionHasErrors('employee_ids');
        $this->assertNotNull(Employee::find($first->id));
        $this->post('/employees/archive', ['employee_ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, Employee::count());
        $this->assertNotNull(Employee::find($untouched->id));
        $this->assertSame(3, DB::table('employees')->count());
    }

    public function test_archived_identifiers_are_not_reused_and_backup_retains_records(): void
    {
        [$employee] = $this->faculty();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertSessionHasNoErrors();
        $this->get('/employees/create')->assertInertia(fn (Assert $page) => $page->where('nextEmployeeNumber', 'COS-0002'));
        $response = $this->get('/settings/backup/download')->assertOk();
        $backup = json_decode($response->streamedContent(), true);
        $this->assertCount(1, $backup['tables']['employees']);
        $this->assertNotNull($backup['tables']['employees'][0]['archived_at']);
        $this->assertCount(1, $backup['tables']['faculty_schedules']);
    }

    public function test_calendar_and_accounts_do_not_expose_archived_faculty(): void
    {
        [$employee, $faculty] = $this->faculty();
        $event = CalendarEvent::create(['title' => 'Faculty Activity', 'type' => 'asynchronous', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'scope_type' => 'faculty', 'is_paid' => false]);
        $event->employees()->attach($employee->id);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$employee->id]])->assertSessionHasNoErrors();
        $this->get('/school-calendar?month=2026-10')->assertInertia(fn (Assert $page) => $page->has('events', 0)->has('employees', 0));
        $this->get('/settings/accounts')->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.id', $admin->id));
        $this->post('/settings/accounts', ['name' => 'Invalid Link', 'email' => 'invalid@example.test', 'role' => 'faculty', 'employee_id' => $employee->id, 'password' => 'password123'])->assertSessionHasErrors('employee_id');
        $this->post('/archived-faculty/'.$employee->id.'/restore')->assertSessionHasNoErrors();
        $this->assertNotNull(CalendarEvent::find($event->id));
    }

    public function test_editing_shared_calendar_event_preserves_hidden_faculty_membership(): void
    {
        [$archived] = $this->faculty();
        [$visible] = $this->faculty('COS-0002');
        $event = CalendarEvent::create(['title' => 'Shared activity', 'type' => 'asynchronous', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'scope_type' => 'faculty', 'is_paid' => false]);
        $event->employees()->attach([$archived->id, $visible->id]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/employees/archive', ['employee_ids' => [$archived->id]])->assertSessionHasNoErrors();
        $this->put('/school-calendar/events/'.$event->id, ['title' => 'Updated activity', 'type' => 'asynchronous', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'scope_type' => 'faculty', 'is_paid' => false, 'employee_ids' => [$visible->id]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('calendar_event_employee', ['calendar_event_id' => $event->id, 'employee_id' => $archived->id]);
        $this->assertSame([$visible->id], $event->fresh()->employees->pluck('id')->all());
        $this->post('/archived-faculty/'.$archived->id.'/restore')->assertSessionHasNoErrors();
        $this->assertCount(2, $event->fresh()->employees);
    }
}

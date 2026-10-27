<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\RfidCard;
use App\Models\User;
use App\Services\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SchoolCalendarDtrTest extends TestCase
{
    use RefreshDatabase, \Tests\CreatesClassSessions;

    public function test_calendar_event_does_not_credit_hours_without_taps(): void
    {
        $employee = $this->employee('EMP-001', 'Ada');
        $other = $this->employee('EMP-002', 'Grace');
        $this->mondaySchedule($employee);
        $this->mondaySchedule($other);
        $event = CalendarEvent::create([
            'title' => 'Asynchronous Classes', 'type' => 'asynchronous',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'is_paid' => true, 'scope_type' => 'faculty',
        ]);
        $event->employees()->attach($employee);

        $row = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['rows']->firstWhere('date', '2026-10-05');
        $otherRow = app(DtrService::class)->build($other, '2026-10-01', '2026-10-15')['rows']->firstWhere('date', '2026-10-05');

        $this->assertSame('ASYNCHRONOUS', $row['event']['label']);
        $this->assertNull($row['worked_hours']);
        $this->assertNull($row['time_in']);
        $this->assertNull($row['time_out']);
        $this->assertNull($otherRow['event']);
        $this->assertNull($otherRow['worked_hours']);
    }

    public function test_unscheduled_tap_is_retained_for_audit_but_hidden_from_dtr(): void
    {
        $employee = $this->employee('EMP-001', 'Ada');
        AttendanceLog::create([
            'employee_id' => $employee->id, 'attendance_date' => '2026-10-06',
            'time_in' => '08:00', 'time_out' => '17:00', 'total_hours' => 8,
            'status' => 'present',
        ]);

        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['rows']->firstWhere('date', '2026-10-06');

        $this->assertFalse($row['has_schedule']);
        $this->assertNull($row['time_in']);
        $this->assertNull($row['time_out']);
        $this->assertNull($row['worked_hours']);
        $this->assertSame('not_scheduled', $row['status']);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id, 'attendance_date' => '2026-10-06']);
    }

    public function test_hardware_accepts_class_taps_on_calendar_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila'));
        config(['services.hardware.api_key' => 'test-hardware-key']);
        $employee = $this->employee('EMP-001', 'Ada');
        FacultySchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60]);
        RfidCard::create(['employee_id' => $employee->id, 'rfid_uid' => 'AABBCCDD', 'status' => 'active']);
        CalendarEvent::create([
            'title' => 'Regular Holiday', 'type' => 'regular_holiday',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'is_paid' => false, 'scope_type' => 'campus',
        ]);

        $this->withHeader('X-Hardware-Key', 'test-hardware-key')->postJson('/api/hardware/tap', [
            'identifier' => 'AABBCCDD', 'method' => 'rfid',
            'event_id' => 'PAID-EVENT-1', 'occurred_at' => '2026-10-05 08:00:00',
        ])->assertOk()->assertJsonPath('action', 'IN');

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseHas('hardware_attendance_events', ['event_id' => 'PAID-EVENT-1']);
        Carbon::setTestNow();
    }

    public function test_regular_holiday_is_informational_without_paid_hours(): void
    {
        $employee = $this->employee('EMP-001', 'Ada');
        FacultySchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60]);
        CalendarEvent::create([
            'title' => 'Founders Day', 'type' => 'regular_holiday',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'is_paid' => true, 'scope_type' => 'campus',
        ]);

        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['rows']->firstWhere('date', '2026-10-05');

        $this->assertSame('REGULAR HOLIDAY', $row['event']['label']);
        $this->assertNull($row['worked_hours']);
        $this->assertNull($row['margin_hours']);
        $this->assertNull($row['time_in']);
        $this->assertNull($row['time_out']);
    }

    public function test_official_school_activity_does_not_credit_hours_without_taps(): void
    {
        $employee = $this->employee('EMP-001', 'Ada');
        $this->mondaySchedule($employee);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/school-calendar/events', [
            'title' => 'University Sportsfest',
            'type' => 'official_school_activity',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-05',
            'is_paid' => false,
            'scope_type' => 'campus',
            'department' => null,
            'employee_ids' => [],
            'remarks' => 'Campus-wide activity',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('calendar_events', [
            'type' => 'official_school_activity',
            'is_paid' => true,
        ]);

        $row = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['rows']
            ->firstWhere('date', '2026-10-05');

        $this->assertSame('OFFICIAL SCHOOL ACTIVITY', $row['event']['label']);
        $this->assertNull($row['worked_hours']);
        $this->assertNull($row['margin_hours']);
        $this->assertNull($row['time_in']);
        $this->assertNull($row['time_out']);
    }

    public function test_faculty_dtr_forces_linked_employee_and_uses_historical_signatory(): void
    {
        $employee = $this->employee('EMP-001', 'Ada');
        $other = $this->employee('EMP-002', 'Grace');
        DtrSignatory::create([
            'name' => 'Dr. Historical Dean', 'designation' => 'Dean',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-10-31',
        ]);
        $user = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]);

        $this->actingAs($user)->get('/attendance/dtr?employee_id='.$other->id.'&start_date=2026-10-01')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dtr/Show')
            ->where('dtr.employee.id', $employee->id)
            ->where('dtr.cutoff.end_date', '2026-10-15')
            ->where('dtr.signatory.name', 'DR. HISTORICAL DEAN')
            ->where('dtr.signatory.designation', 'In Charge')
            ->has('dtr.rows', 15)
            ->has('dtr.form_rows', 31)
            );
    }

    private function employee(string $number, string $name): Employee
    {
        return Employee::create([
            'employee_no' => $number, 'first_name' => $name, 'last_name' => 'Faculty',
            'department' => 'Department of Computer Studies', 'rate_type' => 'hourly',
            'rate_amount' => 100, 'status' => 'active',
        ]);
    }

    private function mondaySchedule(Employee $employee): void
    {
        FacultySchedule::create([
            'employee_id' => $employee->id, 'day_of_week' => 1, 'schedule_type' => 'class',
            'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60,
        ]);
    }
}

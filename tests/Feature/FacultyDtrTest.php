<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FacultyDtrTest extends TestCase
{
    use RefreshDatabase, \Tests\CreatesClassSessions;

    public function test_faculty_online_dtr_only_contains_their_live_attendance_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:30:00', 'Asia/Manila'));
        $employee = $this->employee('EMP-001', 'Ada');
        $otherEmployee = $this->employee('EMP-002', 'Grace');
        FacultySchedule::create([
            'employee_id' => $employee->id,
            'day_of_week' => 5,
            'schedule_type' => 'class',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
        ]);
        $this->classLog([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-25',
            'time_in' => '08:00:00',
            'method_in' => 'rfid',
            'total_hours' => 0,
            'status' => 'incomplete',
        ]);
        AttendanceLog::create([
            'employee_id' => $otherEmployee->id,
            'attendance_date' => '2026-09-25',
            'time_in' => '08:00:00',
            'method_in' => 'rfid',
            'status' => 'incomplete',
        ]);
        $user = User::factory()->create([
            'role' => 'faculty',
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($user)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->has('employees', 0)
                ->has('logs.data', 1)
                ->where('logs.data.0.employee_id', $employee->id)
                ->where('dtr.employee.id', $employee->id)
                ->has('dtr.rows.9.sessions', 1)
                ->where('dtr.summary.days_present', 1)
                ->where('dtr.summary.scheduled_days', 2)
                ->where('dtr.summary.absent_days', 1)
            );

        Carbon::setTestNow();
    }

    private function employee(string $number, string $firstName): Employee
    {
        return Employee::create([
            'employee_no' => $number,
            'first_name' => $firstName,
            'last_name' => 'Faculty',
            'rate_type' => 'hourly',
            'rate_amount' => 100,
            'status' => 'active',
        ]);
    }
}

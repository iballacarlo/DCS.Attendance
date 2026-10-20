<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_leave_credits_only_scheduled_days_and_appears_in_dtr(): void
    {
        $employee = $this->employee();
        $this->mondaySchedule($employee);
        $type = LeaveType::where('code', 'VL')->firstOrFail();
        LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-06',
            'reason' => 'Personal leave', 'status' => 'approved',
        ]);

        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $monday = $dtr['rows']->firstWhere('date', '2026-10-05');
        $tuesday = $dtr['rows']->firstWhere('date', '2026-10-06');

        $this->assertSame('VL - LEAVE', $monday['event']['label']);
        $this->assertSame(8.0, $monday['payable_hours']);
        $this->assertNull($monday['time_in']);
        $this->assertFalse($tuesday['has_schedule']);
        $this->assertNull($tuesday['event']);
    }

    public function test_leave_without_pay_is_authorized_but_credits_no_hours(): void
    {
        $employee = $this->employee();
        $this->mondaySchedule($employee);
        $type = LeaveType::where('code', 'LWOP')->firstOrFail();
        LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'reason' => 'Personal leave', 'status' => 'approved',
        ]);

        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['rows']->firstWhere('date', '2026-10-05');

        $this->assertSame('unpaid_leave', $row['status']);
        $this->assertSame(0, $row['payable_hours']);
        $this->assertNull($row['margin_hours']);
        $this->assertSame(0, $dtr['summary']['absent_days']);
    }

    public function test_faculty_can_only_cancel_own_pending_request(): void
    {
        $employee = $this->employee();
        $type = LeaveType::where('code', 'SL')->firstOrFail();
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
            'reason' => 'Medical', 'status' => 'pending',
        ]);
        $user = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]);

        $this->actingAs($user)->post("/leave/{$leave->id}/cancel")->assertRedirect();
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'cancelled']);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_no' => 'EMP-LEAVE', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'email' => 'ada.leave@cvsu.edu.ph', 'status' => 'active', 'department' => 'DCS',
            'rate_type' => 'hourly', 'rate_amount' => 100,
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

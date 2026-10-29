<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\AttendanceLog;
use App\Services\ClassAttendanceService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_counts_today_classes_and_review_records_instead_of_school_taps(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:30', 'Asia/Manila'));
        $employee = Employee::create(['employee_no' => 'ADMIN-TEST', 'first_name' => 'Test', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        foreach (['08:00', '14:00'] as $start) {
            $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'class_mode' => 'lecture',
                'subject' => 'TEST', 'section' => 'BSIT 1A', 'start_time' => $start, 'end_time' => $start === '08:00' ? '09:00' : '15:00']);
        }
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'consultation', 'start_time' => '16:00', 'end_time' => '17:00']);
        $employee->schedules()->create(['day_of_week' => 2, 'schedule_type' => 'class', 'subject' => 'TUESDAY', 'start_time' => '10:00', 'end_time' => '11:00']);
        $schedule = $employee->schedules()->where('schedule_type', 'class')->orderBy('start_time')->firstOrFail();
        $log = AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($schedule),
            'attendance_date' => '2026-10-05', 'time_in' => '08:00']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('adminOverview.classes_today', 2)->where('classAnalytics.summary.incomplete', 1)->missing('adminOverview.pending_leave_requests')->where('employeeCount', 1));
        $log->update(['time_out' => '09:00']);
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('classAnalytics.summary.incomplete', 0));
        CalendarEvent::create(['title' => 'Suspension', 'type' => 'suspension', 'scope_type' => 'campus',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'is_paid' => false]);
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('adminOverview.classes_today', 0));
    }

    public function test_faculty_do_not_receive_admin_review_or_class_counts(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => null]))->get('/')
            ->assertInertia(fn (Assert $p) => $p->where('adminOverview', null));
    }
}

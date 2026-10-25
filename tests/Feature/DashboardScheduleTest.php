<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_sees_only_own_current_manila_day_in_chronological_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:30:00', 'UTC'));
        try {
            $employee = Employee::create(['employee_no' => 'TODAY', 'first_name' => 'Today', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 100, 'rate_type' => 'hourly']);
            $other = Employee::create(['employee_no' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 100, 'rate_type' => 'hourly']);
            $class = ['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21', 'section' => 'BSIT 1A', 'class_mode' => 'lecture'];
            $employee->schedules()->create([...$class, 'day_of_week' => 2, 'subject' => 'Tomorrow']);
            $employee->schedules()->create([...$class, 'schedule_type' => 'consultation', 'start_time' => '13:00', 'end_time' => '14:00']);
            $employee->schedules()->create($class);
            $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);
            $other->schedules()->create([...$class, 'subject' => 'Private']);
            $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]));
            $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('scheduleDate', '2026-10-05')->has('todaySchedule', 3)
                ->where('todaySchedule.0.subject', 'DCIT 21')->where('todaySchedule.0.section', 'BSIT 1A')
                ->where('todaySchedule.1.schedule_type', 'lunch_break')->where('todaySchedule.2.schedule_type', 'consultation'));
            Carbon::setTestNow(Carbon::parse('2026-10-11 08:00', 'Asia/Manila'));
            $this->get('/')->assertInertia(fn (Assert $page) => $page->has('todaySchedule', 0));
        } finally {
            Carbon::setTestNow();
        }
    }
}

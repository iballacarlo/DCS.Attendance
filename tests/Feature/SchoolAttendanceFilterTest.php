<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SchoolAttendanceFilterTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'rate_amount' => 0, 'status' => 'active']);
    }

    private function log(Employee $employee, string $date, bool $complete = true): void
    {
        EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => $date,
            'time_in' => '08:00:00', 'time_out' => $complete ? '17:00:00' : null, 'schedule_snapshot' => []]);
    }

    public function test_cutoff_filter_includes_leap_month_end_and_excludes_neighboring_dates(): void
    {
        $employee = $this->faculty('FIRST');
        foreach (['2024-02-15', '2024-02-16', '2024-02-29', '2024-03-01'] as $date) {
            $this->log($employee, $date);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/entrance?cutoff_start=2024-02-16')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('filters.cutoff_start', '2024-02-16')
            ->where('canSelectFaculty', true)->has('logs.data', 2)->where('logs.total', 2)
            ->where('logs.data.0.attendance_date', '2024-02-29')->where('logs.data.1.attendance_date', '2024-02-16'));
    }

    public function test_admin_can_combine_faculty_and_status_with_the_selected_cutoff(): void
    {
        $first = $this->faculty('FIRST');
        $second = $this->faculty('SECOND');
        $this->log($first, '2026-10-16');
        $this->log($first, '2026-10-17', false);
        $this->log($second, '2026-10-18', false);
        $this->log($first, '2026-10-15', false);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = '/entrance?cutoff_start=2026-10-16&employee_id='.$first->id;
        $this->get($url.'&status=incomplete')->assertInertia(fn (Assert $p) => $p->has('logs.data', 1)
            ->where('logs.data.0.attendance_date', '2026-10-17')->where('logs.data.0.time_out', null)->has('employees', 2));
        $this->get($url.'&status=complete')->assertInertia(fn (Assert $p) => $p->has('logs.data', 1)->where('logs.data.0.attendance_date', '2026-10-16'));
    }

    public function test_faculty_filter_cannot_reveal_another_facultys_records(): void
    {
        $first = $this->faculty('FIRST');
        $second = $this->faculty('SECOND');
        $this->log($first, '2026-10-16');
        $this->log($second, '2026-10-17');
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $first->id]))
            ->get('/entrance?cutoff_start=2026-10-16&employee_id='.$second->id)
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('logs.data', 1)->where('logs.data.0.employee_id', $first->id)
            ->where('canSelectFaculty', false)->where('filters.employee_id', '')->has('employees', 0));
    }

    public function test_default_cutoff_legacy_date_links_and_validation(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 10:00', 'Asia/Manila'));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/entrance')->assertInertia(fn (Assert $p) => $p->where('filters.cutoff_start', '2026-10-16'));
        $this->get('/entrance?date=2026-10-05')->assertInertia(fn (Assert $p) => $p->where('filters.cutoff_start', '2026-10-01'));
        $this->get('/entrance?cutoff_start=2026-10-28')->assertSessionHasErrors('cutoff_start');
        $this->get('/entrance?cutoff_start=2026-02-30')->assertSessionHasErrors('cutoff_start');
        $this->get('/entrance?cutoff_start=1999-10-01')->assertSessionHasErrors('cutoff_start');
        $this->get('/entrance?status=invalid')->assertSessionHasErrors('status');
    }

    public function test_pagination_retains_all_filters_and_stable_newest_first_order(): void
    {
        $employee = $this->faculty('FIRST');
        foreach (range(16, 31) as $day) {
            $this->log($employee, '2026-10-'.$day, false);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = '/entrance?cutoff_start=2026-10-16&employee_id='.$employee->id.'&status=incomplete';
        $response = $this->get($url)->assertInertia(fn (Assert $p) => $p->has('logs.data', 10)->where('logs.total', 16)
            ->where('logs.data.0.attendance_date', '2026-10-31'));
        $next = $response->viewData('page')['props']['logs']['next_page_url'];
        $this->assertStringContainsString('cutoff_start=2026-10-16', $next);
        $this->assertStringContainsString('employee_id='.$employee->id, $next);
        $this->assertStringContainsString('status=incomplete', $next);
        $this->get($url.'&page=2')->assertInertia(fn (Assert $p) => $p->has('logs.data', 6)->where('logs.data.0.attendance_date', '2026-10-21'));
    }
}

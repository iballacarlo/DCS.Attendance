<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecordFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function faculty(string $number): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Mark Gil', 'middle_name' => 'D.', 'last_name' => $number,
            'status' => 'active', 'rate_type' => 'hourly', 'rate_amount' => 100]);
    }

    private function log(Employee $employee, array $values = []): AttendanceLog
    {
        return AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            'is_legacy' => false, 'schedule_type' => 'class', 'scheduled_start' => '08:00', 'scheduled_end' => '10:00:00',
            'time_in' => '07:55', 'time_out' => '10:05:00', 'status' => 'present', 'method_in' => 'rfid',
            'method_out' => 'rfid', 'worked_seconds' => 7200, 'total_hours' => 2, ...$values]);
    }

    public function test_attendance_status_filters_use_taps_and_schedule_without_mutating_payable_hours(): void
    {
        $employee = $this->faculty('VISIBLE');
        $complete = $this->log($employee);
        $late = $this->log($employee, ['time_in' => '08:01:00', 'worked_seconds' => 7140, 'total_hours' => 1.9833]);
        $earlyOut = $this->log($employee, ['time_out' => '09:59', 'worked_seconds' => 7140, 'total_hours' => 1.9833]);
        $missing = $this->log($employee, ['time_out' => null, 'status' => 'incomplete', 'worked_seconds' => 0, 'total_hours' => 0]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/attendance?cutoff_start=2026-10-01&status=late_undertime')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2)->where('logs.data.0.display_status', 'late_undertime'));
        $this->get('/attendance?cutoff_start=2026-10-01&status=present')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.id', $complete->id));
        $this->get('/attendance?cutoff_start=2026-10-01')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 4)->where('logs.data', fn ($logs) => collect($logs)->firstWhere('id', $missing->id)['display_status'] === null));
        $this->get('/attendance?status=incomplete')->assertSessionHasErrors('status');
        $this->assertSame('late_undertime', $late->refresh()->display_status);
        $this->assertSame('late_undertime', $earlyOut->refresh()->display_status);
        $this->assertEquals(1.9833, $late->total_hours);
        $this->assertEquals(0, $missing->refresh()->total_hours);
        $this->assertNull($missing->display_status);
    }

    public function test_faculty_selection_persists_through_pagination_and_cannot_bypass_ownership(): void
    {
        $first = $this->faculty('FIRST');
        $second = $this->faculty('SECOND');
        $archived = $this->faculty('ARCHIVED');
        $archived->forceFill(['archived_at' => now()])->save();
        for ($i = 0; $i < 17; $i++) {
            $this->log($first, ['attendance_date' => sprintf('2026-10-%02d', intdiv($i, 2) + 1),
                'scheduled_start' => $i % 2 ? '13:00' : '08:00', 'scheduled_end' => $i % 2 ? '15:00' : '10:00',
                'time_in' => $i % 2 ? '13:01' : '08:01', 'time_out' => $i % 2 ? '15:00' : '10:00']);
        }
        $own = $this->log($second);
        $url = '/attendance?cutoff_start=2026-10-01&employee_id='.$first->id.'&status=late_undertime&method=rfid';
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url.'&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('logs.total', 17)->has('logs.data', 2)
                ->where('logs.data.0.employee_id', $first->id)->has('facultyChoices', 2)
                ->where('logs.prev_page_url', fn ($url) => str_contains($url, 'employee_id='.$first->id) && str_contains($url, 'status=late_undertime')));
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $second->id]))
            ->get('/attendance?cutoff_start=2026-10-01&employee_id='.$first->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('facultyChoices', 0)->has('logs.data', 1)->where('logs.data.0.id', $own->id));
    }

    public function test_employee_archive_and_leave_selectors_filter_by_id_and_keep_archived_choices_separate(): void
    {
        $first = $this->faculty('FIRST');
        $second = $this->faculty('SECOND');
        $archived = $this->faculty('ARCHIVED');
        $archived->forceFill(['archived_at' => now()])->save();
        $type = LeaveType::firstOrFail();
        foreach ([$first, $second] as $employee) {
            LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Personal', 'status' => 'pending']);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/employees?sort=name&employee_id='.$first->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $first->id)->has('facultyChoices', 2));
        $this->get('/archived-faculty?employee_id='.$archived->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $archived->id)->has('archivedFacultyChoices', 1)->has('facultyChoices', 2));
        $this->get('/leave?employee_id='.$first->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.employee_id', $first->id));
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $second->id]))
            ->get('/leave?employee_id='.$first->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.employee_id', $second->id)->has('facultyChoices', 0));
    }
}

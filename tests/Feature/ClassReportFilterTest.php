<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClassReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassReportFilterTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'status' => 'active', 'rate_amount' => 0]);
    }

    private function schedule(Employee $employee, string $subject, string $section, string $type = 'class'): void
    {
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => $type, 'subject' => $subject,
            'section' => $section, 'class_mode' => 'lecture', 'start_time' => '08:00', 'end_time' => '10:00']);
    }

    public function test_subject_choices_belong_to_the_selected_faculty_and_sections_follow_subject(): void
    {
        $first = $this->faculty('FIRST');
        $second = $this->faculty('SECOND');
        $this->schedule($first, 'DCIT 24', 'BSIT 1A');
        $this->schedule($first, 'DCIT 26', 'BSIT 2A');
        $this->schedule($first, 'CONSULT', 'CONSULT', 'consultation');
        $this->schedule($second, 'ITEC 200-B', 'BSIT 3E');
        $this->actingAs(User::factory()->create());
        $url = '/class-reports?cutoff_start=2026-10-01&employee_id='.$first->id;
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('subjects', ['DCIT 24', 'DCIT 26'])->where('sections', ['BSIT 1A', 'BSIT 2A'])
            ->where('report.filters.employee_id', (string) $first->id));
        $this->get($url.'&subject=DCIT%2024')->assertInertia(fn (Assert $p) => $p
            ->where('subjects', ['DCIT 24', 'DCIT 26'])->where('sections', ['BSIT 1A'])
            ->where('report.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['subject'] === 'DCIT 24' && $row['employee_id'] === $first->id)));
        $this->get($url.'&subject=ITEC%20200-B&section=BSIT%203E')->assertInertia(fn (Assert $p) => $p
            ->where('report.filters.subject', '')->where('report.filters.section', '')
            ->where('subjects', ['DCIT 24', 'DCIT 26']));
        $this->get('/class-reports?cutoff_start=2026-10-01')->assertInertia(fn (Assert $p) => $p
            ->where('subjects', ['DCIT 24', 'DCIT 26', 'ITEC 200-B']));
    }

    public function test_sections_from_another_subject_are_cleared_and_empty_faculty_has_no_choices(): void
    {
        $first = $this->faculty('FIRST');
        $empty = $this->faculty('EMPTY');
        $this->schedule($first, 'DCIT 24', 'BSIT 1A');
        $this->schedule($first, 'DCIT 26', 'BSIT 2A');
        $this->actingAs(User::factory()->create());
        $this->get('/class-reports?cutoff_start=2026-10-01&employee_id='.$first->id.'&subject=DCIT%2024&section=BSIT%202A')
            ->assertInertia(fn (Assert $p) => $p->where('sections', ['BSIT 1A'])->where('report.filters.section', ''));
        $this->get('/class-reports?cutoff_start=2026-10-01&employee_id='.$empty->id)
            ->assertInertia(fn (Assert $p) => $p->where('subjects', [])->where('sections', [])->has('report.rows', 0));
    }

    public function test_historical_choices_are_scoped_to_the_faculty_and_selected_period(): void
    {
        $first = $this->faculty('FIRST');
        $second = $this->faculty('SECOND');
        foreach ([[$first, '2026-10-05', 'OLD CLASS', false, 'class'], [$first, '2026-09-05', 'OTHER MONTH', false, 'class'],
            [$second, '2026-10-05', 'OTHER FACULTY', false, 'class'], [$first, '2026-10-06', 'LEGACY', true, 'class'],
            [$first, '2026-10-07', 'CONSULT', false, 'consultation']] as [$employee, $date, $subject, $legacy, $type]) {
            AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => $date, 'subject' => $subject,
                'section' => 'HISTORY', 'schedule_type' => $type, 'is_legacy' => $legacy, 'class_mode' => 'lecture',
                'scheduled_start' => '08:00', 'scheduled_end' => '10:00', 'time_in' => '08:00', 'time_out' => '10:00', 'status' => 'present']);
        }
        $this->actingAs(User::factory()->create())->get('/class-reports?cutoff_start=2026-10-01&employee_id='.$first->id)
            ->assertInertia(fn (Assert $p) => $p->where('subjects', ['OLD CLASS'])->where('sections', ['HISTORY'])
                ->has('report.rows', 1)->where('report.summary.complete', 1));
    }

    public function test_month_year_cutoff_matches_other_pages_including_leap_month_end(): void
    {
        $employee = $this->faculty('FIRST');
        $this->schedule($employee, 'DCIT 24', 'BSIT 1A');
        $this->actingAs(User::factory()->create())->get('/class-reports?cutoff_start=2024-02-16&employee_id='.$employee->id)
            ->assertInertia(fn (Assert $p) => $p->where('report.filters.cutoff_start', '2024-02-16')
                ->where('report.filters.start_date', '2024-02-16')->where('report.filters.end_date', '2024-02-29')
                ->where('report.rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['date'] >= '2024-02-16' && $row['date'] <= '2024-02-29')));
    }

    public function test_default_legacy_links_and_invalid_cutoffs(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00', 'Asia/Manila'));
        $this->actingAs(User::factory()->create());
        $this->get('/class-reports')->assertInertia(fn (Assert $p) => $p->where('report.filters.cutoff_start', '2026-10-16')->where('report.filters.end_date', '2026-10-31'));
        $this->get('/class-reports?start_date=2026-10-05&end_date=2026-10-05')
            ->assertInertia(fn (Assert $p) => $p->where('report.filters.cutoff_start', '2026-10-01')->where('report.filters.end_date', '2026-10-15'));
        foreach (['2026-10-28', '2026-02-30', '1999-10-01'] as $value) {
            $this->get('/class-reports?cutoff_start='.$value)->assertSessionHasErrors('cutoff_start');
        }
    }

    public function test_open_class_becomes_for_review_only_after_ending_and_is_not_late_or_undertime(): void
    {
        $employee = $this->faculty('FIRST');
        $this->schedule($employee, 'DCIT 24', 'BSIT 1A');
        $schedule = $employee->schedules()->firstOrFail();
        $schedule->update(['start_time' => '10:00', 'end_time' => '11:00']);
        $log = AttendanceLog::create(['employee_id' => $employee->id, 'faculty_schedule_id' => $schedule->id,
            'attendance_date' => '2026-10-05', 'schedule_type' => 'class', 'is_legacy' => false,
            'subject' => 'DCIT 24', 'section' => 'BSIT 1A', 'class_mode' => 'lecture',
            'scheduled_start' => '10:00', 'scheduled_end' => '11:00', 'time_in' => '10:05']);
        $filters = ['start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'employee_id' => $employee->id];
        $this->travelTo(Carbon::parse('2026-10-05 10:30', 'Asia/Manila'));
        $ongoing = app(ClassReportService::class)->build($filters);
        $this->assertSame('in_progress', $ongoing['rows'][0]['status']);
        $this->assertSame(0, $ongoing['summary']['incomplete']);
        $this->travelTo(Carbon::parse('2026-10-05 11:00', 'Asia/Manila'));
        $ended = app(ClassReportService::class)->build($filters);
        $this->assertSame('incomplete', $ended['rows'][0]['status']); // Internal compatibility key; UI says For review.
        $this->assertSame(1, $ended['summary']['incomplete']);
        foreach (['on_time', 'late', 'undertime', 'missed', 'total_late_minutes', 'total_undertime_minutes'] as $field) {
            $this->assertSame(0, $ended['summary'][$field]);
        }
        $this->assertNull($log->fresh()->time_out);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }
}

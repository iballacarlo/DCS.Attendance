<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\FacultySchedule;
use App\Models\User;
use App\Services\CutoffService;
use App\Services\DailyScheduleService;
use App\Services\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Form48Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function faculty(): Employee
    {
        return Employee::create(['employee_no' => 'FORM48-1', 'first_name' => 'Form', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
    }

    private function block(Employee $employee, string $start, string $end, string $type = 'class'): FacultySchedule
    {
        return $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => $type, 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0, 'subject' => 'DCIT 21', 'section' => 'BSIT 1A']);
    }

    private function log(Employee $employee, FacultySchedule $block, ?string $in, ?string $out): void
    {
        EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => $in, 'time_out' => $out, 'source' => 'simulator', 'schedule_snapshot' => DailyScheduleService::snapshot($employee->fresh(), Carbon::parse('2026-10-05'))]);
    }

    public static function cutoffs(): array
    {
        return [['2026-02-01', 28, 15], ['2026-02-16', 28, 13], ['2028-02-16', 29, 14], ['2026-04-16', 30, 15], ['2026-10-01', 31, 15], ['2026-10-16', 31, 16], ['2026-12-16', 31, 16], ['2027-01-01', 31, 15]];
    }

    #[DataProvider('cutoffs')]
    public function test_form_always_has_31_rows_without_extending_cutoff_totals(string $cutoff, int $days, int $cutoffDays): void
    {
        [$start, $end] = CutoffService::range($cutoff);
        $dtr = app(DtrService::class)->build($this->faculty(), $start, $end);
        $this->assertCount(31, $dtr['form_rows']);
        $this->assertCount($cutoffDays, $dtr['rows']);
        $this->assertSame($days, $dtr['form_rows']->where('valid_day', true)->count());
        $this->assertSame($cutoffDays, $dtr['form_rows']->where('in_cutoff', true)->count());
        $this->assertSame(range(1, 31), $dtr['form_rows']->pluck('day')->all());
        foreach ($dtr['form_rows']->where('in_cutoff', false) as $row) {
            foreach (['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure', 'schedule_label', 'margin_hours', 'undertime_hours', 'undertime_minutes'] as $field) {
                $this->assertNull($row[$field]);
            }
        }
        $this->assertSame(0.0, $dtr['summary']['total_hours']);
    }

    public function test_no_taps_are_blank_and_incomplete_pairs_are_not_synthetic(): void
    {
        $employee = $this->faculty();
        $block = $this->block($employee, '08:00', '10:00');
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        foreach (['margin_hours', 'undertime_hours', 'undertime_minutes', 'shortfall_minutes'] as $field) {
            $this->assertNull($dtr['form_rows'][4][$field]);
        }
        $this->assertNull($dtr['form_rows'][5]['schedule_label']);
        $this->log($employee, $block, '08:00', null);
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['form_rows'][4];
        $this->assertSame('08:00', $row['am_arrival']);
        $this->assertNull($row['am_departure']);
        $this->assertNull($row['pm_departure']);
        $this->assertNull($row['margin_hours']);
        $this->assertNull($row['undertime_hours']);
        $this->assertNull($row['shortfall_minutes']);
        $this->assertSame(0.0, $dtr['summary']['total_hours']);
    }

    public function test_date_controls_support_new_years_and_default_cutoffs_follow_manila_time(): void
    {
        $employee = $this->faculty();
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]);
        foreach (['2026-10-15 23:59' => '2026-10-01', '2026-10-16 00:00' => '2026-10-16', '2026-12-31 23:59' => '2026-12-16', '2027-01-01 00:00' => '2027-01-01'] as $now => $cutoff) {
            Carbon::setTestNow(Carbon::parse($now, 'Asia/Manila'));
            $this->actingAs($faculty)->get('/attendance/dtr')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('dtr.employee.id', $employee->id)
                ->where('dtr.cutoff.start_date', $cutoff)
                ->has('dtr.form_rows', 31)
                ->missing('cutoffOptions'));
        }
        $this->get('/attendance/dtr?start_date=2028-02-16')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dtr.cutoff.end_date', '2028-02-29')->where('dtr.cutoff.label', 'February 16-29, 2028')
            ->where('dtr.form_rows.30.valid_day', false));
        $this->getJson('/attendance/dtr?start_date=2026-10-17')->assertUnprocessable()->assertJsonValidationErrors('cutoff_start');
        $this->getJson('/attendance/dtr?start_date=2101-01-01')->assertUnprocessable();
        $this->getJson('/attendance/dtr?start_date=1999-01-01')->assertUnprocessable();
        foreach (['2000-01-01', '2100-12-16'] as $boundary) {
            $this->get('/attendance/dtr?start_date='.$boundary)->assertOk();
        }
    }

    public static function scheduledDailyPairs(): array
    {
        return [
            ['07:30', '17:30', 8.0, 0],
            ['08:00', '17:00', 8.0, 0],
            ['08:30', '16:45', 7.25, 45],
            ['07:30', '16:30', 7.5, 30],
            ['08:30', '18:00', 7.5, 30],
            ['12:30', '17:00', 4.0, 240],
            ['12:00', '13:00', 0.0, 480],
            ['06:00', '07:00', 0.0, 480],
        ];
    }

    #[DataProvider('scheduledDailyPairs')]
    public function test_daily_taps_count_only_scheduled_class_and_consultation_time(string $in, string $out, float $hours, int $shortfall): void
    {
        $employee = $this->faculty();
        $morning = $this->block($employee, '08:00', '12:00');
        $this->block($employee, '13:00', '15:00');
        $this->block($employee, '15:00', '17:00', 'consultation');
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);
        $this->log($employee, $morning, $in, $out);
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['form_rows'][4];
        $this->assertSame('8:00 AM-5:00 PM', $row['schedule_label']);
        $this->assertSame($hours, $row['worked_hours']);
        $this->assertSame($shortfall, $row['shortfall_minutes']);
        $this->assertSame($shortfall ? null : $hours, $row['margin_hours']);
        $this->assertSame($shortfall >= 60 ? intdiv($shortfall, 60) : null, $row['undertime_hours']);
        $this->assertSame($shortfall ? $shortfall % 60 : null, $row['undertime_minutes']);
    }

    public function test_explicit_lunch_inside_a_legacy_full_day_schedule_is_excluded_once(): void
    {
        $employee = $this->faculty();
        $block = $this->block($employee, '08:00', '17:00');
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);
        $this->log($employee, $block, '07:30', '18:00');
        $row = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['form_rows'][4];
        $this->assertSame(8.0, $row['worked_hours']);
        $this->assertSame(8.0, $row['margin_hours']);
        $this->assertSame(0, $row['shortfall_minutes']);
    }
}

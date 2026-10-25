<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\User;
use App\Services\AttendanceCalculator;
use App\Services\ClassAttendanceService;
use App\Services\CutoffService;
use App\Services\DtrService;
use App\Services\PayrollComputationService;
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
        $log = new AttendanceLog(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', ...ClassAttendanceService::snapshot($block), 'time_in' => $in, 'time_out' => $out]);
        AttendanceCalculator::recalculate($log);
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

    public function test_shortfall_uses_daily_displayed_boundaries_and_does_not_add_payroll_deductions(): void
    {
        $employee = $this->faculty();
        foreach ([['08:00', '10:00', '08:15', '10:00'], ['10:00', '12:00', '10:00', '12:00'], ['14:00', '16:00', '14:00', '15:45']] as [$start, $end, $in, $out]) {
            $this->log($employee, $this->block($employee, $start, $end), $in, $out);
        }
        $this->log($employee, $this->block($employee, '12:00', '13:00', 'consultation'), '12:00', '13:00');
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['form_rows'][4];
        $this->assertSame('08:15', $row['am_arrival']);
        $this->assertSame('15:45', $row['pm_departure']);
        $this->assertNull($row['am_departure']);
        $this->assertNull($row['pm_arrival']);
        $this->assertSame(30, $row['shortfall_minutes']);
        $this->assertNull($row['undertime_hours']);
        $this->assertSame(30, $row['undertime_minutes']);
        $this->assertNull($row['margin_hours']);
        $this->assertSame(6.5, $row['worked_hours']);
        $this->assertSame(6.5, $dtr['summary']['total_hours']);
        $payroll = app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15');
        $this->assertSame(650.0, $payroll['gross_pay']);
        $this->assertSame(0, $payroll['late_undertime_minutes']);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id, 'late_minutes' => 0, 'undertime_minutes' => 0]);
    }

    public function test_report_caps_visible_taps_to_historical_schedule_intervals_without_gaps(): void
    {
        $employee = $this->faculty();
        $first = $this->block($employee, '08:00', '10:00');
        $this->log($employee, $first, '07:45', '10:15');
        $this->log($employee, $this->block($employee, '14:00', '16:00'), '13:45', '16:10');
        // Historical snapshot stays authoritative for a class whose plotting changes.
        $first->update(['start_time' => '07:00']);
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['form_rows'][4];
        $this->assertSame(4.0, $row['margin_hours']);
        $this->assertSame(4.0, $dtr['summary']['total_hours']);
        $this->assertSame(0, $row['shortfall_minutes']);
        $this->assertNull($row['undertime_hours']);
        $this->assertNull($row['undertime_minutes']);
        $this->assertSame('8:00 AM-4:00 PM', $row['schedule_label']);
        $this->assertSame('07:45', $row['am_arrival']);
        $this->assertSame('16:10', $row['pm_departure']);
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

    public function test_report_matches_printed_minutes_and_ignores_middle_class_lateness(): void
    {
        $employee = $this->faculty();
        $this->log($employee, $this->block($employee, '08:00', '10:00'), '08:29:40', '10:00:00');
        $this->log($employee, $this->block($employee, '14:00', '16:00'), '14:29:40', '16:00:00');
        $row = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['form_rows'][4];
        $this->assertSame(29, $row['shortfall_minutes']);
        $this->assertNull($row['undertime_hours']);
        $this->assertSame(29, $row['undertime_minutes']);
        // Changing an intermediate IN does not change the daily taps printed on the form.
        $log = AttendanceLog::orderByDesc('id')->first();
        $log->time_in = '14:30:00';
        AttendanceCalculator::recalculate($log);
        $row = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['form_rows'][4];
        $this->assertSame(29, $row['undertime_minutes']);
        $first = AttendanceLog::orderBy('id')->first();
        $first->time_in = '09:00';
        AttendanceCalculator::recalculate($first);
        $row = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['form_rows'][4];
        $this->assertSame(1, $row['undertime_hours']);
        $this->assertSame(0, $row['undertime_minutes']);
    }

    public function test_daily_report_excludes_gaps_without_changing_per_class_payroll(): void
    {
        $employee = $this->faculty();
        foreach ([['08:00', '10:00', '08:00', '10:00'], ['10:00', '12:00', '10:10', '12:00'], ['13:00', '17:00', '13:00', '17:00']] as [$start, $end, $in, $out]) {
            $this->log($employee, $this->block($employee, $start, $end), $in, $out);
        }
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $row = $dtr['form_rows'][4];
        $this->assertSame('08:00', $row['am_arrival']);
        $this->assertSame('17:00', $row['pm_departure']);
        $this->assertSame(8.0, $row['worked_hours']);
        $this->assertSame(8.0, $row['margin_hours']);
        $this->assertSame(0, $row['shortfall_minutes']);
        $this->assertSame(7.8333, $dtr['summary']['total_hours']);
        $payroll = app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15');
        $this->assertSame(783.33, $payroll['gross_pay']);
    }

    public function test_missing_middle_class_timeout_does_not_change_the_daily_visible_pair(): void
    {
        $employee = $this->faculty();
        $this->log($employee, $this->block($employee, '08:00', '10:00'), '08:00', '10:00');
        $this->log($employee, $this->block($employee, '10:00', '12:00'), '10:00', null);
        $this->log($employee, $this->block($employee, '13:00', '17:00'), '13:00', '17:00');
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $this->assertSame(8.0, $dtr['form_rows'][4]['margin_hours']);
        $this->assertSame(6.0, $dtr['summary']['total_hours']);
        $this->assertNull(AttendanceLog::where('scheduled_start', '10:00')->first()->time_out);
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

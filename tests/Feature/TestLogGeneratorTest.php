<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\User;
use App\Services\DtrService;
use App\Services\TestAttendanceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TestLogGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): Employee
    {
        return Employee::create(['employee_no' => 'GEN-1', 'first_name' => 'Generated', 'last_name' => 'Faculty', 'status' => 'active', 'rate_type' => 'hourly', 'rate_amount' => 100]);
    }

    public function test_only_payroll_staff_can_generate_and_admin_and_faculty_are_forbidden(): void
    {
        foreach (['admin', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post('/attendance/generate-test', [])->assertForbidden();
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))->post('/attendance/generate-test', [])->assertSessionHasErrors('target_mode');
    }

    public function test_generated_days_are_mostly_full_hours_with_complete_rfid_pairs_and_ordered_taps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:00', 'Asia/Manila'));
        $employee = $this->employee();
        foreach (range(1, 6) as $day) {
            foreach ([['08:00', '10:00', 'class'], ['10:00', '12:00', 'class'], ['13:00', '14:00', 'consultation']] as [$start, $end, $type]) {
                $employee->schedules()->create(['day_of_week' => $day, 'schedule_type' => $type, 'start_time' => $start, 'end_time' => $end, 'subject' => 'DCIT 21', 'section' => 'BSIT 1A', 'break_minutes' => 0]);
            }
        }
        $staff = User::factory()->create(['role' => 'payroll_staff']);
        $data = ['target_mode' => 'selected', 'employee_ids' => [$employee->id], 'generation_mode' => 'fill_missing', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'];
        $this->actingAs($staff)->post('/attendance/generate-test', $data)->assertSessionHasNoErrors();
        $logs = AttendanceLog::orderBy('attendance_date')->orderBy('scheduled_start')->get();
        $this->assertCount(81, $logs);
        foreach ($logs as $log) {
            $this->assertSame('rfid', $log->method_in);
            $this->assertSame('rfid', $log->method_out);
            $this->assertSame('present', $log->status);
            $this->assertNotNull($log->time_in);
            $this->assertNotNull($log->time_out);
        }
        foreach ($logs->groupBy('attendance_date') as $daily) {
            $previousOut = null;
            foreach ($daily as $log) {
                if ($previousOut) {
                    $this->assertGreaterThanOrEqual($previousOut, $log->time_in);
                }
                $previousOut = $log->time_out;
            }
        }
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-31');
        $attended = $dtr['rows']->filter(fn ($row) => $row['time_in']);
        $this->assertCount(27, $attended);
        $this->assertSame(22, $attended->filter(fn ($row) => (int) $row['worked_seconds'] === (int) $row['scheduled_seconds'])->count());
        $this->assertSame(5, $attended->filter(fn ($row) => $row['worked_seconds'] < $row['scheduled_seconds'])->count());
        $before = $logs->toArray();
        $this->post('/attendance/generate-test', $data)->assertSessionHasNoErrors();
        $this->assertSame($before, AttendanceLog::orderBy('attendance_date')->orderBy('scheduled_start')->get()->toArray());
    }

    public function test_overwrite_replaces_all_scoped_logs_including_legacy_orphan_incomplete_and_future_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 18:00', 'Asia/Manila'));
        $employee = $this->employee();
        $class = $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21A', 'section' => 'BSIT 1A']);
        $consultation = $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'consultation', 'start_time' => '10:00', 'end_time' => '11:00']);
        $source = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'is_legacy' => true, 'time_in' => '08:00', 'time_out' => '11:00']);
        $old = collect([$source]);
        foreach ([
            ['attendance_date' => '2026-10-05', 'faculty_schedule_id' => $class->id, 'legacy_source_id' => $source->id, 'remarks' => 'Reconstructed from legacy daily attendance'],
            ['attendance_date' => '2026-10-05', 'faculty_schedule_id' => $consultation->id, 'time_in' => '10:00', 'time_out' => null],
            ['attendance_date' => '2026-10-06', 'subject' => 'Removed schedule', 'method_in' => 'manual'],
            ['attendance_date' => '2026-10-12', 'faculty_schedule_id' => $class->id, 'method_in' => 'fingerprint'],
        ] as $attributes) {
            $old->push(AttendanceLog::create(['employee_id' => $employee->id, ...$attributes]));
        }
        $before = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-09-30', 'remarks' => 'Keep previous cutoff']);
        $after = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-16', 'remarks' => 'Keep next cutoff']);
        $other = $employee->replicate();
        $other->employee_no = 'GEN-2';
        $other->save();
        $otherLog = AttendanceLog::create(['employee_id' => $other->id, 'attendance_date' => '2026-10-05', 'remarks' => 'Keep unselected faculty']);
        $preserved = collect([$before, $after, $otherLog])->mapWithKeys(fn ($log) => [$log->id => $log->fresh()->toArray()]);
        $request = ['target_mode' => 'selected', 'employee_ids' => [$employee->id], 'generation_mode' => 'overwrite', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15'];
        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))->post('/attendance/generate-test', $request)->assertSessionHasNoErrors();
        foreach ($old as $log) {
            $this->assertDatabaseMissing('attendance_logs', ['id' => $log->id]);
        }
        foreach ($preserved as $id => $attributes) {
            $this->assertSame($attributes, AttendanceLog::findOrFail($id)->toArray());
        }
        $fresh = AttendanceLog::where('employee_id', $employee->id)->whereBetween('attendance_date', ['2026-10-01', '2026-10-15'])->get();
        $this->assertCount(2, $fresh);
        foreach ($fresh as $log) {
            $this->assertNull($log->legacy_source_id);
            $this->assertFalse($log->is_legacy);
            $this->assertSame('Generated class test attendance.', $log->remarks);
            $this->assertSame('present', $log->status);
            $this->assertSame('rfid', $log->method_in);
            $this->assertSame('rfid', $log->method_out);
            $this->assertSame('2026-10-05', $log->attendance_date);
        }
        $sessions = app(DtrService::class)->build($employee->fresh(), '2026-10-01', '2026-10-15')['rows']->flatMap(fn ($row) => $row['sessions']);
        $this->assertTrue($sessions->every(fn ($session) => ! $session['reconstructed']));
        $this->post('/attendance/generate-test', $request)->assertSessionHasNoErrors();
        $this->assertSame(2, AttendanceLog::where('employee_id', $employee->id)->whereBetween('attendance_date', ['2026-10-01', '2026-10-15'])->count());
    }

    public function test_fill_missing_does_not_delete_or_relabel_legacy_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 18:00', 'Asia/Manila'));
        $employee = $this->employee();
        $schedule = $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00']);
        $source = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'is_legacy' => true]);
        AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'faculty_schedule_id' => $schedule->id, 'is_legacy' => false, 'legacy_source_id' => $source->id, 'remarks' => 'Reconstructed from legacy daily attendance']);
        $before = AttendanceLog::orderBy('id')->get()->toArray();
        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))->post('/attendance/generate-test', ['target_mode' => 'selected', 'employee_ids' => [$employee->id], 'generation_mode' => 'fill_missing', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15'])->assertSessionHasNoErrors();
        $this->assertSame($before, AttendanceLog::orderBy('id')->get()->toArray());
    }

    public function test_overwrite_rolls_back_every_faculty_if_generation_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 18:00', 'Asia/Manila'));
        $first = $this->employee();
        $second = $first->replicate();
        $second->employee_no = 'GEN-2';
        $second->save();
        foreach ([$first, $second] as $employee) {
            $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00']);
            AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'is_legacy' => true, 'remarks' => 'Keep on failure']);
        }
        $before = AttendanceLog::orderBy('id')->get()->toArray();
        $this->app->instance(TestAttendanceGenerator::class, new class extends TestAttendanceGenerator
        {
            private int $calls = 0;

            public function pairs(Collection $schedules, Carbon $date, Carbon $now, Collection $existing, bool $overwrite, bool $shortfall): array
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Generation failed for test');
                }

                return parent::pairs($schedules, $date, $now, $existing, $overwrite, $shortfall);
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))->post('/attendance/generate-test', ['target_mode' => 'all', 'generation_mode' => 'overwrite', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15']);
            $this->fail('Expected the injected generation failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Generation failed for test', $exception->getMessage());
        }
        $this->assertSame($before, AttendanceLog::orderBy('id')->get()->toArray());
    }

    public function test_early_and_late_taps_do_not_overlap_adjacent_sessions_or_go_into_the_future(): void
    {
        $generator = new class extends TestAttendanceGenerator
        {
            protected function pick(int $min, int $max): int
            {
                return $max;
            }
        };
        $schedules = collect([
            new FacultySchedule(['id' => 1, 'start_time' => '08:00', 'end_time' => '10:00']),
            new FacultySchedule(['id' => 2, 'start_time' => '10:00', 'end_time' => '12:00']),
        ]);
        // IDs are guarded when constructing Eloquent models; set the fixture identities explicitly.
        $schedules[0]->id = 1;
        $schedules[1]->id = 2;
        $date = Carbon::parse('2026-10-05', 'Asia/Manila');
        $pairs = $generator->pairs($schedules, $date, $date->copy()->setTime(12, 5), collect(), false, false);
        $this->assertSame('07:40', $pairs[1]['in']->format('H:i'));
        $this->assertSame('10:00', $pairs[1]['out']->format('H:i'));
        $this->assertSame('10:00', $pairs[2]['in']->format('H:i'));
        $this->assertSame('12:05', $pairs[2]['out']->format('H:i'));
    }

    public function test_fill_missing_pairs_respect_existing_taps(): void
    {
        $generator = new TestAttendanceGenerator;
        $first = new FacultySchedule(['start_time' => '08:00', 'end_time' => '10:00']);
        $first->id = 1;
        $second = new FacultySchedule(['start_time' => '10:00', 'end_time' => '12:00']);
        $second->id = 2;
        $old = new AttendanceLog(['faculty_schedule_id' => 1, 'scheduled_start' => '08:00', 'time_in' => '08:00', 'time_out' => '10:10']);
        $date = Carbon::parse('2026-10-05', 'Asia/Manila');
        $pairs = $generator->pairs(collect([$first, $second]), $date, $date->copy()->setTime(18, 0), collect([1 => $old]), false, false);
        $this->assertArrayNotHasKey(1, $pairs);
        $this->assertSame('10:10', $pairs[2]['in']->format('H:i'));
        $this->assertSame('10:10', $old->time_out);
    }
}

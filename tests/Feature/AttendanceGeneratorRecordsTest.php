<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\RfidCard;
use App\Models\User;
use App\Services\AttendanceGenerationService;
use App\Services\ClassAttendanceService;
use App\Services\ClassReportService;
use App\Services\DailyScheduleService;
use App\Services\DtrService;
use App\Services\EntranceAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AttendanceGeneratorRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance_generator.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-05 18:00', 'Asia/Manila'));
    }

    private function faculty(string $number = 'FIRST'): Employee
    {
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'status' => 'active', 'rate_amount' => 0]);
        RfidCard::create(['employee_id' => $employee->id, 'rfid_uid' => $number.'-CARD', 'status' => 'active']);
        foreach ([['08:00', '10:00'], ['14:00', '16:00']] as [$start, $end]) {
            $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'class_mode' => 'lecture',
                'start_time' => $start, 'end_time' => $end, 'subject' => 'DCIT 24', 'section' => 'BSIT 1A']);
        }
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'consultation', 'start_time' => '10:00', 'end_time' => '11:00']);
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);

        return $employee;
    }

    private function actor(): User
    {
        return User::factory()->create(['role' => 'generator', 'must_change_password' => false]);
    }

    private function filters(string $mode, ?Employee $employee = null): array
    {
        return ['mode' => $mode, 'cutoff_start' => '2026-10-01', 'employee_id' => $employee?->id];
    }

    private function service(int $scenario = 0, bool $maximum = false): AttendanceGenerationService
    {
        return new class($scenario, $maximum) extends AttendanceGenerationService
        {
            public function __construct(private int $scenario, private bool $maximum) {}

            protected function pick(string $seed, string $key, int $min, int $max): int
            {
                return str_ends_with($key, ':scenario') ? $this->scenario : ($this->maximum ? $max : $min);
            }
        };
    }

    public static function scenarios(): array
    {
        return ['full class' => [0, false, false], 'last on time' => [89, false, false],
            'late' => [90, true, false], 'last late' => [92, true, false],
            'undertime' => [93, false, true], 'last undertime' => [95, false, true],
            'late and undertime' => [96, true, true], 'last combined' => [97, true, true]];
    }

    public function test_both_modes_use_the_same_on_time_heavy_distribution(): void
    {
        $employee = $this->faculty();
        foreach (['class' => 2, 'school' => 1] as $mode => $sessions) {
            $counts = ['on_time' => 0, 'late' => 0, 'undertime' => 0, 'both' => 0, 'absent' => 0];
            foreach (range(0, 99) as $draw) {
                $preview = $this->service($draw)->preview($this->filters($mode, $employee), 'distribution');
                $counts['absent'] += $preview['absent'];
                foreach ($preview['samples'] as $sample) {
                    $late = $sample['time_in'] > '08:00:00';
                    $under = $sample['time_out'] < ($mode === 'class' && $sample['time_in'] < '12:00:00' ? '10:00:00' : '16:00:00');
                    if ($mode === 'class' && $sample['time_in'] >= '12:00:00') {
                        $late = $sample['time_in'] > '14:00:00';
                    }
                    $counts[$late ? ($under ? 'both' : 'late') : ($under ? 'undertime' : 'on_time')]++;
                }
            }
            $this->assertSame(['on_time' => 90 * $sessions, 'late' => 3 * $sessions,
                'undertime' => 3 * $sessions, 'both' => 2 * $sessions, 'absent' => 2 * $sessions], $counts);
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_random_absence_leaves_no_fake_logs_and_existing_records_are_preserved(): void
    {
        $employee = $this->faculty();
        $actor = $this->actor();
        $this->travelTo(Carbon::parse('2026-10-06 18:00', 'Asia/Manila'));
        foreach (['class' => 2, 'school' => 1] as $mode => $sessions) {
            $filters = $this->filters($mode, $employee);
            $service = $this->service(98);
            $preview = $service->preview($filters, 'absence');
            $result = $service->generate($filters, 'absence', $actor, $preview['guards']);
            $this->assertSame($sessions, $preview['absent']);
            $this->assertSame($sessions, $result['absent']);
            $this->assertSame(0, $result['created']);
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->assertSame(2, app(ClassReportService::class)->build(['employee_id' => $employee->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['summary']['missed']);
        $this->assertSame(1, app(DtrService::class)->build($employee, '2026-10-05', '2026-10-05')['summary']['absent_days']);
        foreach (['class', 'school'] as $mode) {
            $filters = [...$this->filters($mode, $employee), 'overwrite' => true];
            $this->service(0)->generate($filters, 'create', $actor);
            $before = ($mode === 'class' ? AttendanceLog::all() : EntranceAttendance::all())->toArray();
            $preview = $this->service(99)->preview($filters, 'keep');
            $result = $this->service(99)->generate($filters, 'keep', $actor, $preview['guards']);
            $this->assertSame(0, $result['updated']);
            $this->assertSame(0, $result['absent']);
            $this->assertSame($before, ($mode === 'class' ? AttendanceLog::all() : EntranceAttendance::all())->toArray());
        }
    }

    #[DataProvider('scenarios')]
    public function test_complete_class_pairs_reflect_each_random_scenario_without_touching_dtr(int $scenario, bool $late, bool $under): void
    {
        $employee = $this->faculty();
        $service = $this->service($scenario);
        $preview = $service->preview($this->filters('class', $employee), 'same-seed');
        $this->assertSame(2, $preview['eligible']);
        $this->assertDatabaseCount('attendance_logs', 0);
        $result = $service->generate($this->filters('class', $employee), 'same-seed', $this->actor());
        $this->assertSame($preview['samples'], $result['samples']);
        $this->assertSame(2, $result['created']);
        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertDatabaseCount('entrance_attendances', 0);
        foreach (AttendanceLog::all() as $log) {
            $this->assertSame('rfid', $log->method_in);
            $this->assertSame('rfid', $log->method_out);
            $this->assertSame('class', $log->schedule_type);
            $this->assertGreaterThan($log->time_in, $log->time_out);
            $this->assertNull($log->remarks);
        }
        $report = app(ClassReportService::class)->build(['employee_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
        $this->assertSame($late ? 2 : 0, $report['summary']['late']);
        $this->assertSame($under ? 2 : 0, $report['summary']['undertime']);
        $this->assertSame(0, $report['summary']['incomplete']);
        $this->assertSame(0.0, app(DtrService::class)->build($employee, '2026-10-05', '2026-10-05')['summary']['total_hours']);
        $this->assertSame(0, DB::table('audit_trails')->count());
    }

    #[DataProvider('scenarios')]
    public function test_school_pairs_remain_independent_include_consultation_and_save_actor_and_snapshot(int $scenario, bool $late, bool $under): void
    {
        $employee = $this->faculty();
        $actor = $this->actor();
        $service = $this->service($scenario);
        $snapshot = DailyScheduleService::snapshot($employee, Carbon::parse('2026-10-05', 'Asia/Manila'));
        $result = $service->generate($this->filters('school', $employee), 'school-seed', $actor);
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 1);
        $log = EntranceAttendance::firstOrFail();
        $this->assertSame('simulator', $log->source);
        $this->assertSame($actor->id, $log->recorded_by);
        $this->assertSame($actor->id, $log->out_recorded_by);
        $this->assertSame($snapshot, $log->schedule_snapshot);
        $this->assertSame($late ? '08:01:00' : '08:00:00', $log->time_in);
        $this->assertSame($under ? '15:59:00' : '16:00:00', $log->time_out);
        $dtr = app(DtrService::class)->build($employee, '2026-10-05', '2026-10-05');
        $this->assertSame(5.0, $dtr['rows']->first()['scheduled_hours']);
        $this->assertSame(($late ? 1 : 0) + ($under ? 1 : 0), $dtr['summary']['undertime_minutes']);
        $employee->schedules()->delete();
        $employee->scheduleBreaks()->delete();
        $this->assertSame($dtr['summary'], app(DtrService::class)->build($employee->fresh(), '2026-10-05', '2026-10-05')['summary']);
        $this->assertSame(0, $service->generate($this->filters('school', $employee), 'another-seed', $actor)['created']);
    }

    public function test_existing_partial_class_and_school_records_are_preserved_on_repeated_submissions(): void
    {
        $employee = $this->faculty();
        $schedule = $employee->schedules()->where('schedule_type', 'class')->orderBy('start_time')->firstOrFail();
        $oldClass = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($schedule), 'time_in' => '08:07:00', 'method_in' => 'fingerprint']);
        $oldSchool = EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '07:45:00', 'schedule_snapshot' => [[28800, 36000]]]);
        $actor = $this->actor();
        $service = $this->service();
        $this->assertSame(1, $service->generate($this->filters('class', $employee), 'seed', $actor)['created']);
        $this->assertSame(0, $service->generate($this->filters('class', $employee), 'seed', $actor)['created']);
        $this->assertSame(0, $service->generate($this->filters('school', $employee), 'seed', $actor)['created']);
        $this->assertSame('08:07:00', $oldClass->fresh()->time_in);
        $this->assertNull($oldClass->fresh()->time_out);
        $this->assertSame('fingerprint', $oldClass->fresh()->method_in);
        $this->assertNull($oldSchool->fresh()->time_out);
        $this->assertSame([[28800, 36000]], $oldSchool->fresh()->schedule_snapshot);
    }

    public function test_future_and_ongoing_sessions_are_skipped_and_generated_times_never_exceed_now(): void
    {
        $employee = $this->faculty();
        $actor = $this->actor();
        $service = $this->service(0, true);
        $this->travelTo(Carbon::parse('2026-10-05 09:30', 'Asia/Manila'));
        $this->assertSame(0, $service->preview($this->filters('class', $employee), 'seed')['eligible']);
        $this->assertSame(0, $service->preview($this->filters('school', $employee), 'seed')['eligible']);
        $this->travelTo(Carbon::parse('2026-10-05 10:01', 'Asia/Manila'));
        $this->assertSame(1, $service->generate($this->filters('class', $employee), 'seed', $actor)['created']);
        $this->assertSame('10:01:00', AttendanceLog::firstOrFail()->time_out);
        $this->assertSame(0, $service->generate(['mode' => 'school', 'cutoff_start' => '2026-11-01', 'employee_id' => $employee->id], 'seed', $actor)['created']);
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->assertDatabaseMissing('attendance_logs', ['attendance_date' => '2026-10-12']);
    }

    public function test_active_rfid_and_faculty_eligibility_are_checked_again_when_generating(): void
    {
        $employee = $this->faculty();
        $service = $this->service();
        $actor = $this->actor();
        $this->assertSame(2, $service->preview($this->filters('class', $employee), 'seed')['eligible']);
        $employee->rfidCards()->update(['status' => 'inactive']);
        $this->assertSame(0, $service->generate($this->filters('class', $employee), 'seed', $actor)['created']);
        $employee->rfidCards()->update(['status' => 'active']);
        $employee->update(['status' => 'inactive']);
        $this->assertSame(0, $service->generate($this->filters('school', $employee), 'seed', $actor)['created']);
        $employee->forceFill(['status' => 'active', 'archived_at' => now()])->save();
        $this->assertSame(0, $service->generate($this->filters('class', $employee), 'seed', $actor)['created']);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_calendar_and_approved_leave_exclusions_match_the_reports_for_both_flows(): void
    {
        $employee = $this->faculty();
        $other = $this->faculty('SECOND');
        $service = $this->service();
        $event = CalendarEvent::create(['title' => 'Suspension', 'type' => 'suspension', 'scope_type' => 'faculty',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'is_paid' => false]);
        $event->employees()->attach($employee->id);
        foreach (['class', 'school'] as $mode) {
            $this->assertSame(0, $service->preview($this->filters($mode, $employee), 'seed')['eligible']);
            $this->assertGreaterThan(0, $service->preview($this->filters($mode, $other), 'seed')['eligible']);
        }
        $event->delete();
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::firstOrFail()->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Leave', 'status' => 'approved']);
        foreach (['class', 'school'] as $mode) {
            $this->assertSame(0, $service->preview($this->filters($mode, $employee), 'seed')['eligible']);
        }
        $this->travelTo(Carbon::parse('2026-11-30 18:00', 'Asia/Manila'));
        $service->generate(['mode' => 'class', 'cutoff_start' => '2026-11-16', 'employee_id' => $employee->id], 'seed', $this->actor());
        $this->assertDatabaseMissing('attendance_logs', ['attendance_date' => '2026-11-30']);
    }

    public function test_neighboring_classes_share_their_boundary_and_overlaps_are_not_generated(): void
    {
        $employee = $this->faculty();
        $second = $employee->schedules()->where('schedule_type', 'class')->orderByDesc('start_time')->firstOrFail();
        $second->update(['start_time' => '10:00', 'end_time' => '12:00']);
        $service = $this->service(0, true);
        $this->assertSame(2, $service->generate($this->filters('class', $employee), 'seed', $this->actor())['created']);
        $logs = AttendanceLog::orderBy('scheduled_start')->get();
        $this->assertSame('10:00:00', $logs[0]->time_out);
        $this->assertSame('10:00:00', $logs[1]->time_in);
        $other = $this->faculty('OVERLAP');
        $other->schedules()->where('schedule_type', 'class')->orderByDesc('start_time')->first()->update(['start_time' => '09:00', 'end_time' => '11:00']);
        $this->assertSame(0, $service->generate($this->filters('class', $other), 'seed', $this->actor())['created']);
    }

    public function test_saved_snapshot_conflicts_and_legacy_consultation_logs_are_kept(): void
    {
        $employee = $this->faculty();
        $old = $employee->schedules()->where('schedule_type', 'class')->orderBy('start_time')->firstOrFail();
        $oldLog = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($old), 'time_in' => '08:00', 'time_out' => '10:00', 'method_in' => 'fingerprint']);
        $old->delete();
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:30', 'end_time' => '09:30', 'subject' => 'EDITED']);
        $consultation = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            'schedule_type' => 'consultation', 'is_legacy' => true, 'time_in' => '10:00', 'time_out' => '11:00']);
        $this->assertSame(1, $this->service()->generate($this->filters('class', $employee), 'seed', $this->actor())['created']);
        $this->assertSame('fingerprint', $oldLog->fresh()->method_in);
        $this->assertTrue($consultation->fresh()->is_legacy);
        $this->assertDatabaseMissing('attendance_logs', ['subject' => 'EDITED']);
    }

    public function test_failed_school_out_rolls_back_the_entire_pair(): void
    {
        $this->faculty();
        $this->faculty('SECOND');
        $real = new EntranceAttendanceService;
        $this->mock(EntranceAttendanceService::class)->shouldReceive('record')->times(4)->andReturnUsing(function (...$args) use ($real) {
            if ($args[0] === 'SECOND-CARD' && $args[1] === 'OUT') {
                throw new RuntimeException('Interrupted write');
            }

            return $real->record(...$args);
        });
        try {
            $this->service()->generate($this->filters('school'), 'seed', $this->actor());
            $this->fail('The interrupted write should throw.');
        } catch (RuntimeException $error) {
            $this->assertSame('Interrupted write', $error->getMessage());
        }
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_saved_snapshot_time_format_does_not_make_adjacent_classes_overlap(): void
    {
        $employee = $this->faculty();
        $first = $employee->schedules()->where('schedule_type', 'class')->orderBy('start_time')->firstOrFail();
        $employee->schedules()->where('schedule_type', 'class')->where('id', '!=', $first->id)->update(['start_time' => '10:00', 'end_time' => '12:00']);
        AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($first), 'scheduled_start' => '08:00:00', 'scheduled_end' => '10:00:00',
            'time_in' => '08:00:00', 'time_out' => '10:00:00']);
        $this->assertSame(1, $this->service()->generate($this->filters('class', $employee), 'seed', $this->actor())['created']);
        $this->assertDatabaseCount('attendance_logs', 2);
    }

    public function test_existing_tap_conflicts_cannot_force_generated_offsets_beyond_fifteen_minutes(): void
    {
        $employee = $this->faculty();
        $first = $employee->schedules()->where('schedule_type', 'class')->orderBy('start_time')->firstOrFail();
        $employee->schedules()->where('schedule_type', 'class')->where('id', '!=', $first->id)->update(['start_time' => '10:00', 'end_time' => '12:00']);
        AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($first), 'time_in' => '08:00', 'time_out' => '10:45']);
        $this->assertSame(0, $this->service()->generate($this->filters('class', $employee), 'seed', $this->actor())['created']);
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame('10:45', AttendanceLog::firstOrFail()->time_out);
    }

    public function test_consultation_only_days_are_school_eligible_but_break_only_days_are_not(): void
    {
        $employee = $this->faculty();
        $employee->schedules()->where('schedule_type', 'class')->delete();
        $service = $this->service();
        $this->assertSame(0, $service->preview($this->filters('class', $employee), 'seed')['eligible']);
        $this->assertSame(1, $service->generate($this->filters('school', $employee), 'seed', $this->actor())['created']);
        $this->assertSame(1.0, app(DtrService::class)->build($employee, '2026-10-05', '2026-10-05')['summary']['total_hours']);
        $other = $this->faculty('BREAK-ONLY');
        $other->schedules()->delete();
        $this->assertSame(0, $service->preview($this->filters('school', $other), 'seed')['eligible']);
    }

    public function test_service_cannot_be_used_to_bypass_the_feature_flag_or_account_role(): void
    {
        $this->faculty();
        $admin = User::factory()->create(['role' => 'admin']);
        try {
            $this->service()->generate($this->filters('class'), 'seed', $admin);
            $this->fail('An administrator must not use the private generator service.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        config(['attendance_generator.enabled' => false]);
        try {
            $this->service()->generate($this->filters('school'), 'seed', $this->actor());
            $this->fail('The disabled feature must not write records.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }
}

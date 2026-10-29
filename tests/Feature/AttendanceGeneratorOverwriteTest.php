<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\RfidCard;
use App\Models\User;
use App\Services\AttendanceGenerationService;
use App\Services\ClassAttendanceService;
use App\Services\DailyScheduleService;
use App\Services\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceGeneratorOverwriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance_generator.enabled' => true]);
        // Access and overwrite tests need stable pairs; distribution is tested separately.
        $this->app->bind(AttendanceGenerationService::class, fn () => new class extends AttendanceGenerationService
        {
            protected function pick(string $seed, string $key, int $min, int $max): int
            {
                return str_ends_with($key, ':scenario') ? 0 : parent::pick($seed, $key, $min, $max);
            }
        });

        $this->travelTo(Carbon::parse('2026-10-05 18:00', 'Asia/Manila'));
    }

    private function faculty(string $number = 'FIRST'): Employee
    {
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'status' => 'active', 'rate_amount' => 0]);
        RfidCard::create(['employee_id' => $employee->id, 'rfid_uid' => $number.'-CARD', 'status' => 'active']);
        foreach ([['08:00', '10:00'], ['14:00', '16:00']] as [$start, $end]) {
            $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => $start, 'end_time' => $end, 'subject' => 'ORIGINAL', 'section' => 'BSIT 1A', 'class_mode' => 'lecture']);
        }

        return $employee;
    }

    private function actor(): User
    {
        return User::factory()->create(['role' => 'generator', 'must_change_password' => false]);
    }

    private function filters(string $mode, Employee $employee, bool $overwrite = true): array
    {
        return ['mode' => $mode, 'cutoff_start' => '2026-10-01', 'employee_id' => $employee->id, 'overwrite' => $overwrite];
    }

    private function classLog(Employee $employee): AttendanceLog
    {
        $schedule = $employee->schedules()->where('schedule_type', 'class')->orderBy('start_time')->firstOrFail();

        return AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($schedule), 'time_in' => '08:30:00', 'time_out' => null, 'method_in' => 'fingerprint']);
    }

    private function schoolLog(Employee $employee): EntranceAttendance
    {
        return EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '08:30:00',
            'time_out' => null, 'schedule_snapshot' => DailyScheduleService::snapshot($employee, Carbon::parse('2026-10-05', 'Asia/Manila'))])->refresh();
    }

    public function test_class_can_generate_directly_and_repeat_the_same_cutoff_with_or_without_overwrite(): void
    {
        $employee = $this->faculty();
        $this->actingAs($this->actor());
        $filters = $this->filters('class', $employee, false);
        $this->postJson('/tools/attendance-generator/generate', $filters)
            ->assertOk()->assertJsonPath('result.created', 2)->assertJsonMissingPath('result.guards');
        $ids = AttendanceLog::orderBy('id')->pluck('id')->all();
        $this->postJson('/tools/attendance-generator/generate', $filters)
            ->assertOk()->assertJsonPath('result.created', 0)->assertJsonPath('result.updated', 0);
        AttendanceLog::query()->update(['time_in' => '09:30:00', 'time_out' => null]);
        $this->postJson('/tools/attendance-generator/generate', [...$filters, 'overwrite' => true, 'confirm_overwrite' => true])
            ->assertOk()->assertJsonPath('result.created', 0)->assertJsonPath('result.updated', 2);
        $this->assertSame($ids, AttendanceLog::orderBy('id')->pluck('id')->all());
        foreach (AttendanceLog::all() as $record) {
            $this->assertNotNull($record->time_out);
            $this->assertGreaterThan($record->time_in, $record->time_out);
            $this->assertGreaterThan(0, $record->worked_seconds);
        }
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_school_can_generate_directly_and_repeat_the_same_cutoff_without_repreviewing(): void
    {
        $employee = $this->faculty();
        $this->actingAs($this->actor());
        $filters = $this->filters('school', $employee, false);
        $this->postJson('/tools/attendance-generator/generate', $filters)
            ->assertOk()->assertJsonPath('result.created', 1);
        $record = EntranceAttendance::firstOrFail();
        $this->postJson('/tools/attendance-generator/generate', $filters)
            ->assertOk()->assertJsonPath('result.created', 0)->assertJsonPath('result.updated', 0);
        $record->update(['time_in' => '09:30:00', 'time_out' => null]);
        $this->postJson('/tools/attendance-generator/generate', [...$filters, 'overwrite' => true, 'confirm_overwrite' => true])
            ->assertOk()->assertJsonPath('result.updated', 1)->assertJsonMissingPath('result.guards');
        $this->assertNotNull($record->fresh()->time_out);
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_direct_generation_validates_scope_overwrite_and_future_dates(): void
    {
        $employee = $this->faculty();
        $record = $this->classLog($employee);
        $this->actingAs($this->actor());
        $filters = $this->filters('class', $employee);
        $this->postJson('/tools/attendance-generator/generate', $filters)
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_overwrite');
        $this->postJson('/tools/attendance-generator/generate', [...$filters, 'mode' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->postJson('/tools/attendance-generator/generate', [...$filters, 'cutoff_start' => '2026-11-01', 'confirm_overwrite' => true])
            ->assertOk()->assertJsonPath('result.created', 0)->assertJsonPath('result.updated', 0);
        $this->assertNull($record->fresh()->time_out);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_class_overwrite_updates_pairs_in_place_keeps_snapshots_and_respects_selected_flow_and_faculty(): void
    {
        $employee = $this->faculty();
        $other = $this->faculty('SECOND');
        $record = $this->classLog($employee);
        $otherLog = $this->classLog($other);
        $school = $this->schoolLog($employee);
        $before = $record->only(['id', 'faculty_schedule_id', 'subject', 'section', 'class_mode', 'scheduled_start', 'scheduled_end', 'attendance_date']);
        $createdAt = $record->getRawOriginal('created_at');
        $service = app(AttendanceGenerationService::class);
        $filters = $this->filters('class', $employee);
        $preview = $service->preview($filters, 'overwrite-seed');
        $this->assertSame(1, $preview['to_create']);
        $this->assertSame(1, $preview['to_overwrite']);
        $this->assertNull($record->fresh()->time_out);
        $result = $service->generate($filters, 'overwrite-seed', $this->actor(), $preview['guards']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($before, $record->fresh()->only(array_keys($before)));
        $this->assertSame($createdAt, $record->fresh()->getRawOriginal('created_at'));
        $this->assertSame('rfid', $record->fresh()->method_in);
        $this->assertSame('rfid', $record->fresh()->method_out);
        $this->assertNotNull($record->fresh()->time_out);
        $this->assertGreaterThan(0, $record->fresh()->worked_seconds);
        $this->assertNull($school->fresh()->time_out);
        $this->assertNull($otherLog->fresh()->time_out);
        $repeat = $service->generate($filters, 'overwrite-seed', $this->actor(), $preview['guards']);
        $this->assertSame(0, $repeat['created']);
        $this->assertSame(0, $repeat['updated']);
        $this->assertDatabaseCount('attendance_logs', 3);
    }

    public function test_a_skipped_overwrite_remains_a_boundary_for_other_planned_pairs(): void
    {
        $employee = $this->faculty();
        $first = $this->classLog($employee);
        $first->update(['time_out' => '14:45:00']);
        $earlier = AttendanceLog::create([
            'employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
            'schedule_type' => 'class', 'is_legacy' => false, 'subject' => 'Earlier',
            'scheduled_start' => '07:00:00', 'scheduled_end' => '08:00:00',
            'time_in' => '07:00:00', 'time_out' => '08:00:00',
        ]);
        $service = app(AttendanceGenerationService::class);
        $filters = $this->filters('class', $employee);
        $preview = $service->preview($filters, 'chain-conflict');
        $earlier->update(['time_out' => '08:46:00']);
        $result = $service->generate($filters, 'chain-conflict', $this->actor(), $preview['guards']);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('14:45:00', $first->fresh()->time_out);
        $this->assertSame('08:46:00', $earlier->fresh()->time_out);
        $this->assertDatabaseCount('attendance_logs', 2);
    }

    public function test_school_overwrite_uses_saved_daily_basis_even_after_schedule_deletion(): void
    {
        $employee = $this->faculty();
        $class = $this->classLog($employee);
        $record = $this->schoolLog($employee);
        $before = $record->only(['id', 'schedule_snapshot', 'source']);
        $createdAt = $record->getRawOriginal('created_at');
        $employee->schedules()->delete();
        $service = app(AttendanceGenerationService::class);
        $filters = $this->filters('school', $employee);
        $preview = $service->preview($filters, 'overwrite-school');
        $this->assertSame(1, $preview['to_overwrite']);
        $actor = $this->actor();
        $result = $service->generate($filters, 'overwrite-school', $actor, $preview['guards']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($before, $record->fresh()->only(array_keys($before)));
        $this->assertSame($createdAt, $record->fresh()->getRawOriginal('created_at'));
        $this->assertSame($actor->id, $record->fresh()->recorded_by);
        $this->assertSame($actor->id, $record->fresh()->out_recorded_by);
        $this->assertNotNull($record->fresh()->time_out);
        $this->assertNull($class->fresh()->time_out);
        $this->assertGreaterThan(0, app(DtrService::class)->build($employee->fresh(), '2026-10-05', '2026-10-05')['summary']['total_hours']);
    }

    public function test_class_overwrite_keeps_moved_and_deleted_schedule_snapshots_and_legacy_logs(): void
    {
        $employee = $this->faculty();
        $record = $this->classLog($employee);
        $employee->schedules()->whereKey($record->faculty_schedule_id)->update(['day_of_week' => 2, 'start_time' => '12:00', 'end_time' => '13:00', 'subject' => 'EDITED']);
        $legacy = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'schedule_type' => 'consultation', 'is_legacy' => true, 'time_in' => '10:00', 'time_out' => '11:00']);
        $service = app(AttendanceGenerationService::class);
        $filters = $this->filters('class', $employee);
        $preview = $service->preview($filters, 'moved-seed');
        $this->assertSame(1, $service->generate($filters, 'moved-seed', $this->actor(), $preview['guards'])['updated']);
        $this->assertSame('ORIGINAL', $record->fresh()->subject);
        $this->assertSame('08:00', $record->fresh()->scheduled_start);
        $employee->schedules()->delete();
        $before = $record->fresh()->only(['subject', 'section', 'class_mode', 'scheduled_start', 'scheduled_end']);
        $preview = $service->preview($filters, 'deleted-seed');
        $result = $service->generate($filters, 'deleted-seed', $this->actor(), $preview['guards']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame($before, $record->fresh()->only(array_keys($before)));
        $this->assertSame('10:00', $legacy->fresh()->time_in);
        $this->assertTrue($legacy->fresh()->is_legacy);
    }

    public function test_records_changed_or_created_after_preview_are_not_overwritten(): void
    {
        $employee = $this->faculty();
        $record = $this->schoolLog($employee);
        $service = app(AttendanceGenerationService::class);
        $filters = $this->filters('school', $employee);
        $preview = $service->preview($filters, 'seed');
        $record->update(['time_out' => '17:55:00']);
        $result = $service->generate($filters, 'seed', $this->actor(), $preview['guards']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('17:55:00', $record->fresh()->time_out);
        $this->assertArrayHasKey('Changed since preview; preview again', $result['reasons']);
        $classFilters = $this->filters('class', $employee);
        $preview = $service->preview($classFilters, 'seed');
        $newTap = $this->classLog($employee);
        $result = $service->generate($classFilters, 'seed', $this->actor(), $preview['guards']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['created']);
        $this->assertNull($newTap->fresh()->time_out);
    }

    public function test_preview_confirmation_is_required_and_request_payload_cannot_enable_overwrite(): void
    {
        $employee = $this->faculty();
        $record = $this->schoolLog($employee);
        $this->actingAs($this->actor());
        $this->get('/tools/attendance-generator')->assertInertia(fn (Assert $p) => $p->where('filters.overwrite', false));
        $filters = $this->filters('school', $employee);
        $preview = $this->postJson('/tools/attendance-generator/preview', $filters)->assertOk()->assertJsonPath('preview.to_overwrite', 1)->assertJsonMissingPath('preview.guards');
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token']])->assertUnprocessable()->assertJsonValidationErrors('confirm_overwrite');
        $this->assertNull($record->fresh()->time_out);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token'], 'confirm_overwrite' => true])
            ->assertOk()->assertJsonPath('result.updated', 1)->assertJsonMissingPath('result.guards');
        $record->refresh();
        $times = $record->only(['time_in', 'time_out']);
        $append = $this->postJson('/tools/attendance-generator/preview', $this->filters('school', $employee, false))->assertOk()->assertJsonPath('preview.to_overwrite', 0);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $append['token'], 'overwrite' => true, 'confirm_overwrite' => true])
            ->assertOk()->assertJsonPath('result.updated', 0);
        $this->assertSame($times, $record->fresh()->only(array_keys($times)));
    }

    public function test_overwrite_cannot_bypass_future_dates_or_ongoing_session_checks(): void
    {
        $employee = $this->faculty();
        $record = $this->schoolLog($employee);
        $this->travelTo(Carbon::parse('2026-10-05 15:00', 'Asia/Manila'));
        $service = app(AttendanceGenerationService::class);
        $filters = $this->filters('school', $employee);
        $preview = $service->preview($filters, 'seed');
        $this->assertSame(0, $preview['eligible']);
        $this->assertSame(0, $service->generate($filters, 'seed', $this->actor(), $preview['guards'])['updated']);
        $this->assertNull($record->fresh()->time_out);
        $future = [...$filters, 'cutoff_start' => '2026-11-01'];
        $preview = $service->preview($future, 'seed');
        $this->assertSame(0, $preview['eligible']);
    }
}

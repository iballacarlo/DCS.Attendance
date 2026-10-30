<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use App\Models\AcademicTerm;
use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\FacultyRank;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\RfidCard;
use App\Models\SemesterDaySnapshot;
use App\Models\SemesterEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttendanceGenerationService;
use App\Services\ClassAttendanceService;
use App\Services\ClassReportService;
use App\Services\CutoffService;
use App\Services\DailyScheduleService;
use App\Services\DtrService;
use App\Services\EntranceAttendanceService;
use App\Services\SelectiveBackupService;
use App\Services\SemesterBackupValidator;
use App\Services\SemesterLifecycleService;
use App\Services\SemesterManagementService;
use App\Services\SemesterScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SemesterHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 18:00', 'Asia/Manila'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->employee = Employee::create(['employee_no' => 'SEM-ONE', 'first_name' => 'Original', 'last_name' => 'Faculty',
            'status' => 'active', 'position' => 'Instructor', 'contract_start' => '2026-10-01', 'contract_end' => '2026-10-15', 'rate_amount' => 0]);
        RfidCard::create(['employee_id' => $this->employee->id, 'rfid_uid' => 'SEM-CARD', 'status' => 'active']);
    }

    private function term(bool $second = false): AcademicTerm
    {
        return app(SemesterManagementService::class)->create(['school_year' => '2026-2027', 'semester' => $second ? 'Second Semester' : 'First Semester',
            'start_date' => $second ? '2026-10-16' : '2026-10-01', 'end_date' => $second ? '2026-10-31' : '2026-10-15'], $this->admin);
    }

    private function blocks(bool $second = false): array
    {
        return [['schedule_type' => 'class', 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00',
            'subject' => $second ? 'DCIT 25A' : 'DCIT 24', 'section' => 'BSIT 2A', 'class_mode' => 'lecture'],
            ['schedule_type' => 'consultation', 'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '11:00'],
            ['schedule_type' => 'lunch_break', 'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00']];
    }

    private function publish(AcademicTerm $term, ?array $blocks = null, ?string $effective = null): void
    {
        app(SemesterManagementService::class)->save($term, $this->employee, $blocks ?? $this->blocks(), [], 'publish',
            $effective ?? $term->start_date->toDateString(), 'Verified schedule basis', $this->admin);
    }

    private function active(): AcademicTerm
    {
        $term = $this->term();
        $this->publish($term);
        app(SemesterManagementService::class)->transition($term, 'activate', 'Approved semester activation', $this->admin);

        return $term->fresh();
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a semester validation error.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_older_logs_remain_visible_in_verified_semester_filters_without_rewriting_records(): void
    {
        $schedule = $this->employee->schedules()->create($this->blocks()[0]);
        $log = AttendanceLog::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($schedule), 'time_in' => '08:00', 'time_out' => '10:00',
            'method_in' => 'rfid', 'method_out' => 'rfid']);
        EntranceAttendance::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-05',
            'time_in' => '08:00', 'time_out' => '11:00', 'schedule_snapshot' => [[28800, 39600]], 'source' => 'simulation']);
        $term = $this->active();
        $filters = ['start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'employee_id' => $this->employee->id];
        $unfiltered = app(ClassReportService::class)->build($filters);
        $filtered = app(ClassReportService::class)->build([...$filters, 'term_id' => (string) $term->id]);
        $this->assertSame($unfiltered['summary'], $filtered['summary']);
        $this->assertSame(1, $filtered['summary']['complete']);
        $this->assertSame(0, $filtered['summary']['missed']);
        $this->assertCount(1, $filtered['rows']);
        $this->actingAs($this->admin);
        foreach (['/attendance', '/entrance'] as $path) {
            $this->get($path.'?cutoff_start=2026-10-01&employee_id='.$this->employee->id.'&term_id='.$term->id.'&status=on_time')
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('logs.total', 1));
        }
        $this->assertNull($log->fresh()->academic_term_id);
        $this->assertSame($schedule->id, $log->fresh()->faculty_schedule_id);
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->assertFalse(SemesterScheduleService::matches($term->id + 1, (string) $term->id, '2026-10-05'));
        $this->assertFalse(SemesterScheduleService::matches(null, (string) $term->id, '2026-09-30'));
        $this->assertFalse(SemesterScheduleService::matches(null, (string) $term->id, '2026-10-16'));
    }

    public function test_scheduled_semester_starts_at_manila_midnight_without_closing_previous_history(): void
    {
        $first = $this->active();
        $second = $this->term(true);
        $this->publish($second, $this->blocks(true));
        $before = app(ClassReportService::class)->build(['employee_id' => $this->employee->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->all();
        $this->actingAs($this->admin);
        $this->assertSame('scheduled', $second->fresh()->status);
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame($first->id, SystemSetting::academicTerm()['id']);
        SemesterBackupValidator::check();
        $this->travelTo(Carbon::parse('2026-10-15 23:59:59', 'Asia/Manila'));
        $this->assertSame(0, app(SemesterLifecycleService::class)->synchronize());
        $this->travelTo(Carbon::parse('2026-10-15 16:00:00', 'UTC'));
        // A normal web request applies rollover without a required cron job.
        $this->get('/settings/semesters')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedTerm.id', $second->id)->where('selectedTerm.status', 'active')->where('today', '2026-10-16'));
        $this->assertSame('ended', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame($second->id, SystemSetting::academicTerm()['id']);
        $this->assertSame($before, app(ClassReportService::class)->build(['employee_id' => $this->employee->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->all());
        $this->assertNotEmpty(DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-19')));
        $this->assertSame(0, app(SemesterLifecycleService::class)->synchronize());
        $this->assertSame(1, SemesterEvent::where('academic_term_id', $second->id)->where('action', 'automatic_activation')->count());
        $this->assertSame(1, SemesterEvent::where('academic_term_id', $first->id)->where('action', 'automatic_end')->count());
        $this->assertDatabaseHas('semester_events', ['academic_term_id' => $second->id, 'action' => 'automatic_activation', 'actor_id' => null]);
        SemesterBackupValidator::check();
    }

    public function test_pending_leave_does_not_block_the_next_scheduled_semester_but_still_blocks_closing(): void
    {
        $first = $this->active();
        $second = $this->term(true);
        $service = app(SemesterManagementService::class);
        $service->transition($second, 'activate', '', $this->admin);
        $leave = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::firstOrFail()->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'status' => 'pending', 'reason' => 'Pending review']);
        $this->travelTo(Carbon::parse('2026-10-16 00:00', 'Asia/Manila'));
        app(SemesterLifecycleService::class)->synchronize();
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame('ended', $first->fresh()->status);
        $this->rejected(fn () => $service->transition($first, 'close', 'Finalize historical records', $this->admin));
        $leave->update(['status' => 'rejected']);
        $service->transition($first, 'close', 'Historical records reviewed', $this->admin);
        $this->assertSame('closed', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_saving_a_semester_is_enough_to_start_it_without_manual_activation(): void
    {
        $first = $this->active();
        $second = $this->term(true);
        $this->assertSame('scheduled', $second->status);
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame(0, SemesterEvent::where('action', 'schedule')->count());
        $this->travelTo(Carbon::parse('2026-10-16 00:00', 'Asia/Manila'));
        $this->artisan('semesters:sync')->assertSuccessful();
        $this->assertSame('ended', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame([], DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-19')));
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_existing_draft_semester_also_starts_automatically_when_its_date_arrives(): void
    {
        $first = $this->active();
        $second = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => 'Second Semester',
            'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'status' => 'draft']);
        $this->travelTo(Carbon::parse('2026-10-16 00:00', 'Asia/Manila'));
        $this->actingAs($this->admin)->get('/settings/semesters')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedTerm.id', $second->id)->where('selectedTerm.status', 'active'));
        $this->assertSame('ended', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_expired_scheduled_semester_does_not_activate_late_after_offline_period(): void
    {
        $first = $this->active();
        $second = $this->term(true);
        app(SemesterManagementService::class)->transition($second, 'activate', '', $this->admin);
        $this->travelTo(Carbon::parse('2026-11-01 12:00', 'Asia/Manila'));
        app(SemesterLifecycleService::class)->synchronize();
        $this->assertSame('ended', $first->fresh()->status);
        $this->assertSame('ended', $second->fresh()->status);
        $this->assertSame(0, AcademicTerm::where('status', 'active')->count());
        $this->assertSame(0, SemesterEvent::where('academic_term_id', $second->id)->where('action', 'automatic_activation')->count());
        $this->assertNull(SystemSetting::academicTerm()['id']);
    }

    public function test_semester_creation_uses_its_dates_and_preserves_admin_permissions(): void
    {
        $this->actingAs($this->admin)->post('/settings/semesters', ['school_year' => '2026-2028', 'semester' => 'First Semester',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-15'])->assertSessionHasErrors('semester');
        $term = $this->term();
        $this->assertSame('active', $term->status);
        $this->assertTrue(SemesterScheduleService::managed());
        $this->rejected(fn () => app(SemesterManagementService::class)->create(['school_year' => '2027-2028', 'semester' => 'First Semester',
            'start_date' => '2026-10-10', 'end_date' => '2026-10-20'], $this->admin));
        foreach (['faculty', 'guard'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'employee_id' => $role === 'faculty' ? $this->employee->id : null]))
                ->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'activate', 'reason' => 'Unauthorized activation'])->assertForbidden();
        }
        $this->assertSame('active', $term->fresh()->status);
    }

    public function test_semester_dates_must_stay_within_the_selected_academic_year(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20', 'Asia/Manila'));
        $data = ['school_year' => '2026-2027', 'semester' => 'First Semester',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-15'];
        $this->actingAs($this->admin)->post('/settings/semesters', array_replace($data, ['start_date' => '2025-12-31']))
            ->assertSessionHasErrors('start_date');
        $this->post('/settings/semesters', array_replace($data, ['end_date' => '2028-01-01']))
            ->assertSessionHasErrors('end_date');
        $this->assertDatabaseCount('academic_terms', 0);
        $term = $this->term();
        $this->put('/settings/semesters/'.$term->id, array_replace($data, ['school_year' => '2027-2028']))
            ->assertSessionHasErrors(['start_date', 'end_date']);
        $this->assertSame('2026-2027', $term->fresh()->school_year);
        $this->put('/settings/semesters/'.$term->id, array_replace($data, ['start_date' => '2026-12-31', 'end_date' => '2027-01-01']))
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-01-01', $term->fresh()->end_date->toDateString());
    }

    public function test_versions_preserve_expected_classes_and_paid_consultation_for_each_date(): void
    {
        $term = $this->active();
        $service = app(ClassReportService::class);
        $before = $service->build(['employee_id' => $this->employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
        $blocks = $this->blocks();
        $blocks[0]['start_time'] = '09:00';
        $blocks[0]['end_time'] = '11:00';
        $blocks[1]['start_time'] = '11:00';
        $blocks[1]['end_time'] = '12:00';
        $this->publish($term, $blocks, '2026-10-12');
        $this->assertSame($before['rows']->all(), $service->build(['employee_id' => $this->employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->all());
        $this->assertSame(28800, DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-05'))[0][0]);
        $this->assertSame(32400, DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-12'))[0][0]);
        $this->assertSame([], DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-19')));
        $this->assertSame(2, FacultyScheduleVersion::count());
        $this->rejected(fn () => $this->publish($term, $blocks, '2026-10-05'));
    }

    public function test_hardware_and_entrance_use_dated_versions_and_remain_independent(): void
    {
        $term = $this->active();
        $class = app(ClassAttendanceService::class)->record($this->employee, 'rfid', Carbon::parse('2026-10-05 08:00', 'Asia/Manila'));
        app(ClassAttendanceService::class)->record($this->employee, 'rfid', Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));
        $this->assertSame('IN', $class['action']);
        $this->assertSame($term->id, AttendanceLog::first()->academic_term_id);
        $this->assertDatabaseCount('entrance_attendances', 0);
        $entrance = app(EntranceAttendanceService::class);
        $entrance->record('SEM-CARD', 'IN', Carbon::parse('2026-10-05 08:00', 'Asia/Manila'), $this->admin->id);
        $entrance->record('SEM-CARD', 'OUT', Carbon::parse('2026-10-05 11:00', 'Asia/Manila'), $this->admin->id);
        $this->assertSame($term->id, EntranceAttendance::first()->academic_term_id);
        $this->assertSame(3.0, app(DtrService::class)->build($this->employee, '2026-10-05', '2026-10-05')['summary']['total_hours']);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_copy_is_only_a_draft_and_cannot_publish_an_invalid_next_semester_subject(): void
    {
        $first = $this->active();
        $next = $this->term(true);
        app(SemesterManagementService::class)->copy($next, $this->employee, (string) $first->id, $this->admin);
        $assignment = FacultyTermAssignment::where('academic_term_id', $next->id)->firstOrFail();
        $this->assertCount(3, $assignment->draft_blocks);
        $this->assertSame(0, FacultyScheduleVersion::where('academic_term_id', $next->id)->count());
        $this->rejected(fn () => $this->publish($next, $assignment->draft_blocks));
        $this->assertSame([], DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-19')));
        $this->assertSame('2026-10-15', $this->employee->fresh()->contract_end);
    }

    public function test_closing_freezes_profiles_exclusions_and_expected_sessions_and_reopen_does_not_activate_old_term(): void
    {
        $first = $this->active();
        $this->actingAs($this->admin);
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        app(SemesterManagementService::class)->transition($first, 'close', 'Semester completed and verified', $this->admin);
        $this->assertSame(15, SemesterDaySnapshot::count());
        $before = app(ClassReportService::class)->build(['employee_id' => $this->employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
        $this->employee->update(['first_name' => 'Changed']);
        $second = $this->term(true);
        app(SemesterManagementService::class)->transition($second, 'activate', 'New semester starts today', $this->admin);
        $this->assertSame([], DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-19')));
        $this->assertSame($before['rows']->all(), app(ClassReportService::class)->build(['employee_id' => $this->employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->all());
        $event = ['title' => 'Corrected suspension', 'type' => 'suspension', 'scope_type' => 'campus', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'is_paid' => false];
        $this->rejected(fn () => CalendarEvent::create($event));
        app(SemesterManagementService::class)->transition($first->fresh(), 'reopen', 'Correct historical suspension', $this->admin);
        CalendarEvent::create($event);
        $this->assertSame($second->id, AcademicTerm::where('status', 'active')->value('id'));
        $this->rejected(fn () => app(ClassAttendanceService::class)->record($this->employee, 'rfid', Carbon::parse('2026-10-05 08:00', 'Asia/Manila')));
        app(SemesterManagementService::class)->transition($first->fresh(), 'close', 'Historical correction verified', $this->admin);
        $report = app(ClassReportService::class)->build(['employee_id' => $this->employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
        $this->assertSame(1, $report['summary']['excluded']);
        $this->assertGreaterThan(0, SemesterEvent::where('action', 'record_added')->count());
    }

    public function test_pending_leave_prevents_closing_and_closed_generator_is_read_only(): void
    {
        $term = $this->active();
        $leave = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::firstOrFail()->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Review required', 'status' => 'pending']);
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $this->rejected(fn () => app(SemesterManagementService::class)->transition($term, 'close', 'Close semester', $this->admin));
        $leave->update(['status' => 'rejected']);
        app(SemesterManagementService::class)->transition($term, 'close', 'All reviews completed', $this->admin);
        config(['attendance_generator.enabled' => true]);
        $actor = User::factory()->create(['role' => 'generator', 'must_change_password' => false]);
        foreach (['class', 'school'] as $mode) {
            $result = app(AttendanceGenerationService::class)->generate(['mode' => $mode, 'cutoff_start' => '2026-10-01', 'employee_id' => $this->employee->id, 'overwrite' => true], 'semester-test', $actor);
            $this->assertSame(0, $result['created']);
            $this->assertSame(0, $result['updated']);
            $this->assertArrayHasKey('Closed semester', $result['reasons']);
        }
    }

    public function test_legacy_mapping_is_explicit_and_backup_includes_selected_faculty_semester_history(): void
    {
        $log = AttendanceLog::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-05', 'schedule_type' => 'class',
            'is_legacy' => false, 'scheduled_start' => '08:00', 'scheduled_end' => '10:00', 'time_in' => '08:00', 'time_out' => '10:00']);
        $term = $this->active();
        $this->assertNull($log->fresh()->academic_term_id);
        app(SemesterManagementService::class)->transition($term, 'map_legacy', 'Verified original semester dates', $this->admin);
        $this->assertSame($term->id, $log->fresh()->academic_term_id);
        $this->assertSame('08:00', $log->fresh()->time_in);
        $order = (new \ReflectionClass(BackupController::class))->getConstant('TABLES');
        $backup = app(SelectiveBackupService::class)->create(['attendance'], 'selected', [$this->employee->id], $order);
        $this->assertCount(1, $backup['tables']['academic_terms']);
        $this->assertCount(1, $backup['tables']['faculty_schedule_versions']);
        $this->assertSame(0, app(SelectiveBackupService::class)->restore($backup, $order));
    }

    public function test_history_pages_are_scoped_to_linked_faculty_and_include_archived_faculty_for_admin(): void
    {
        $term = $this->active();
        $other = Employee::create(['employee_no' => 'SEM-OTHER', 'first_name' => 'Other', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $other->id]);
        $this->actingAs($faculty)->get('/my-schedule?term_id='.$term->id)->assertInertia(fn (Assert $p) => $p->where('employee.id', $other->id)->has('employee.schedules', 0)->where('readOnly', true));
        $this->employee->forceFill(['archived_at' => now()])->save();
        $this->actingAs($this->admin)->get('/employees/'.$this->employee->id.'/schedule?term_id='.$term->id)->assertOk();
        $this->get('/class-reports?cutoff_start=2026-10-01&term_id='.$term->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.expected', 0)->has('report.rows', 0)
            ->where('employees', fn ($rows) => ! collect($rows)->contains('id', $this->employee->id)));
        $this->get('/class-reports?cutoff_start=2026-10-01')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.rows', 0));
        $this->get('/class-reports?cutoff_start=2026-10-01&term_id='.$term->id.'&employee_id='.$this->employee->id)
            ->assertInertia(fn (Assert $p) => $p->where('report.filters.term_id', (string) $term->id)->where('report.summary.expected', 2));
    }

    public function test_verified_legacy_semester_can_be_archived_without_fabricating_missing_schedules(): void
    {
        $legacySchedule = $this->employee->schedules()->create($this->blocks()[0]);
        $log = AttendanceLog::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-05',
            ...ClassAttendanceService::snapshot($legacySchedule), 'time_in' => '08:00', 'time_out' => '10:00']);
        $term = $this->term();
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $service = app(SemesterManagementService::class);
        $service->transition($term, 'map_legacy', 'Verified original semester dates', $this->admin);
        $service->transition($term, 'archive_legacy', 'Historical actual records verified', $this->admin);
        $this->assertSame('closed', $term->fresh()->status);
        $this->assertSame($term->id, $log->fresh()->academic_term_id);
        $this->assertSame([], DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-12')));
        $report = app(ClassReportService::class)->build(['term_id' => $term->id, 'employee_id' => $this->employee->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-15']);
        $this->assertSame(1, $report['summary']['expected']);
        $this->assertSame(0, $report['summary']['missed']);
        $this->assertDatabaseCount('faculty_schedules', 1);
        $this->assertSame(0, AcademicTerm::where('status', 'active')->count());
    }

    public function test_dtr_cutoff_resolves_two_semesters_and_preserves_closed_signatory_and_contract(): void
    {
        $service = app(SemesterManagementService::class);
        $first = $service->create(['school_year' => '2026-2027', 'semester' => 'First Semester',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-09'], $this->admin);
        $this->publish($first);
        $service->transition($first, 'activate', 'First semester verified', $this->admin);
        DtrSignatory::create(['name' => 'Original signatory', 'designation' => 'In Charge', 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-09']);
        $entrance = app(EntranceAttendanceService::class);
        foreach (['IN' => '08:00', 'OUT' => '11:00'] as $action => $time) {
            $entrance->record('SEM-CARD', $action, Carbon::parse('2026-10-05 '.$time, 'Asia/Manila'), $this->admin->id);
        }
        $this->travelTo(Carbon::parse('2026-10-10 08:00', 'Asia/Manila'));
        $service->transition($first, 'close', 'First semester complete', $this->admin);
        $second = $service->create(['school_year' => '2026-2027', 'semester' => 'Second Semester',
            'start_date' => '2026-10-10', 'end_date' => '2026-10-31'], $this->admin);
        $service->save($second, $this->employee, $this->blocks(true), ['contract_start' => '2026-10-10', 'contract_end' => '2026-10-31'],
            'publish', '2026-10-10', 'Verified new contract and schedule', $this->admin);
        $service->transition($second, 'activate', 'Second semester verified', $this->admin);
        $this->travelTo(Carbon::parse('2026-10-12 18:00', 'Asia/Manila'));
        foreach (['IN' => '08:00', 'OUT' => '11:00'] as $action => $time) {
            $entrance->record('SEM-CARD', $action, Carbon::parse('2026-10-12 '.$time, 'Asia/Manila'), $this->admin->id);
        }
        $dtr = app(DtrService::class)->build($this->employee, '2026-10-01', '2026-10-15');
        $this->assertSame(6.0, $dtr['summary']['total_hours']);
        $this->assertCount(2, $dtr['term_labels']);
        $this->assertSame($first->id, $dtr['rows']->firstWhere('date', '2026-10-05')['academic_term_id']);
        $this->assertSame($second->id, $dtr['rows']->firstWhere('date', '2026-10-12')['academic_term_id']);
        $historical = app(DtrService::class)->build($this->employee, '2026-10-01', '2026-10-09');
        $this->assertSame('ORIGINAL SIGNATORY', $historical['signatory']['name']);
        $this->assertSame('2026-10-15', $this->employee->fresh()->contract_end);
        $this->assertSame('2026-10-31', $dtr['employee']->contract_end);
    }

    public function test_admin_can_delete_upcoming_semester_and_its_preparation_without_touching_current_history(): void
    {
        $first = $this->active();
        $current = FacultyScheduleVersion::where('academic_term_id', $first->id)->get()->toArray();
        $future = $this->term(true);
        $this->publish($future, $this->blocks(true));
        $this->assertTrue(FacultyTermAssignment::where('academic_term_id', $future->id)->exists());
        $this->actingAs($this->admin)->delete('/settings/semesters/'.$future->id)
            ->assertRedirect('/settings/semesters')->assertSessionHasNoErrors();
        foreach (['academic_terms', 'faculty_schedule_versions', 'faculty_term_assignments', 'faculty_schedules', 'faculty_schedule_breaks', 'semester_events'] as $table) {
            $this->assertDatabaseMissing($table, [$table === 'academic_terms' ? 'id' : 'academic_term_id' => $future->id]);
        }
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame($current, FacultyScheduleVersion::where('academic_term_id', $first->id)->get()->toArray());
        SemesterBackupValidator::check();
    }

    public function test_semester_deletion_requires_admin_and_never_deletes_started_or_closed_terms(): void
    {
        $first = $this->active();
        $future = $this->term(true);
        foreach (['faculty', 'guard'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->delete('/settings/semesters/'.$future->id)->assertForbidden();
        }
        $this->actingAs($this->admin)->delete('/settings/semesters/'.$first->id)->assertSessionHasErrors('semester');
        $future->update(['status' => 'closed']);
        $this->delete('/settings/semesters/'.$future->id)->assertSessionHasErrors('semester');
        $future->update(['status' => 'scheduled']);
        $this->travelTo(Carbon::parse('2026-10-16 00:00', 'Asia/Manila'));
        $this->delete('/settings/semesters/'.$future->id)->assertSessionHasErrors('semester');
        $this->assertSame('active', $future->fresh()->status);
        $this->assertDatabaseCount('academic_terms', 2);
    }

    public function test_future_semester_with_attendance_or_snapshots_cannot_be_deleted(): void
    {
        $this->active();
        $future = $this->term(true);
        $this->publish($future, $this->blocks(true));
        foreach ([AttendanceLog::class, EntranceAttendance::class] as $model) {
            $record = $model::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-19',
                'time_in' => '08:00', 'time_out' => '10:00',
                ...($model === EntranceAttendance::class ? ['schedule_snapshot' => [[28800, 36000]]] : [])]);
            $this->actingAs($this->admin)->delete('/settings/semesters/'.$future->id)->assertSessionHasErrors('semester');
            $this->assertNotNull($record->fresh());
            $this->assertTrue(FacultyScheduleVersion::where('academic_term_id', $future->id)->exists());
            DB::table($record->getTable())->where('id', $record->id)->update(['academic_term_id' => null]);
            $this->delete('/settings/semesters/'.$future->id)->assertSessionHasErrors('semester');
            $record->delete();
        }
        SemesterDaySnapshot::create(['academic_term_id' => $future->id, 'employee_id' => $this->employee->id, 'date' => '2026-10-19', 'payload' => []]);
        $this->delete('/settings/semesters/'.$future->id)->assertSessionHasErrors('semester');
        $this->assertNotNull($future->fresh());
    }

    public function test_active_semester_end_date_is_editable_without_changing_existing_history(): void
    {
        $term = $this->active();
        $before = app(ClassReportService::class)->build(['employee_id' => $this->employee->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->all();
        $versions = FacultyScheduleVersion::all()->toArray();
        $this->actingAs($this->admin)->get('/settings/semesters?term_id='.$term->id)
            ->assertInertia(fn (Assert $page) => $page->where('startDateLocked', true));
        $data = ['school_year' => $term->school_year, 'semester' => $term->semester,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-20'];
        $this->put('/settings/semesters/'.$term->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $term->fresh()->status);
        $this->assertSame('2026-10-20', $term->fresh()->end_date->toDateString());
        $this->assertSame($versions, FacultyScheduleVersion::all()->toArray());
        $this->assertSame($before, app(ClassReportService::class)->build(['employee_id' => $this->employee->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->all());
        $this->put('/settings/semesters/'.$term->id, [...$data, 'start_date' => '2026-09-30'])->assertSessionHasErrors('semester');
        $this->assertSame('2026-10-01', $term->fresh()->start_date->toDateString());
        $this->assertDatabaseHas('semester_events', ['academic_term_id' => $term->id,
            'actor_id' => $this->admin->id, 'action' => 'details_updated']);
    }

    public function test_active_semester_with_no_recorded_basis_allows_start_date_correction(): void
    {
        $term = $this->term();
        $data = ['school_year' => $term->school_year, 'semester' => $term->semester,
            'start_date' => '2026-10-02', 'end_date' => '2026-10-15'];
        $this->actingAs($this->admin)->get('/settings/semesters?term_id='.$term->id)
            ->assertInertia(fn (Assert $page) => $page->where('startDateLocked', false));
        $this->put('/settings/semesters/'.$term->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-02', $term->fresh()->start_date->toDateString());
        $this->put('/settings/semesters/'.$term->id, [...$data, 'end_date' => '2026-10-04'])->assertSessionHasErrors('semester');
        $this->put('/settings/semesters/'.$term->id, [...$data, 'start_date' => '2026-10-06'])->assertSessionHasErrors('semester');
        $this->assertSame('active', $term->fresh()->status);
    }

    public function test_date_changes_cannot_orphan_class_or_school_logs_including_unassigned_records(): void
    {
        $term = $this->term();
        $service = app(SemesterManagementService::class);
        foreach ([AttendanceLog::class, EntranceAttendance::class] as $model) {
            $record = $model::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-14',
                'time_in' => '08:00', 'time_out' => '10:00',
                ...($model === EntranceAttendance::class ? ['schedule_snapshot' => [[28800, 36000]]] : [])]);
            $this->rejected(fn () => $service->update($term, ['school_year' => $term->school_year, 'semester' => $term->semester,
                'start_date' => '2026-10-01', 'end_date' => '2026-10-12'], $this->admin));
            DB::table($record->getTable())->where('id', $record->id)->update(['academic_term_id' => null]);
            $this->rejected(fn () => $service->update($term, ['school_year' => $term->school_year, 'semester' => $term->semester,
                'start_date' => '2026-10-01', 'end_date' => '2026-10-12'], $this->admin));
            $this->assertSame('2026-10-15', $term->fresh()->end_date->toDateString());
            $record->delete();
        }
    }

    public function test_upcoming_details_can_be_corrected_but_published_versions_cannot_be_mutated(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20', 'Asia/Manila'));
        $term = $this->term();
        $service = app(SemesterManagementService::class);
        $service->update($term, ['school_year' => '2026-2027', 'semester' => 'First Semester', 'start_date' => '2026-09-30', 'end_date' => '2026-10-15'], $this->admin);
        $this->assertSame('2026-09-30', $term->fresh()->start_date->toDateString());
        $this->publish($term->fresh());
        $block = $this->employee->schedules()->whereNotNull('academic_term_id')->firstOrFail();
        $this->rejected(fn () => $block->update(['start_time' => '09:00']));
        $this->rejected(fn () => $this->employee->delete());
    }

    public function test_semester_restore_rejects_conflicting_references_without_partial_import(): void
    {
        $this->active();
        $order = (new \ReflectionClass(BackupController::class))->getConstant('TABLES');
        $service = app(SelectiveBackupService::class);
        $backup = $service->create(['attendance'], 'selected', [$this->employee->id], $order);
        $backup['tables']['faculty_schedule_versions'][0]['academic_term_id'] = 999999;
        $before = FacultyScheduleVersion::firstOrFail()->toArray();
        $this->rejected(fn () => $service->restore($backup, $order));
        $this->assertSame($before, FacultyScheduleVersion::firstOrFail()->toArray());
        $this->assertDatabaseCount('academic_terms', 1);
    }

    public function test_semester_report_reuses_date_basis_without_querying_schedules_for_every_day(): void
    {
        $term = $this->active();
        SemesterScheduleService::reset();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(ClassReportService::class)->build(['term_id' => $term->id, 'employee_id' => $this->employee->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-15']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(2, $report['summary']['expected']);
        $this->assertLessThan(40, $count);
    }

    public function test_semesters_are_sorted_by_academic_year_then_first_second_and_midyear(): void
    {
        $midyear = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => 'Midyear Term', 'start_date' => '2027-06-14', 'end_date' => '2027-07-24']);
        $second = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => 'Second Semester', 'start_date' => '2027-01-11', 'end_date' => '2027-05-15']);
        $first = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => 'First Semester', 'start_date' => '2026-08-10', 'end_date' => '2026-12-12']);
        $newer = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => 'First Semester', 'start_date' => '2027-08-01', 'end_date' => '2027-12-01']);
        $ids = [$newer->id, $first->id, $second->id, $midyear->id];
        $this->assertSame($ids, AcademicTerm::inAcademicOrder()->pluck('id')->all());
        $this->actingAs($this->admin)->get('/settings/semesters')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('terms.0.id', $newer->id)->where('terms.1.id', $first->id)->where('terms.2.id', $second->id)->where('terms.3.id', $midyear->id)
            ->where('semesterOptions.0.id', $newer->id)->where('semesterOptions.1.id', $first->id)->where('semesterOptions.2.id', $second->id)->where('semesterOptions.3.id', $midyear->id));
    }

    public function test_legacy_activation_request_is_idempotent_and_corrections_still_require_a_reason(): void
    {
        $term = $this->term();
        $this->actingAs($this->admin)->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'activate'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $term->fresh()->status);
        $this->assertDatabaseHas('semester_events', ['academic_term_id' => $term->id, 'actor_id' => null,
            'action' => 'automatic_activation']);
        $this->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'reopen'])->assertSessionHasErrors('reason');
        $this->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'activate'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SemesterEvent::where('academic_term_id', $term->id)->where('action', 'automatic_activation')->count());
    }

    public function test_close_is_a_direct_action_and_reopen_retains_the_required_correction_reason(): void
    {
        $term = $this->active();
        $this->actingAs($this->admin)->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'close'])
            ->assertSessionHasErrors('semester');
        $this->assertSame('active', $term->fresh()->status);
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $this->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'close'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $term->fresh()->status);
        $this->assertDatabaseHas('semester_events', ['academic_term_id' => $term->id, 'actor_id' => $this->admin->id,
            'action' => 'close', 'reason' => 'Semester closed by administrator.']);
        foreach (['reopen', 'map_legacy', 'archive_legacy'] as $action) {
            $this->post('/settings/semesters/'.$term->id.'/transition', ['action' => $action])->assertSessionHasErrors('reason');
        }
        $this->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'reopen', 'reason' => 'Correct verified class schedule'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($term->fresh()->reopened);
        $this->assertSame('closed', $term->fresh()->status);
        $this->post('/settings/semesters/'.$term->id.'/transition', ['action' => 'close'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($term->fresh()->reopened);
    }

    public function test_bulk_publish_publishes_valid_drafts_and_reports_invalid_or_missing_schedules(): void
    {
        $term = $this->term();
        $service = app(SemesterManagementService::class);
        $service->save($term, $this->employee, $this->blocks(), [], 'draft', null, '', $this->admin);
        $bad = Employee::create(['employee_no' => 'BULK-BAD', 'first_name' => 'Invalid', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        $missing = Employee::create(['employee_no' => 'BULK-EMPTY', 'first_name' => 'Empty', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        $invalid = $this->blocks();
        $invalid[0]['subject'] = 'INVALID SUBJECT';
        $service->save($term, $bad, $invalid, [], 'draft', null, '', $this->admin);
        $this->actingAs($this->admin)->post('/settings/semesters/'.$term->id.'/publish-all')
            ->assertRedirect('/settings/semesters?term_id='.$term->id)->assertSessionHasNoErrors()
            ->assertSessionHas('semester_publish_result', fn ($result) => $result['published'] === 1
                && $result['no_draft'] === 1 && count($result['failed']) === 1 && $result['failed'][0]['employee_id'] === $bad->id);
        $this->assertSame(1, FacultyScheduleVersion::where('academic_term_id', $term->id)->count());
        $version = FacultyScheduleVersion::firstOrFail();
        $this->assertSame($this->employee->id, $version->employee_id);
        $this->assertSame('2026-10-01', $version->effective_from->toDateString());
        $this->assertSame($this->admin->id, $version->published_by);
        $this->assertDatabaseHas('semester_events', ['academic_term_id' => $term->id, 'employee_id' => $this->employee->id, 'action' => 'schedule_published']);
        $this->assertSame($invalid, FacultyTermAssignment::where('employee_id', $bad->id)->firstOrFail()->draft_blocks);
        $this->assertDatabaseMissing('faculty_term_assignments', ['academic_term_id' => $term->id, 'employee_id' => $missing->id]);
        $this->get('/settings/semesters?term_id='.$term->id)->assertInertia(fn (Assert $page) => $page
            ->where('publishResult.published', 1)->where('publishResult.failed.0.employee_id', $bad->id));
    }

    public function test_bulk_publish_is_repeatable_and_changed_schedules_preserve_old_versions(): void
    {
        $term = $this->active();
        $service = app(SemesterManagementService::class);
        $original = FacultyScheduleVersion::firstOrFail()->toArray();
        $result = $service->publishAll($term, $this->admin);
        $this->assertSame(0, $result['published']);
        $this->assertSame(1, $result['unchanged']);
        $blocks = $this->blocks();
        $blocks[0]['start_time'] = '08:30';
        $service->save($term, $this->employee, $blocks, [], 'draft', null, '', $this->admin);
        $this->assertSame(1, $service->publishAll($term, $this->admin)['published']);
        $this->assertSame(2, FacultyScheduleVersion::count());
        $this->assertSame($original, FacultyScheduleVersion::findOrFail($original['id'])->toArray());
        $new = FacultyScheduleVersion::orderByDesc('id')->firstOrFail();
        $this->assertSame('2026-10-06', $new->effective_from->toDateString());
        $this->assertSame('08:00', substr($this->employee->schedules()->where('schedule_version_id', $original['id'])->where('schedule_type', 'class')->firstOrFail()->start_time, 0, 5));
        $this->assertSame('08:30', substr($this->employee->schedules()->where('schedule_version_id', $new->id)->where('schedule_type', 'class')->firstOrFail()->start_time, 0, 5));
        $this->assertSame(1, $service->publishAll($term, $this->admin)['unchanged']);
        $this->assertSame(2, FacultyScheduleVersion::count());
    }

    public function test_publish_buttons_follow_real_changes_and_repeated_publication_never_duplicates_versions(): void
    {
        $term = $this->active();
        $service = app(SemesterManagementService::class);
        $url = '/employees/'.$this->employee->id.'/schedule';
        $this->actingAs($this->admin)->get('/settings/semesters?term_id='.$term->id)
            ->assertInertia(fn (Assert $page) => $page->where('faculty.0.needs_publication', false));
        $this->get($url.'?term_id='.$term->id)->assertInertia(fn (Assert $page) => $page->where('hasUnpublishedSchedule', false));
        $this->put($url, ['term_id' => $term->id, 'intent' => 'publish', 'blocks' => array_reverse($this->blocks())])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, FacultyScheduleVersion::count());
        $blocks = $this->blocks();
        $blocks[0]['start_time'] = '08:15';
        $service->save($term, $this->employee, $blocks, [], 'draft', null, '', $this->admin);
        $this->get('/settings/semesters?term_id='.$term->id)
            ->assertInertia(fn (Assert $page) => $page->where('faculty.0.needs_publication', true));
        $this->get($url.'?term_id='.$term->id)->assertInertia(fn (Assert $page) => $page->where('hasUnpublishedSchedule', true));
        $this->assertSame(1, $service->publishAll($term, $this->admin)['published']);
        $this->assertSame(1, $service->publishAll($term, $this->admin)['unchanged']);
        $this->put($url, ['term_id' => $term->id, 'intent' => 'publish', 'blocks' => $blocks])->assertSessionHasNoErrors();
        $this->assertSame(2, FacultyScheduleVersion::count());
        $this->get('/settings/semesters?term_id='.$term->id)
            ->assertInertia(fn (Assert $page) => $page->where('faculty.0.needs_publication', false));
        $this->get($url.'?term_id='.$term->id)->assertInertia(fn (Assert $page) => $page->where('hasUnpublishedSchedule', false));
    }

    public function test_closed_semesters_have_a_separate_paginated_catalog_and_academic_year_filter(): void
    {
        $active = $this->active();
        foreach (range(2020, 2022) as $year) {
            foreach ([['First Semester', "$year-08-01", "$year-12-01"],
                ['Second Semester', ($year + 1).'-01-01', ($year + 1).'-05-01'],
                ['Midyear Term', ($year + 1).'-06-01', ($year + 1).'-07-01']] as [$name, $start, $end]) {
                AcademicTerm::create(['school_year' => $year.'-'.($year + 1), 'semester' => $name,
                    'start_date' => $start, 'end_date' => $end, 'status' => 'closed']);
            }
        }
        $this->actingAs($this->admin)->get('/settings/semesters')->assertInertia(fn (Assert $page) => $page
            ->where('semesterFilters.view', 'current')->where('semesterListing.total', 1)->where('selectedTerm.id', $active->id));
        $this->get('/settings/semesters?view=closed')->assertInertia(fn (Assert $page) => $page
            ->where('semesterFilters.view', 'closed')->where('semesterListing.total', 9)->has('semesterListing.data', 6)
            ->where('semesterListing.last_page', 2)->where('selectedTerm.status', 'closed')
            ->where('semesterListing.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['status'] === 'closed')));
        $this->get('/settings/semesters?view=closed&semesters_page=2')->assertInertia(fn (Assert $page) => $page
            ->where('semesterListing.current_page', 2)->has('semesterListing.data', 3)->where('selectedTerm.school_year', '2020-2021'));
        $this->get('/settings/semesters?view=closed&academic_year=2021-2022')->assertInertia(fn (Assert $page) => $page
            ->where('semesterListing.total', 3)->where('selectedTerm.school_year', '2021-2022')
            ->where('semesterListing.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['school_year'] === '2021-2022')));
        $old = AcademicTerm::where('school_year', '2020-2021')->firstOrFail();
        $this->get('/settings/semesters?term_id='.$old->id)->assertInertia(fn (Assert $page) => $page
            ->where('semesterFilters.view', 'closed')->where('semesterListing.current_page', 2)->where('selectedTerm.id', $old->id));
        $this->assertDatabaseCount('academic_terms', 10);
    }

    public function test_recovery_maintenance_events_are_hidden_before_history_pagination_but_retained(): void
    {
        $term = $this->active();
        $reason = 'Recovered saved schedules for administrator-confirmed semester dates';
        foreach (range(1, 12) as $index) {
            SemesterEvent::create(['academic_term_id' => $term->id, 'actor_id' => $this->admin->id,
                'action' => 'schedule_published', 'reason' => $reason]);
        }
        $expected = SemesterEvent::where('academic_term_id', $term->id)->where('reason', '!=', $reason)->count();
        $this->actingAs($this->admin)->get('/settings/semesters?term_id='.$term->id.'&per_page=10')
            ->assertInertia(fn (Assert $page) => $page->where('history.total', $expected)
                ->where('history.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['reason'] !== $reason)));
        $this->assertSame(12, SemesterEvent::where('academic_term_id', $term->id)->where('reason', $reason)->count());
    }

    public function test_removing_all_blocks_is_a_real_publication_change_and_is_not_republished(): void
    {
        $term = $this->active();
        $service = app(SemesterManagementService::class);
        $service->save($term, $this->employee, [], [], 'draft', null, '', $this->admin);
        $this->actingAs($this->admin)->get('/settings/semesters?term_id='.$term->id)
            ->assertInertia(fn (Assert $page) => $page->where('faculty.0.needs_publication', true));
        $this->assertSame(1, $service->publishAll($term, $this->admin)['published']);
        $this->assertSame(1, $service->publishAll($term, $this->admin)['unchanged']);
        $this->assertSame(2, FacultyScheduleVersion::count());
        $this->assertNotEmpty(DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-05')));
        $this->assertSame([], DailyScheduleService::snapshot($this->employee, Carbon::parse('2026-10-12')));
    }

    public function test_bulk_publish_excludes_archived_or_inactive_faculty_and_blocks_closed_terms(): void
    {
        $term = $this->active();
        $service = app(SemesterManagementService::class);
        foreach (['inactive', 'archived'] as $state) {
            $faculty = Employee::create(['employee_no' => 'BULK-'.$state, 'first_name' => $state, 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
            $service->save($term, $faculty, $this->blocks(), [], 'draft', null, '', $this->admin);
            $faculty->forceFill($state === 'inactive' ? ['status' => 'inactive'] : ['archived_at' => now()])->save();
        }
        $this->assertSame(2, $service->publishAll($term, $this->admin)['ineligible']);
        $this->assertSame(1, FacultyScheduleVersion::count());
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $service->transition($term, 'close', 'End of semester', $this->admin);
        $this->actingAs($this->admin)->post('/settings/semesters/'.$term->id.'/publish-all')->assertSessionHasErrors('semester');
        $service->transition($term->fresh(), 'reopen', 'Review historical schedule', $this->admin);
        $this->post('/settings/semesters/'.$term->id.'/publish-all')->assertSessionHasErrors('semester');
        $this->assertSame(1, FacultyScheduleVersion::count());
        foreach (['faculty', 'guard', 'generator'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post('/settings/semesters/'.$term->id.'/publish-all')->assertForbidden();
        }
    }

    public function test_draft_semesters_are_restricted_to_admin_management_and_hidden_from_regular_choices(): void
    {
        $active = $this->active();
        $draft = $this->term(true);
        $draft->update(['status' => 'draft']); // Legacy backup fixture.
        $this->publish($draft, $this->blocks(true));
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $this->employee->id]);
        $this->actingAs($faculty)->get('/my-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('selectedSemester.id', $active->id)->has('semesterOptions', 1)->where('semesterOptions.0.id', $active->id));
        $this->get('/my-schedule?term_id='.$draft->id)->assertNotFound();
        $this->get('/attendance?term_id='.$draft->id)->assertSessionHasErrors('term_id');
        $this->get('/settings/semesters')->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/semesters?term_id='.$draft->id)->assertInertia(fn (Assert $page) => $page
            ->has('terms', 2)->has('semesterOptions', 2)->where('selectedTerm.id', $draft->id));
        $this->get('/employees/'.$this->employee->id.'/schedule?term_id='.$draft->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedSemester.id', $draft->id)->where('readOnly', false)->has('semesterOptions', 1)->where('semesterOptions.0.id', $active->id));
        $this->get('/entrance')->assertInertia(fn (Assert $page) => $page->has('semesterOptions', 1)->where('semesterOptions.0.id', $active->id));
        $this->get('/class-reports?term_id='.$draft->id)->assertSessionHasErrors('term_id');
    }

    public function test_my_schedule_defaults_to_own_published_history_and_never_returns_draft_profiles(): void
    {
        $first = $this->active();
        $service = app(SemesterManagementService::class);
        $service->save($first, $this->employee, $this->blocks(), ['position' => 'DRAFT ONLY'], 'draft', null, '', $this->admin);
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $this->employee->id]);
        $this->actingAs($faculty)->get('/my-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('assignmentProfile.position', 'Instructor')->where('selectedSemester.id', $first->id));
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $service->transition($first, 'close', 'Completed first semester', $this->admin);
        $second = $this->term(true);
        $service->transition($second, 'activate', 'Start second semester', $this->admin);
        $other = Employee::create(['employee_no' => 'VISIBLE-OTHER', 'first_name' => 'Other', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        $service->save($second, $other, $this->blocks(true), [], 'publish', '2026-10-16', '', $this->admin);
        $this->get('/my-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('selectedSemester.id', $first->id)->has('semesterOptions', 1)->where('semesterOptions.0.id', $first->id)
            ->where('employee.id', $this->employee->id));
        $this->publish($second, $this->blocks(true));
        $this->get('/my-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('selectedSemester.id', $second->id)->has('semesterOptions', 2)->where('readOnly', true));
        $empty = Employee::create(['employee_no' => 'VISIBLE-EMPTY', 'first_name' => 'No', 'last_name' => 'Schedule', 'status' => 'active', 'rate_amount' => 0]);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $empty->id]))->get('/my-schedule')->assertInertia(fn (Assert $page) => $page
            ->where('selectedSemester', null)->has('semesterOptions', 0)->has('employee.schedules', 0));
    }

    public function test_dashboard_ignores_old_semester_queries_for_both_roles_and_keeps_date_navigation(): void
    {
        $term = $this->active();
        $draft = $this->term(true);
        $draft->update(['status' => 'draft']); // Legacy backup fixture.
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $this->employee->id]);
        foreach ([$this->admin, $faculty] as $user) {
            $this->actingAs($user)->get('/?term_id='.$draft->id)->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')->missing('termSelection')->missing('semesterHistory')->where('scheduleView', null)->where('scheduleDate', '2026-10-05')
                ->has('semesterOptions', 1)->where('semesterOptions.0.id', $term->id));
        }
        $this->get('/?term_id='.$draft->id.'&schedule_date=2026-10-01')->assertInertia(fn (Assert $page) => $page
            ->where('scheduleView.date', '2026-10-01')->where('scheduleDate', '2026-10-05')->missing('termSelection'));
    }

    public function test_faculty_list_returns_dense_schedule_arrays_after_semester_recovery_and_version_changes(): void
    {
        $legacy = $this->employee->schedules()->create($this->blocks()[0]);
        $term = $this->active();
        $empty = Employee::create(['employee_no' => 'ZERO-LOAD', 'first_name' => 'No', 'last_name' => 'Z Schedule', 'status' => 'active', 'rate_amount' => 0]);
        $this->actingAs($this->admin)->get('/employees?sort=name&direction=asc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Index')->where('employees.data.0.id', $this->employee->id)->has('employees.data.0.schedules', 2)
            ->where('employees.data.0.schedules', fn ($rows) => array_is_list(collect($rows)->all()))
            ->where('employees.data.0.schedules.0.subject', 'DCIT 24')->where('employees.data.1.id', $empty->id)->has('employees.data.1.schedules', 0));
        $blocks = $this->blocks();
        $blocks[0]['start_time'] = '08:30';
        $this->publish($term, $blocks, '2026-10-06');
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'Asia/Manila'));
        $this->get('/employees?sort=name&direction=asc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('employees.data.0.schedules', fn ($rows) => array_is_list(collect($rows)->all()))
            ->has('employees.data.0.schedules', 2)->where('employees.data.0.schedules.0.start_time', fn ($time) => substr($time, 0, 5) === '08:30'));
        $this->assertDatabaseHas('faculty_schedules', ['id' => $legacy->id, 'academic_term_id' => null]);
    }

    public function test_schedule_effectivity_is_automatic_and_uses_semester_dates_without_rewriting_prior_days(): void
    {
        $term = $this->term();
        $this->actingAs($this->admin)->put('/employees/'.$this->employee->id.'/schedule', [
            'term_id' => $term->id, 'intent' => 'publish', 'blocks' => $this->blocks(),
        ])->assertSessionHasNoErrors();
        $first = FacultyScheduleVersion::firstOrFail();
        $this->assertSame('2026-10-01', $first->effective_from->toDateString());
        $original = $first->toArray();
        $blocks = $this->blocks();
        $blocks[0]['start_time'] = '08:30';
        $this->put('/employees/'.$this->employee->id.'/schedule', [
            'term_id' => $term->id, 'intent' => 'publish', 'blocks' => $blocks,
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-06', FacultyScheduleVersion::orderByDesc('id')->firstOrFail()->effective_from->toDateString());
        $this->assertSame($original, $first->fresh()->toArray());
        $this->assertSame(2, FacultyScheduleVersion::where('academic_term_id', $term->id)->count());
        $future = $this->term(true);
        foreach (['08:00', '08:30'] as $start) {
            $next = $this->blocks(true);
            $next[0]['start_time'] = $start;
            $this->put('/employees/'.$this->employee->id.'/schedule', [
                'term_id' => $future->id, 'intent' => 'publish', 'blocks' => $next,
            ])->assertSessionHasNoErrors();
            $this->assertSame('2026-10-16', FacultyScheduleVersion::orderByDesc('id')->firstOrFail()->effective_from->toDateString());
        }
    }

    public function test_schedule_rank_follows_the_faculty_profile_without_rewriting_old_publications(): void
    {
        $oldRank = FacultyRank::create(['name' => 'Previous faculty rank', 'rate_type' => 'hourly', 'rate_amount' => 0]);
        $newRank = FacultyRank::create(['name' => 'Updated faculty rank', 'rate_type' => 'hourly', 'rate_amount' => 0]);
        $this->employee->update(['faculty_rank_id' => $oldRank->id]);
        $term = $this->active();
        $original = FacultyScheduleVersion::firstOrFail();
        $this->assertSame($oldRank->id, $original->profile['faculty_rank_id']);
        $this->employee->update(['faculty_rank_id' => $newRank->id]);
        $this->actingAs($this->admin)->put('/employees/'.$this->employee->id.'/schedule', [
            'term_id' => $term->id, 'intent' => 'publish', 'blocks' => $this->blocks(),
            'assignment_profile' => ['faculty_rank_id' => $oldRank->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame($newRank->id, FacultyScheduleVersion::orderByDesc('id')->firstOrFail()->profile['faculty_rank_id']);
        $this->assertSame($oldRank->id, $original->fresh()->profile['faculty_rank_id']);
        $this->travelTo(Carbon::parse('2026-10-16 08:00', 'Asia/Manila'));
        $service = app(SemesterManagementService::class);
        $service->transition($term, 'close', 'Completed semester', $this->admin);
        $service->transition($term->fresh(), 'reopen', 'Correct historical schedule', $this->admin);
        $this->employee->update(['faculty_rank_id' => $oldRank->id]);
        $count = FacultyScheduleVersion::count();
        $this->put('/employees/'.$this->employee->id.'/schedule', [
            'term_id' => $term->id, 'intent' => 'publish', 'blocks' => $this->blocks(),
        ])->assertSessionHasErrors('semester');
        $this->assertSame($count, FacultyScheduleVersion::count());
        $this->put('/employees/'.$this->employee->id.'/schedule', [
            'term_id' => $term->id, 'intent' => 'publish', 'blocks' => $this->blocks(), 'reason' => 'Verified historical correction',
        ])->assertSessionHasNoErrors();
        $this->assertSame($newRank->id, FacultyScheduleVersion::orderByDesc('id')->firstOrFail()->profile['faculty_rank_id']);
    }

    public function test_semester_filters_constrain_school_class_report_and_dtr_cutoffs_to_applicable_months(): void
    {
        $term = app(SemesterManagementService::class)->create(['school_year' => '2026-2027', 'semester' => 'First Semester',
            'start_date' => '2026-08-10', 'end_date' => '2026-12-12'], $this->admin);
        $this->publish($term);
        app(SemesterManagementService::class)->transition($term, 'activate', 'Verified first semester', $this->admin);
        $this->actingAs($this->admin);
        foreach (['2026-05-01' => '2026-08-01', '2027-05-01' => '2026-12-01', '2026-10-16' => '2026-10-16'] as $requested => $expected) {
            foreach (['/attendance', '/entrance'] as $path) {
                $this->get($path.'?term_id='.$term->id.'&cutoff_start='.$requested)->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('filters.cutoff_start', $expected)->where('filters.term_id', (string) $term->id));
            }
            $this->get('/class-reports?term_id='.$term->id.'&cutoff_start='.$requested)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('report.filters.cutoff_start', $expected));
            $this->get('/attendance/dtr?employee_id='.$this->employee->id.'&term_id='.$term->id.'&start_date='.$requested)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('dtr.cutoff.start_date', $expected));
        }
        [$start, $end] = CutoffService::range('2026-08-01', (string) $term->id);
        $this->assertSame('2026-08-01', $start->toDateString());
        $this->assertSame('2026-08-15', $end->toDateString());
        [$unfiltered] = CutoffService::range('2026-05-01');
        $this->assertSame('2026-05-01', $unfiltered->toDateString());
    }

    public function test_general_settings_links_redirect_without_changing_semester_configuration(): void
    {
        $setting = SystemSetting::updateOrCreate(['key' => 'academic_term_mode'], ['value' => 'manual']);
        $this->actingAs($this->admin);
        $this->get('/settings/general')->assertRedirect('/settings/semesters');
        $this->put('/settings/general', ['mode' => 'automatic'])->assertRedirect('/settings/semesters');
        $this->assertSame('manual', $setting->fresh()->value);
        $this->assertDatabaseCount('academic_terms', 0);
        $this->get('/settings/semesters')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Semesters')->has('terms', 0)->where('selectedTerm', null));
    }
}

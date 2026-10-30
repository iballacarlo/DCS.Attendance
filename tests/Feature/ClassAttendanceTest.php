<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClassAttendanceService;
use App\Services\ClassReportService;
use App\Services\CutoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $number = 'CLASS-1'): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Class', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
    }

    private function block(Employee $employee, string $start = '08:00', string $end = '10:00', string $type = 'class')
    {
        return $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => $type, 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0, 'subject' => 'Programming', 'section' => 'BSCS-1A']);
    }

    private function tap(Employee $employee, string $time): array
    {
        return app(ClassAttendanceService::class)->record($employee, 'rfid', Carbon::parse('2026-10-05 '.$time, 'Asia/Manila'));
    }

    public function test_early_taps_and_late_departure_are_capped_to_class_without_penalty(): void
    {
        $employee = $this->employee();
        $this->block($employee);
        $this->assertSame('NO CLASS', $this->tap($employee, '07:29:59')['action']);
        $this->assertSame('IN', $this->tap($employee, '07:30')['action']);
        $this->assertSame('OUT', $this->tap($employee, '10:10')['action']);
        $this->assertEquals(2, AttendanceLog::first()->total_hours);
    }

    public function test_missing_out_stays_incomplete_when_next_class_starts(): void
    {
        $employee = $this->employee();
        $this->block($employee);
        $this->block($employee, '11:00', '12:00');
        $this->tap($employee, '08:00');
        $this->assertSame('IN', $this->tap($employee, '11:00')['action']);
        $this->tap($employee, '12:00');
        $old = AttendanceLog::orderBy('id')->first();
        $this->assertNull($old->time_out);
        $this->assertSame(0, $old->worked_seconds);
        // A subsequent stray tap must never close the abandoned morning class.
        $this->assertSame('NO CLASS', $this->tap($employee, '12:05')['action']);
        $this->assertNull($old->fresh()->time_out);
    }

    public function test_faculty_filters_are_applied_to_own_records_and_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));
        $employee = $this->employee();
        $other = $this->employee('OTHER');
        $this->block($employee);
        $this->block($other);
        $this->tap($employee, '08:00');
        $this->tap($employee, '10:00');
        $this->tap($other, '08:00');
        $user = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]);
        $this->actingAs($user)->get('/attendance?cutoff_start=2026-10-01&status=present&method=rfid')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.employee_id', $employee->id)->missing('dtr'));
        $this->get('/attendance?cutoff_start=2026-10-01&status=late_undertime')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
        $this->get('/attendance?cutoff_start=2026-10-16')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0)->missing('dtr'));
        $this->get('/attendance?cutoff_start=2026-10-02')->assertSessionHasErrors('cutoff_start');
    }

    public function test_faculty_schedule_is_own_and_read_only(): void
    {
        $employee = $this->employee();
        $other = $this->employee('OTHER');
        $this->block($employee);
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]);
        $this->actingAs($faculty)->get('/my-schedule?employee_id='.$other->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('employee.id', $employee->id)->where('readOnly', true)->where('employee.schedules.0.subject', 'Programming'));
        $this->get('/employees/'.$other->id.'/schedule')->assertForbidden();
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => []])->assertForbidden();
    }

    public function test_admin_preserves_adjacent_class_ids_and_rejects_overlap_and_missing_details(): void
    {
        $employee = $this->employee();
        $existing = $this->block($employee);
        $admin = User::factory()->create(['role' => 'admin']);
        $block = ['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21A', 'section' => 'BSIT 1A', 'class_mode' => 'lecture'];
        $next = [...$block, 'start_time' => '10:00', 'end_time' => '12:00'];
        $this->actingAs($admin)->put('/employees/'.$employee->id.'/schedule', ['blocks' => [['id' => $existing->id, ...$block], $next]])->assertSessionHasNoErrors();
        $this->assertSame(2, $employee->schedules()->count());
        $this->assertNotNull($existing->fresh());
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block, [...$next, 'start_time' => '09:30']]])->assertSessionHasErrors('blocks.1.start_time');
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [[...$block, 'section' => '']]])->assertSessionHasErrors('blocks.0.section');
    }

    public function test_all_schedule_types_are_limited_to_7_am_through_7_pm(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25', 'Asia/Manila'));
        $employee = $this->employee();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['class', 'consultation', 'lunch_break'] as $type) {
            $block = ['day_of_week' => 1, 'schedule_type' => $type, 'start_time' => '07:00', 'end_time' => '19:00', 'subject' => 'DCIT 21A', 'section' => 'BSIT 1A', 'class_mode' => 'lecture'];
            $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasNoErrors();
            foreach (['start_time' => '06:59', 'end_time' => '19:01'] as $field => $time) {
                $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [[...$block, $field => $time]]])->assertSessionHasErrors('blocks.0.'.$field);
            }
            $relation = $type === 'lunch_break' ? $employee->scheduleBreaks() : $employee->schedules();
            $this->assertSame(1, $relation->count());
            $this->assertSame('07:00', substr($relation->first()->start_time, 0, 5));
            $this->assertSame('19:00', substr($relation->first()->end_time, 0, 5));
        }
    }

    public function test_cutoff_service_handles_leap_year_and_month_end(): void
    {
        $this->assertSame('2024-02-29', CutoffService::range('2024-02-16')[1]->toDateString());
        $this->assertSame('2026-02-28', CutoffService::range('2026-02-16')[1]->toDateString());
        $this->assertSame('2026-10-15', CutoffService::range('2026-10-01')[1]->toDateString());
    }

    public function test_manual_attendance_is_unavailable_for_all_roles_and_preserves_existing_taps(): void
    {
        $employee = $this->employee();
        $schedule = $this->block($employee);
        $this->tap($employee, '08:15');
        $schedule->delete();
        foreach (['admin', 'payroll_staff', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'employee_id' => $role === 'faculty' ? $employee->id : null]))
                ->post('/attendance/manual', ['employee_id' => $employee->id, 'faculty_schedule_id' => $schedule->id, 'attendance_date' => '2026-10-05', 'time_in' => '08:15', 'time_out' => '09:45'])->assertNotFound();
            $this->post('/attendance/tap', ['identifier' => 'test-card', 'method' => 'rfid'])->assertNotFound();
        }
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertNull(AttendanceLog::first()->time_out);
        $this->assertEquals(0, AttendanceLog::first()->total_hours);
    }

    public function test_conversion_reports_unmatched_sources_and_incomplete_pairs_remain_unpaid(): void
    {
        $employee = $this->employee();
        $this->block($employee);
        AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '08:00']);
        AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-06', 'time_in' => '08:00', 'time_out' => '10:00']);
        $this->artisan('attendance:convert-classes')->expectsOutputToContain('No matching class schedule')->assertSuccessful();
        $this->assertSame(0, AttendanceLog::where('is_legacy', false)->first()->worked_seconds);
    }

    public function test_schedule_edit_cannot_create_overlapping_paid_snapshots(): void
    {
        $employee = $this->employee();
        $schedule = $this->block($employee);
        $this->tap($employee, '08:00');
        $this->tap($employee, '10:00');
        $schedule->update(['end_time' => '09:00']);
        $new = $this->block($employee, '09:00', '11:00');
        $this->assertSame('NO CLASS', $this->tap($employee, '10:10')['action']);
        $staff = User::factory()->create(['role' => 'admin']);
        $this->actingAs($staff)->post('/attendance/manual', ['employee_id' => $employee->id, 'faculty_schedule_id' => $new->id,
            'attendance_date' => '2026-10-05', 'time_in' => '09:00', 'time_out' => '11:00'])->assertNotFound();
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_dashboard_counts_faculty_instead_of_class_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));
        $employee = $this->employee();
        $this->block($employee);
        $this->block($employee, '10:00', '12:00');
        foreach (['08:00', '10:00', '10:00', '12:00'] as $time) {
            $this->tap($employee, $time);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('presentToday', 0)->where('classAnalytics.summary.complete', 2));
    }

    public function test_schedule_catalog_saves_laboratory_and_preserves_attendance_type(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/employees/'.$employee->id.'/schedule')->assertInertia(fn (Assert $page) => $page
            ->where('scheduleOptions.subjectsBySection.BSIT 1A', ['COSC 50A', 'CVSU 101', 'DCIT 21A', 'DCIT 22A', 'NSTP 1'])
            ->has('scheduleOptions.sections', 24)->where('scheduleOptions.sections.0', 'BSIT 1A')->where('scheduleOptions.sections.23', 'BSIT 4F'));
        $block = ['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21A', 'section' => 'BSIT 1A', 'class_mode' => 'laboratory'];
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasNoErrors();
        $schedule = $employee->schedules()->firstOrFail();
        $this->assertSame('laboratory', $schedule->class_mode);
        $this->tap($employee, '08:00');
        $this->tap($employee, '10:00');
        $schedule->update(['class_mode' => 'lecture']);
        $this->assertSame('laboratory', AttendanceLog::first()->class_mode);
        $session = app(ClassReportService::class)->build(['start_date' => '2026-10-05', 'end_date' => '2026-10-05'])['rows']->first();
        $this->assertSame('laboratory', $session['class_mode']);
        $this->assertSame('complete', $session['status']);
        foreach (['subject' => 'INVALID 1', 'section' => 'BSIT 5A', 'class_mode' => 'invalid'] as $field => $value) {
            $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [[...$block, $field => $value]]])->assertSessionHasErrors('blocks.0.'.$field);
        }
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [[...$block, 'class_mode' => null]]])->assertSessionHasErrors('blocks.0.class_mode');
    }

    public function test_saving_completed_class_details_preserves_other_unnamed_legacy_blocks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25', 'Asia/Manila'));
        $employee = $this->employee();
        $unnamed = $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => null, 'section' => null, 'class_mode' => null]);
        $edited = $employee->schedules()->create(['day_of_week' => 2, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => null, 'section' => null, 'class_mode' => null]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->put('/employees/'.$employee->id.'/schedule', ['blocks' => [
            ['id' => $unnamed->id, 'day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => '', 'section' => '', 'class_mode' => ''],
            ['id' => $edited->id, 'day_of_week' => 2, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 26', 'section' => 'BSIT 3B', 'class_mode' => 'lecture'],
        ]])->assertSessionHasNoErrors();
        $this->assertNull($unnamed->fresh()->subject);
        $this->assertNull($unnamed->fresh()->section);
        $this->assertSame('DCIT 26', $edited->fresh()->subject);
        $this->assertSame('BSIT 3B', $edited->fresh()->section);
        $this->assertSame(2, $employee->schedules()->count());
    }

    public function test_staff_and_faculty_attendance_lists_show_latest_date_and_latest_class_first(): void
    {
        $employee = $this->employee();
        $ids = [];
        foreach ([['07:00', '10:00'], ['10:00', '12:00'], ['13:00', '16:00']] as [$start, $end]) {
            $block = $this->block($employee, $start, $end);
            $log = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', ...ClassAttendanceService::snapshot($block), 'time_in' => $start, 'time_out' => $end]);
            $ids[] = $log->id;
        }
        $latest = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-06', ...ClassAttendanceService::snapshot($employee->schedules()->orderBy('start_time')->first()), 'time_in' => '07:00', 'time_out' => '10:00']);
        $expected = [$latest->id, ...array_reverse($ids)];
        foreach (['admin', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'employee_id' => $role === 'faculty' ? $employee->id : null]))
                ->get('/attendance?cutoff_start=2026-10-01')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('logs.data', fn ($logs) => collect($logs)->where('has_record', true)->pluck('id')->all() === $expected));
        }
    }

    public function test_admin_can_switch_faculty_from_schedule_page_without_exposing_options_to_faculty(): void
    {
        $first = $this->employee('NAV-1');
        $first->update(['last_name' => 'Alpha']);
        $second = $this->employee('NAV-2');
        $second->update(['last_name' => 'Bravo']);
        $archived = $this->employee('NAV-3');
        $archived->forceFill(['archived_at' => now()])->save();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/employees/'.$first->id.'/schedule?view=daily&day=3')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('facultyOptions', 2)
            ->where('facultyOptions.0.id', $first->id)->where('facultyOptions.1.id', $second->id));
        $this->get('/employees/'.$second->id.'/schedule?view=daily&day=3')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('employee.id', $second->id));
        $this->get('/employees/'.$archived->id.'/schedule')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $first->id]))
            ->get('/my-schedule')->assertOk()->assertInertia(fn (Assert $page) => $page->where('readOnly', true)->missing('facultyOptions'));
        $this->get('/employees/'.$second->id.'/schedule')->assertForbidden();
    }

    public function test_legacy_subject_and_section_can_be_retained_when_adding_class_type(): void
    {
        $employee = $this->employee();
        $schedule = $this->block($employee);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put('/employees/'.$employee->id.'/schedule', ['blocks' => [[
            'id' => $schedule->id, 'day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00',
            'subject' => 'Programming', 'section' => 'BSCS-1A', 'class_mode' => 'lecture',
        ]]])->assertSessionHasNoErrors();
        $this->assertSame('Programming', $schedule->fresh()->subject);
        $this->assertSame('lecture', $schedule->fresh()->class_mode);
    }
}

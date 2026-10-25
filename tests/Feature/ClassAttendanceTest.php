<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Services\ClassAttendanceService;
use App\Services\CutoffService;
use App\Services\DtrService;
use App\Services\PayrollComputationService;
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

    public function test_three_classes_have_six_taps_and_one_rate_times_hours_total(): void
    {
        $employee = $this->employee();
        foreach ([['08:00', '10:00'], ['10:00', '12:00'], ['14:00', '16:00']] as [$start, $end]) {
            $this->block($employee, $start, $end);
        }
        foreach (['08:00', '10:00', '10:00', '12:00', '14:00', '16:00'] as $i => $time) {
            $this->assertSame($i % 2 ? 'OUT' : 'IN', $this->tap($employee, $time)['action']);
        }
        $this->assertDatabaseCount('attendance_logs', 3);
        $pay = app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15');
        $this->assertSame(6.0, $pay['total_hours_worked']);
        $this->assertSame(600.0, $pay['gross_pay']);
        $this->assertSame(0, $pay['late_undertime_minutes']);
        $dtr = app(DtrService::class)->build($employee, '2026-10-05', '2026-10-06');
        $day = $dtr['rows'][0];
        $this->assertSame('08:00', $day['am_arrival']);
        $this->assertNull($day['am_departure']);
        $this->assertNull($day['pm_arrival']);
        $this->assertSame('16:00', $day['pm_departure']);
        $this->assertCount(3, $day['sessions']);
        $this->assertSame(6.0, $day['payable_hours']);
        foreach (['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'] as $column) {
            $this->assertNull($dtr['rows'][1][$column]);
        }
        $this->assertSame('NO CLASS', $this->tap($employee, '16:01')['action']);
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

    public function test_late_and_early_exit_are_actual_hours_without_a_second_deduction(): void
    {
        $employee = $this->employee();
        $this->block($employee);
        $this->tap($employee, '08:15');
        $this->tap($employee, '09:45');
        $log = AttendanceLog::first();
        $this->assertSame(5400, $log->worked_seconds);
        $this->assertEquals(1.5, $log->total_hours);
        $this->assertSame(0, $log->late_minutes + $log->undertime_minutes);
        $this->assertSame(150.0, app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15')['gross_pay']);
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

    public function test_consultation_is_paid_from_actual_taps_and_preserves_its_snapshot(): void
    {
        $employee = $this->employee();
        $consultation = $this->block($employee, '08:00', '10:00', 'consultation');
        $this->assertSame('IN', $this->tap($employee, '08:15')['action']);
        $this->assertSame('OUT', $this->tap($employee, '09:45')['action']);
        $consultation->delete();
        $log = AttendanceLog::firstOrFail();
        $this->assertSame('consultation', $log->schedule_type);
        $this->assertSame(5400, $log->worked_seconds);
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $this->assertSame('consultation', $dtr['rows'][4]['sessions'][0]['schedule_type']);
        $this->assertSame(1.5, $dtr['summary']['total_hours']);
        $this->assertSame(150.0, app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15')['gross_pay']);
    }

    public function test_snapshots_survive_schedule_changes_and_deletion(): void
    {
        $employee = $this->employee();
        $schedule = $this->block($employee);
        $this->tap($employee, '08:00');
        $this->tap($employee, '10:00');
        $schedule->update(['subject' => 'Changed', 'end_time' => '09:00']);
        $schedule->delete();
        $dtr = app(DtrService::class)->build($employee->fresh(), '2026-10-01', '2026-10-15');
        $session = $dtr['rows']->firstWhere('date', '2026-10-05')['sessions']->first();
        $this->assertSame('Programming', $session['subject']);
        $this->assertSame(2.0, $session['hours']);
        $this->assertSame(2.0, $dtr['summary']['total_hours']);
    }

    public function test_adjacent_class_and_consultation_need_separate_taps_and_breaks_are_unpaid(): void
    {
        $employee = $this->employee();
        $this->block($employee, '08:00', '10:00');
        $this->block($employee, '10:00', '11:00', 'consultation');
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '11:00', 'end_time' => '12:00']);
        foreach (['08:00', '10:00', '10:00', '11:00'] as $index => $time) {
            $this->assertSame($index % 2 ? 'OUT' : 'IN', $this->tap($employee, $time)['action']);
        }
        $this->assertSame('NO CLASS', $this->tap($employee, '11:01')['action']);
        $this->assertDatabaseCount('attendance_logs', 2);
        $dtr = app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15');
        $this->assertSame(3.0, $dtr['summary']['total_hours']);
        $this->assertSame(3.0, $dtr['form_rows'][4]['margin_hours']);
        $this->assertSame(300.0, app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15')['gross_pay']);
    }

    public function test_consultation_without_complete_taps_never_gets_automatic_hours(): void
    {
        $employee = $this->employee();
        $this->block($employee, '08:00', '10:00', 'consultation');
        $this->assertSame(0.0, app(DtrService::class)->build($employee, '2026-10-01', '2026-10-15')['summary']['total_hours']);
        $this->tap($employee, '08:00');
        $this->assertSame(0.0, app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15')['gross_pay']);
        $this->assertNull(AttendanceLog::first()->time_out);
    }

    public function test_hardware_consultation_taps_are_payable_and_retries_are_idempotent(): void
    {
        $employee = $this->employee();
        $this->block($employee, '08:00', '10:00', 'consultation');
        $employee->rfidCards()->create(['rfid_uid' => 'CONSULT-CARD', 'status' => 'active']);
        config(['services.hardware.api_key' => 'consultation-key']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));
        $payload = ['identifier' => 'CONSULT-CARD', 'method' => 'rfid', 'event_id' => 'consult-in', 'occurred_at' => '2026-10-05 08:15:00'];
        $this->withHeader('X-Hardware-Key', 'consultation-key')->postJson('/api/hardware/tap', $payload)->assertOk()->assertJsonPath('action', 'IN');
        $this->postJson('/api/hardware/tap', $payload)->assertOk()->assertJsonPath('action', 'IN');
        $this->postJson('/api/hardware/tap', [...$payload, 'event_id' => 'consult-out', 'occurred_at' => '2026-10-05 09:45:00'])->assertOk()->assertJsonPath('action', 'OUT');
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame('consultation', AttendanceLog::first()->schedule_type);
        $this->assertSame(150.0, app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15')['gross_pay']);
    }

    public function test_seconds_are_summed_before_pay_is_rounded(): void
    {
        $employee = $this->employee();
        $employee->update(['rate_amount' => 3600]);
        $this->block($employee);
        $this->tap($employee, '08:00:01');
        $this->tap($employee, '09:00:02');
        $this->assertSame(3601.0, app(PayrollComputationService::class)->calculate($employee, '2026-10-01', '2026-10-15')['gross_pay']);
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
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.employee_id', $employee->id)->where('dtr.summary.total_hours', 2));
        $this->get('/attendance?cutoff_start=2026-10-01&status=late_undertime')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
        $this->get('/attendance?cutoff_start=2026-10-16')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0)->where('dtr.summary.total_hours', 0));
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

    public function test_conversion_is_previewable_idempotent_and_preserves_originals_and_adjustments(): void
    {
        $employee = $this->employee();
        $this->block($employee);
        $this->block($employee, '11:00', '12:00');
        $source = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '08:15', 'time_out' => '12:00', 'total_hours' => 3.75]);
        $period = PayrollPeriod::create(['period_name' => 'Test', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'status' => 'processing']);
        $record = PayrollRecord::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'status' => 'draft', 'gross_pay' => 999, 'overtime_pay' => 25, 'other_earnings' => 10, 'total_adjustments' => 35, 'total_deductions' => 5]);
        $this->artisan('attendance:convert-classes', ['--preview' => true])->assertSuccessful();
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertEquals(999, $record->fresh()->gross_pay);
        $this->artisan('attendance:convert-classes')->assertSuccessful();
        $this->assertDatabaseCount('attendance_logs', 3);
        $this->assertEquals(275, $record->fresh()->gross_pay);
        $this->assertEquals(305, $record->fresh()->net_pay);
        $this->assertEquals(35, $record->fresh()->total_adjustments);
        $this->assertEquals(3.75, $source->fresh()->total_hours);
        $this->assertSame(2, AttendanceLog::where('legacy_source_id', $source->id)->count());
        $this->artisan('attendance:convert-classes')->assertSuccessful();
        $this->assertDatabaseCount('attendance_logs', 3);
    }

    public function test_conversion_preserves_released_and_finalized_payroll(): void
    {
        $employee = $this->employee();
        $this->block($employee);
        AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '08:00', 'time_out' => '10:00']);
        $period = PayrollPeriod::create(['period_name' => 'Test', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'status' => 'finalized']);
        $record = PayrollRecord::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'status' => 'released', 'gross_pay' => 999, 'net_pay' => 999]);
        $this->artisan('attendance:convert-classes')->assertSuccessful();
        $this->assertEquals(999, $record->fresh()->gross_pay);
        $this->assertEquals(999, $record->fresh()->net_pay);
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
        $staff = User::factory()->create(['role' => 'payroll_staff']);
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
            ->assertInertia(fn (Assert $page) => $page->where('presentToday', 1)->has('attendanceTrend', 6)->where('attendanceTrend.5.count', 1));
    }

    public function test_generator_creates_separate_class_sessions_and_preserves_fill_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 18:00', 'Asia/Manila'));
        $employee = $this->employee();
        $this->block($employee);
        $this->block($employee, '10:00', '12:00');
        $this->block($employee, '13:00', '14:00', 'consultation');
        $staff = User::factory()->create(['role' => 'payroll_staff']);
        $data = ['target_mode' => 'selected', 'employee_ids' => [$employee->id], 'generation_mode' => 'fill_missing', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15'];
        $this->actingAs($staff)->post('/attendance/generate-test', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_logs', 3);
        foreach (AttendanceLog::all() as $log) {
            $this->assertNotNull($log->time_in);
            $this->assertNotNull($log->time_out);
            $this->assertSame('present', $log->status);
            $this->assertGreaterThan(0, $log->worked_seconds);
        }
        $before = AttendanceLog::orderBy('id')->get()->toArray();
        $this->post('/attendance/generate-test', $data)->assertSessionHasNoErrors();
        $this->assertSame($before, AttendanceLog::orderBy('id')->get()->toArray());
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
        $session = app(DtrService::class)->build($employee->fresh(), '2026-10-01', '2026-10-15')['rows']->firstWhere('date', '2026-10-05')['sessions']->first();
        $this->assertSame('laboratory', $session['class_mode']);
        $this->assertSame(2.0, $session['hours']);
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
        foreach (['payroll_staff', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'employee_id' => $role === 'faculty' ? $employee->id : null]))
                ->get('/attendance?cutoff_start=2026-10-01')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('logs.data', fn ($logs) => collect($logs)->pluck('id')->all() === $expected));
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

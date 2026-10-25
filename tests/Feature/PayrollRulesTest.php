<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\FacultySchedule;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Services\AttendanceCalculator;
use App\Services\ClassAttendanceService;
use App\Services\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayrollRulesTest extends TestCase
{
    use RefreshDatabase, \Tests\CreatesClassSessions;

    public function test_one_minute_late_deducts_only_one_minute(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $log = $this->classLog([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-21',
            'time_in' => '08:01',
            'time_out' => '17:00',
        ]);

        AttendanceCalculator::recalculate($log);

        $log->refresh();
        $this->assertSame(0, $log->late_minutes);
        $this->assertSame(0, $log->undertime_minutes);
        $this->assertEqualsWithDelta(7.9833, (float) $log->total_hours, 0.0001);
    }

    public function test_late_and_undertime_are_minute_accurate(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $log = $this->classLog([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-21',
            'time_in' => '08:01',
            'time_out' => '15:59',
        ]);

        AttendanceCalculator::recalculate($log);

        $log->refresh();
        $this->assertSame(0, $log->late_minutes);
        $this->assertSame(0, $log->undertime_minutes);
        $this->assertEqualsWithDelta(7.9667, (float) $log->total_hours, 0.0001);
    }

    public function test_lunch_and_schedule_gaps_are_not_payable(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $employee->schedules()->delete();
        $service = app(ClassAttendanceService::class);
        foreach ([['08:00', '11:30'], ['13:00', '15:00'], ['16:00', '17:00']] as [$start, $end]) {
            $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0]);
            $service->record($employee, 'rfid', Carbon::parse('2026-09-21 '.$start, 'Asia/Manila'));
            $service->record($employee, 'rfid', Carbon::parse('2026-09-21 '.$end, 'Asia/Manila'));
        }
        $dtr = app(DtrService::class)->build($employee, '2026-09-16', '2026-09-30');
        $this->assertEquals(6.5, $dtr['summary']['total_hours']);
        $this->assertEquals(6.5, AttendanceCalculator::scheduledHoursForDay($employee, '2026-09-21'));
    }

    public function test_payroll_period_sets_pay_date_from_cutoff(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']));

        $response = $this->post(route('payroll.periods.store'), [
            'period_name' => '09/16/2026 - 09/30/2026',
            'start_date' => '2026-09-16',
            'end_date' => '2026-09-30',
            'pay_date' => '2026-09-30',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payroll_periods', [
            'period_name' => '09/16/2026 - 09/30/2026',
            'pay_date' => '2026-10-10',
        ]);
    }

    public function test_payroll_period_rejects_dates_outside_allowed_cutoffs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']));

        $response = $this->from(route('payroll.index'))->post(route('payroll.periods.store'), [
            'period_name' => 'Bad cutoff',
            'start_date' => '2026-09-05',
            'end_date' => '2026-09-20',
        ]);

        $response->assertRedirect(route('payroll.index'));
        $response->assertSessionHasErrors(['start_date', 'end_date']);
        $this->assertSame(0, PayrollPeriod::count());
    }

    public function test_payroll_uses_employee_rate_instead_of_rank_rate(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $employee->update([
            'faculty_rank_id' => FacultyRank::where('is_active', true)->firstOrFail()->id,
            'rate_amount' => 100,
        ]);
        $this->classLog([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-07',
            'total_hours' => 8,
            'status' => 'present',
        ]);
        $period = PayrollPeriod::create([
            'period_name' => '09/01/2026 - 09/15/2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'status' => 'open',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))
            ->post(route('payroll.generate', $period))
            ->assertSessionHasNoErrors();

        $record = PayrollRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(800.0, (float) $record->gross_pay);
        $this->assertSame(800.0, (float) $record->total_earnings);
    }

    public function test_payroll_always_multiplies_hourly_rate_by_actual_hours(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $employee->update(['rate_type' => 'hourly', 'rate_amount' => 100]);
        $log = $this->classLog([
            'employee_id' => $employee->id, 'attendance_date' => '2026-09-07',
            'time_in' => '08:01', 'time_out' => '17:00',
        ]);
        AttendanceCalculator::recalculate($log);
        $period = PayrollPeriod::create([
            'period_name' => '09/01/2026 - 09/15/2026', 'start_date' => '2026-09-01',
            'end_date' => '2026-09-15', 'status' => 'open',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))
            ->post(route('payroll.generate', $period))->assertSessionHasNoErrors();

        $this->assertSame(798.33, (float) PayrollRecord::where('employee_id', $employee->id)->firstOrFail()->gross_pay);
    }

    public function test_payroll_generation_records_late_undertime_and_absences_for_payslip(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $this->classLog([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-07',
            'late_minutes' => 12,
            'undertime_minutes' => 18,
            'total_hours' => 6,
            'status' => 'late',
        ]);
        $period = PayrollPeriod::create([
            'period_name' => '09/01/2026 - 09/15/2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'status' => 'open',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))
            ->post(route('payroll.generate', $period))
            ->assertSessionHasNoErrors();

        $record = PayrollRecord::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(0, $record->late_undertime_minutes);
        $this->assertSame(1.0, (float) $record->absent_days);
    }

    public function test_authorized_staff_can_update_payslip_breakdown_and_totals(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $period = PayrollPeriod::create([
            'period_name' => '09/01/2026 - 09/15/2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'status' => 'processing',
        ]);
        $record = PayrollRecord::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'gross_pay' => 10000,
            'total_earnings' => 10000,
            'net_pay' => 10000,
        ]);
        $breakdown = [
            'overtime_pay' => 500,
            'other_earnings' => 100,
            'increase_amount' => 400,
            'withholding_tax' => 350,
            'gsis_deduction' => 450,
            'philhealth_deduction' => 200,
            'pag_ibig_deduction' => 100,
            'multi_purpose_loan' => 300,
            'gsis_loan' => 200,
            'gsis_eplus_loan' => 100,
            'fea_dues' => 50,
            'oba_deduction' => 25,
            'cra_deduction' => 25,
        ];

        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))
            ->put(route('payroll.records.update', $record), $breakdown)
            ->assertRedirect(route('payroll.records.show', $record))
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(11000.0, (float) $record->total_earnings);
        $this->assertSame(1800.0, (float) $record->total_deductions);
        $this->assertSame(9200.0, (float) $record->net_pay);
    }

    public function test_authorized_staff_can_bulk_approve_and_release_one_payroll_period(): void
    {
        $firstEmployee = $this->employeeWithMondaySchedule();
        $secondEmployee = Employee::create([
            'employee_no' => 'EMP-002',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'rate_type' => 'hourly',
            'rate_amount' => 100,
            'status' => 'active',
        ]);
        $period = PayrollPeriod::create([
            'period_name' => '09/01/2026 - 09/15/2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'status' => 'processing',
        ]);
        $draft = PayrollRecord::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $firstEmployee->id,
            'gross_pay' => 1000,
            'total_earnings' => 1000,
            'net_pay' => 1000,
            'status' => 'draft',
        ]);
        $approved = PayrollRecord::create([
            'payroll_period_id' => $period->id,
            'employee_id' => $secondEmployee->id,
            'gross_pay' => 1000,
            'total_earnings' => 1000,
            'net_pay' => 1000,
            'status' => 'approved',
        ]);
        $staff = User::factory()->create(['role' => 'payroll_staff']);

        $this->actingAs($staff)->post(route('payroll.records.bulk-status'), [
            'record_ids' => [$draft->id, $approved->id],
            'action' => 'approve',
        ])->assertSessionHasNoErrors();

        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertSame('approved', $approved->fresh()->status);

        $this->actingAs($staff)->post(route('payroll.records.bulk-status'), [
            'record_ids' => [$draft->id, $approved->id],
            'action' => 'release',
        ])->assertSessionHasNoErrors();

        $this->assertSame('released', $draft->fresh()->status);
        $this->assertSame('released', $approved->fresh()->status);
        $this->assertSame('finalized', $period->fresh()->status);
    }

    public function test_bulk_payroll_action_rejects_records_from_different_periods(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $otherEmployee = Employee::create([
            'employee_no' => 'EMP-002', 'first_name' => 'Grace', 'last_name' => 'Hopper',
            'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active',
        ]);
        $firstPeriod = PayrollPeriod::create([
            'period_name' => '09/01/2026 - 09/15/2026', 'start_date' => '2026-09-01',
            'end_date' => '2026-09-15', 'status' => 'processing',
        ]);
        $secondPeriod = PayrollPeriod::create([
            'period_name' => '09/16/2026 - 09/30/2026', 'start_date' => '2026-09-16',
            'end_date' => '2026-09-30', 'status' => 'processing',
        ]);
        $first = PayrollRecord::create([
            'payroll_period_id' => $firstPeriod->id, 'employee_id' => $employee->id,
            'gross_pay' => 1000, 'total_earnings' => 1000, 'net_pay' => 1000, 'status' => 'draft',
        ]);
        $second = PayrollRecord::create([
            'payroll_period_id' => $secondPeriod->id, 'employee_id' => $otherEmployee->id,
            'gross_pay' => 1000, 'total_earnings' => 1000, 'net_pay' => 1000, 'status' => 'draft',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'payroll_staff']))
            ->post(route('payroll.records.bulk-status'), [
                'record_ids' => [$first->id, $second->id],
                'action' => 'approve',
            ])
            ->assertSessionHasErrors('record_ids');

        $this->assertSame('draft', $first->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
    }

    public function test_admin_can_create_payroll_periods(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('payroll.periods.store'), [
                'period_name' => '09/01/2026 - 09/15/2026',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-15',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payroll_periods', [
            'period_name' => '09/01/2026 - 09/15/2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
        ]);
    }

    public function test_admin_cannot_create_manual_attendance(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/attendance/manual', [])
            ->assertNotFound();
    }

    public function test_faculty_dashboard_only_links_to_own_released_payslips(): void
    {
        $employee = $this->employeeWithMondaySchedule();
        $otherEmployee = Employee::create(['employee_no' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
        $records = [];
        foreach (['released', 'approved', 'draft'] as $index => $status) {
            $period = PayrollPeriod::create(['period_name' => $status, 'start_date' => '2026-0'.($index + 1).'-01', 'end_date' => '2026-0'.($index + 1).'-15', 'status' => 'processing']);
            $records[$status] = PayrollRecord::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'status' => $status]);
        }
        $otherRecord = PayrollRecord::create(['employee_id' => $otherEmployee->id, 'payroll_period_id' => $records['released']->payroll_period_id, 'status' => 'released']);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]));
        $this->get('/')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Dashboard')->has('latestPayrolls', 1)->where('latestPayrolls.0.id', $records['released']->id));
        $this->get(route('payroll.records.show', $records['released']))->assertOk();
        foreach ([$records['draft'], $records['approved'], $otherRecord] as $record) {
            $this->get(route('payroll.records.show', $record))->assertForbidden();
        }
        $records['released']->update(['status' => 'approved']);
        $this->get('/')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('latestPayrolls', 0));
    }

    private function employeeWithMondaySchedule(): Employee
    {
        $employee = Employee::create([
            'employee_no' => 'EMP-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'rate_type' => 'hourly',
            'rate_amount' => 100,
            'status' => 'active',
        ]);

        FacultySchedule::create([
            'employee_id' => $employee->id,
            'day_of_week' => 1,
            'schedule_type' => 'class',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'break_minutes' => 0,
        ]);

        return $employee;
    }
}

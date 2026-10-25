<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Services\ClassAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SelectiveBackupTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Backup', 'last_name' => $number,
            'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
    }

    private function schedule(Employee $employee): FacultySchedule
    {
        return $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00',
            'end_time' => '10:00', 'break_minutes' => 0, 'subject' => 'DCIT 26', 'section' => 'BSIT 3A', 'class_mode' => 'lecture']);
    }

    private function download(array $groups, array $ids = [], string $scope = 'selected'): array
    {
        return json_decode($this->get('/settings/backup/download?'.http_build_query(['mode' => 'custom', 'groups' => $groups,
            'faculty_scope' => $scope, ...($ids ? ['employee_ids' => $ids] : [])]))->assertOk()->streamedContent(), true);
    }

    private function file(array $backup): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('backup.json', json_encode($backup));
    }

    public function test_selected_schedule_backup_contains_only_chosen_faculty_and_required_dependencies(): void
    {
        $first = $this->faculty('ONE');
        $second = $this->faculty('TWO');
        $archived = $this->faculty('ARCHIVED');
        $archived->forceFill(['archived_at' => now()])->save();
        $block = $this->schedule($first);
        $this->schedule($second);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/settings/backup')->assertOk()->assertInertia(fn (Assert $page) => $page->has('backupGroups', 10)->has('facultyChoices', 2));
        $backup = $this->download(['schedules'], [$first->id]);
        $this->assertSame(2, $backup['version']);
        $this->assertSame([$first->id], array_column($backup['tables']['employees'], 'id'));
        $this->assertSame([$block->id], array_column($backup['tables']['faculty_schedules'], 'id'));
        $this->assertEqualsCanonicalizing(['employees', 'faculty_ranks', 'faculty_schedules', 'faculty_schedule_breaks'], array_keys($backup['tables']));
        $this->assertArrayNotHasKey('users', $backup['tables']);
        $this->assertArrayNotHasKey('attendance_logs', $backup['tables']);
        $multiple = $this->download(['schedules'], [$first->id, $second->id]);
        $this->assertSame([$first->id, $second->id], array_column($multiple['tables']['employees'], 'id'));
        $all = $this->download(['faculty'], scope: 'all');
        $this->assertCount(3, $all['tables']['employees']);
        $this->assertNotNull($all['tables']['employees'][2]['archived_at']);
        $settings = $this->download(['settings'], scope: 'all');
        $this->assertEqualsCanonicalizing(['system_settings', 'dtr_signatories'], array_keys($settings['tables']));
        $this->assertArrayNotHasKey('employees', $settings['tables']);
        $this->post('/settings/backup/restore', ['backup_file' => $this->file($settings)])->assertSessionHasNoErrors();
    }

    public function test_attendance_and_payroll_exports_filter_records_and_keep_reference_data(): void
    {
        $first = $this->faculty('ONE');
        $other = $this->faculty('OTHER');
        $period = PayrollPeriod::create(['period_name' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'status' => 'processing']);
        foreach ([$first, $other] as $employee) {
            $block = $this->schedule($employee);
            AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($block), 'attendance_date' => '2026-10-05', 'time_in' => '08:00', 'time_out' => '10:00']);
            AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-04', 'is_legacy' => true, 'time_in' => '08:00']);
            PayrollRecord::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'status' => 'approved', 'other_earnings' => 77]);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $backup = $this->download(['attendance', 'payroll'], [$first->id]);
        $this->assertCount(2, $backup['tables']['attendance_logs']);
        $this->assertSame([$first->id], array_values(array_unique(array_column($backup['tables']['attendance_logs'], 'employee_id'))));
        $this->assertCount(1, $backup['tables']['faculty_schedules']);
        $this->assertCount(1, $backup['tables']['payroll_records']);
        $this->assertEquals(77, $backup['tables']['payroll_records'][0]['other_earnings']);
        $this->assertSame([$period->id], array_column($backup['tables']['payroll_periods'], 'id'));
    }

    public function test_scoped_accounts_and_calendar_do_not_pull_other_faculty_or_admin_accounts(): void
    {
        $first = $this->faculty('ONE');
        $other = $this->faculty('OTHER');
        $admin = User::factory()->create(['role' => 'admin']);
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $first->id]);
        User::factory()->create(['role' => 'faculty', 'employee_id' => $other->id]);
        $event = CalendarEvent::create(['title' => 'Shared activity', 'type' => 'official_school_activity', 'scope_type' => 'faculty',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'created_by' => $admin->id, 'is_paid' => false]);
        DB::table('calendar_event_employee')->insert([['calendar_event_id' => $event->id, 'employee_id' => $first->id], ['calendar_event_id' => $event->id, 'employee_id' => $other->id]]);
        $type = LeaveType::firstOrFail();
        LeaveRequest::create(['employee_id' => $first->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'reason' => 'Personal', 'status' => 'approved', 'reviewed_by' => $admin->id]);
        $this->actingAs($admin);
        $backup = $this->download(['accounts', 'calendar', 'leave'], [$first->id]);
        $this->assertSame([$faculty->id], array_column($backup['tables']['users'], 'id'));
        $this->assertSame([$first->id], array_column($backup['tables']['calendar_event_employee'], 'employee_id'));
        $this->assertNull($backup['tables']['calendar_events'][0]['created_by']);
        $this->assertNull($backup['tables']['leave_requests'][0]['reviewed_by']);
        $this->assertCount(1, $backup['tables']['leave_types']);
    }

    public function test_custom_recovery_adds_missing_schedules_and_keeps_existing_records_on_repeated_restore(): void
    {
        $first = $this->faculty('ONE');
        $other = $this->faculty('OTHER');
        $block = $this->schedule($first);
        $otherBlock = $this->schedule($other);
        $period = PayrollPeriod::create(['period_name' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'status' => 'processing']);
        $record = PayrollRecord::create(['employee_id' => $first->id, 'payroll_period_id' => $period->id, 'status' => 'approved', 'other_earnings' => 77]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $backup = $this->download(['schedules', 'payroll'], [$first->id]);
        $block->delete();
        $first->update(['rate_amount' => 250]);
        $otherBlock->update(['subject' => 'KEEP ME']);
        $record->update(['status' => 'released', 'other_earnings' => 99]);
        $this->post('/settings/backup/restore', ['backup_file' => $this->file($backup)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('faculty_schedules', ['id' => $block->id, 'employee_id' => $first->id, 'subject' => 'DCIT 26']);
        $this->assertDatabaseHas('employees', ['id' => $first->id, 'rate_amount' => 250]);
        $this->assertDatabaseHas('faculty_schedules', ['id' => $otherBlock->id, 'subject' => 'KEEP ME']);
        $this->assertDatabaseHas('payroll_records', ['id' => $record->id, 'status' => 'released', 'other_earnings' => 99]);
        $this->post('/settings/backup/restore', ['backup_file' => $this->file($backup)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('faculty_schedules', 2);
    }

    public function test_custom_recovery_is_atomic_when_record_ids_belong_to_another_faculty(): void
    {
        $first = $this->faculty('ONE');
        $other = $this->faculty('OTHER');
        $block = $this->schedule($first);
        $cardId = DB::table('rfid_cards')->insertGetId(['employee_id' => $first->id, 'rfid_uid' => 'TEST-CARD', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $backup = $this->download(['credentials', 'schedules'], [$first->id]);
        DB::table('rfid_cards')->where('id', $cardId)->delete();
        $block->update(['employee_id' => $other->id]);
        $this->post('/settings/backup/restore', ['backup_file' => $this->file($backup)])->assertSessionHasErrors('backup_file');
        $this->assertDatabaseMissing('rfid_cards', ['id' => $cardId]);
        $this->assertDatabaseHas('faculty_schedules', ['id' => $block->id, 'employee_id' => $other->id]);
    }

    public function test_custom_selection_and_manifest_are_validated_before_restoring(): void
    {
        $employee = $this->faculty('ONE');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/settings/backup/download?mode=custom&faculty_scope=selected&groups[]=schedules')->assertSessionHasErrors('employee_ids');
        $this->get('/settings/backup/download?mode=custom&faculty_scope=all')->assertSessionHasErrors('groups');
        $this->get('/settings/backup/download?mode=custom&faculty_scope=all&groups[]=sessions')->assertSessionHasErrors('groups.0');
        $this->get('/settings/backup/download?mode=custom&faculty_scope=selected&groups[]=audit&employee_ids[]='.$employee->id)->assertSessionHasErrors('groups');
        $backup = $this->download(['schedules'], [$employee->id]);
        $backup['tables']['users'] = [];
        $this->post('/settings/backup/restore', ['backup_file' => $this->file($backup)])->assertSessionHasErrors('backup_file');
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_backup_and_restore_remain_admin_only_and_legacy_full_backups_work(): void
    {
        $employee = $this->faculty('ONE');
        $this->schedule($employee);
        foreach (['faculty', 'payroll_staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'employee_id' => $role === 'faculty' ? $employee->id : null]));
            $this->get('/settings/backup')->assertForbidden();
            $this->get('/settings/backup/download')->assertForbidden();
            $this->post('/settings/backup/restore')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $backup = json_decode($this->get('/settings/backup/download')->assertOk()->streamedContent(), true);
        $this->assertSame(1, $backup['version']);
        $extra = $this->faculty('LATER');
        $this->post('/settings/backup/restore', ['backup_file' => $this->file($backup)])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('employees', ['id' => $extra->id]);
        $this->assertDatabaseHas('faculty_schedules', ['employee_id' => $employee->id]);
    }
}

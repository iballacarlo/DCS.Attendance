<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ClassAttendanceService;
use App\Services\TodayScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacultyTodayScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number = 'FIRST'): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'status' => 'active', 'rate_amount' => 0]);
    }

    private function schedule(Employee $employee, string $subject = 'DCIT 24')
    {
        return $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'class_mode' => 'lecture',
            'subject' => $subject, 'section' => 'BSIT 1A', 'start_time' => '10:00', 'end_time' => '11:00']);
    }

    public static function states(): array
    {
        return [
            'upcoming' => ['09:00', false, null, null, 'upcoming', false],
            'early IN' => ['09:45', true, '09:40', null, 'checked_in', false],
            'awaiting IN' => ['10:30', false, null, null, 'awaiting_in', true],
            'checked in' => ['10:30', true, '10:05', null, 'checked_in', true],
            'early departure' => ['10:30', true, '10:05', '10:20', 'finished', false],
            'finished' => ['11:10', true, '10:00', '11:00', 'finished', false],
            'absent at end' => ['11:00', false, null, null, 'absent', false],
            'missing OUT' => ['11:00', true, '10:05', null, 'for_review', false],
            'missing IN' => ['11:00', true, null, '10:50', 'for_review', false],
            'invalid pair' => ['11:00', true, '10:10', '10:05', 'for_review', false],
            'equal pair' => ['11:00', true, '10:10', '10:10', 'for_review', false],
            'empty saved record' => ['11:00', true, null, null, 'for_review', false],
        ];
    }

    #[DataProvider('states')]
    public function test_class_states_reflect_real_taps_and_scheduled_boundaries(string $time, bool $record, ?string $in, ?string $out, string $status, bool $current): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 '.$time, 'Asia/Manila'));
        $employee = $this->faculty();
        $schedule = $this->schedule($employee);
        if ($record) {
            AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($schedule),
                'attendance_date' => '2026-10-05', 'time_in' => $in, 'time_out' => $out]);
        }
        $result = app(TodayScheduleService::class)->build($employee, Carbon::now('Asia/Manila'));
        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame($status, $item['status']);
        $this->assertSame($current, $item['is_current']);
        $this->assertSame($in, $item['time_in'] ? substr($item['time_in'], 0, 5) : null);
        $this->assertSame($out, $item['time_out'] ? substr($item['time_out'], 0, 5) : null);
        $this->assertSame(['finished' => $status === 'finished' ? 1 : 0, 'total' => 1], $result['summary']);
        if ($status !== 'finished') {
            $this->assertNull($item['late_minutes']);
            $this->assertNull($item['undertime_minutes']);
        } elseif ($in === '10:05') {
            $this->assertSame(5, $item['late_minutes']);
            $this->assertSame(40, $item['undertime_minutes']);
        }
        $this->assertDatabaseCount('attendance_logs', $record ? 1 : 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_consultation_and_lunch_have_no_attendance_and_are_excluded_from_counts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:30', 'Asia/Manila'));
        $employee = $this->faculty();
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'consultation', 'start_time' => '13:00', 'end_time' => '14:00']);
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);
        $result = app(TodayScheduleService::class)->build($employee, Carbon::now('Asia/Manila'));
        $this->assertSame(['lunch_break', 'consultation'], array_column($result['items'], 'schedule_type'));
        $this->assertSame(['finished' => 0, 'total' => 0], $result['summary']);
        foreach ($result['items'] as $item) {
            $this->assertNull($item['status']);
            $this->assertNull($item['time_in']);
            $this->assertNull($item['time_out']);
        }
    }

    public function test_snapshots_remain_after_schedule_moves_or_is_deleted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));
        $employee = $this->faculty();
        $schedule = $this->schedule($employee);
        AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($schedule),
            'attendance_date' => '2026-10-05', 'time_in' => '10:00', 'time_out' => '11:00']);
        $schedule->update(['subject' => 'CHANGED', 'day_of_week' => 2, 'start_time' => '14:00', 'end_time' => '15:00']);
        foreach ([false, true] as $delete) {
            if ($delete) {
                $schedule->delete();
            }
            $employee->unsetRelation('schedules');
            $result = app(TodayScheduleService::class)->build($employee, Carbon::now('Asia/Manila'));
            $this->assertCount(1, $result['items']);
            $this->assertSame('DCIT 24', $result['items'][0]['subject']);
            $this->assertSame('10:00', substr($result['items'][0]['start_time'], 0, 5));
            $this->assertSame('finished', $result['items'][0]['status']);
        }
    }

    public function test_exclusions_follow_reports_and_do_not_count_as_required_classes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'Asia/Manila'));
        $employee = $this->faculty();
        $this->schedule($employee);
        $event = CalendarEvent::create(['title' => 'Class suspension', 'type' => 'suspension', 'scope_type' => 'campus', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'is_paid' => false]);
        $service = app(TodayScheduleService::class);
        $result = $service->build($employee, Carbon::now('Asia/Manila'));
        $this->assertSame('not_required', $result['items'][0]['status']);
        $this->assertSame('Class suspension', $result['items'][0]['exclusion']);
        $this->assertSame(0, $result['summary']['total']);
        $event->delete();
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::firstOrFail()->id,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'status' => 'approved', 'reason' => 'Approved leave']);
        $result = $service->build($employee, Carbon::now('Asia/Manila'));
        $this->assertSame('Approved leave', $result['items'][0]['exclusion']);
        $this->assertSame(0, $result['summary']['total']);
        $this->travelTo(Carbon::parse('2026-11-30 12:00', 'Asia/Manila'));
        $result = $service->build($employee, Carbon::now('Asia/Manila'));
        $this->assertSame('not_required', $result['items'][0]['status']);
        $this->assertNotEmpty($result['items'][0]['exclusion']);
    }

    public function test_dashboard_is_own_faculty_only_and_missing_link_is_empty(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:30', 'Asia/Manila'));
        $first = $this->faculty();
        $second = $this->faculty('SECOND');
        $this->schedule($first, 'OWN CLASS');
        $this->schedule($second, 'PRIVATE CLASS');
        foreach ([$first, $second] as $employee) {
            EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05',
                'time_in' => '08:00', 'time_out' => '17:00', 'schedule_snapshot' => []]);
        }
        $user = User::factory()->create(['role' => 'faculty', 'employee_id' => $first->id]);
        $this->actingAs($user)->get('/?employee_id='.$second->id)->assertInertia(fn (Assert $p) => $p
            ->has('todaySchedule', 1)->where('todaySchedule.0.subject', 'OWN CLASS')
            ->has('entrance', 1)->where('entrance.0.employee_id', $first->id)
            ->where('todaySchedule.0.status', 'awaiting_in')->where('todayScheduleSummary.total', 1)
            ->where('scheduleAsOf', '2026-10-05T10:30:00+08:00')->where('classAnalytics', null));
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => null]))->get('/')
            ->assertInertia(fn (Assert $p) => $p->has('todaySchedule', 0)->has('entrance', 0)->where('todayScheduleSummary.total', 0));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/')
            ->assertInertia(fn (Assert $p) => $p->has('todaySchedule', 0));
    }

    public function test_schedule_date_navigation_keeps_today_cards_and_uses_historical_taps_and_snapshots(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 10:30', 'Asia/Manila'));
        $employee = $this->faculty();
        $schedule = $this->schedule($employee, 'OLD SUBJECT');
        AttendanceLog::create(['employee_id' => $employee->id, ...ClassAttendanceService::snapshot($schedule),
            'attendance_date' => '2026-10-05', 'time_in' => '10:05', 'time_out' => '10:50']);
        $schedule->update(['subject' => 'NEW SUBJECT']);
        EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-12',
            'time_in' => '08:00', 'schedule_snapshot' => []]);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]));
        $this->get('/?schedule_date=2026-10-05')->assertInertia(fn (Assert $p) => $p
            ->where('scheduleDate', '2026-10-12')->where('todaySchedule.0.subject', 'NEW SUBJECT')
            ->where('todaySchedule.0.status', 'awaiting_in')->has('entrance', 1)
            ->where('entrance.0.attendance_date', '2026-10-12')
            ->where('scheduleView.date', '2026-10-05')->has('scheduleView.items', 1)
            ->where('scheduleView.items.0.subject', 'OLD SUBJECT')->where('scheduleView.items.0.status', 'finished')
            ->where('scheduleView.items.0.late_minutes', 5)->where('scheduleView.items.0.undertime_minutes', 10)
            ->where('scheduleView.summary.finished', 1));
        $this->get('/?schedule_date=2026-10-19')->assertInertia(fn (Assert $p) => $p
            ->where('scheduleView.items.0.status', 'upcoming')->where('scheduleView.items.0.is_current', false));
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('scheduleView', null));
    }

    public function test_selected_day_uses_its_own_weekday_and_still_enforces_faculty_privacy(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 10:30', 'Asia/Manila'));
        $employee = $this->faculty();
        $other = $this->faculty('PRIVATE');
        $this->schedule($employee, 'MONDAY CLASS');
        $this->schedule($other, 'PRIVATE CLASS');
        $employee->scheduleBreaks()->create(['day_of_week' => 1, 'schedule_type' => 'lunch_break', 'start_time' => '12:00', 'end_time' => '13:00']);
        $employee->schedules()->create(['day_of_week' => 2, 'schedule_type' => 'consultation', 'start_time' => '14:00', 'end_time' => '15:00']);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]));
        $this->get('/?schedule_date=2026-10-05&employee_id='.$other->id)->assertInertia(fn (Assert $p) => $p
            ->has('scheduleView.items', 2)->where('scheduleView.items.0.subject', 'MONDAY CLASS')
            ->where('scheduleView.items.0.status', 'absent')->where('scheduleView.items.1.schedule_type', 'lunch_break')
            ->where('todaySchedule.0.schedule_type', 'consultation'));
        $this->get('/?schedule_date=not-a-date')->assertSessionHasErrors('schedule_date');
        $this->get('/?schedule_date=2026-02-30')->assertSessionHasErrors('schedule_date');
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => null]));
        $this->get('/?schedule_date=2026-10-05')->assertInertia(fn (Assert $p) => $p->has('scheduleView.items', 0));
    }

    public function test_school_taps_do_not_change_class_states_and_midnight_uses_manila_date(): void
    {
        $employee = $this->faculty();
        $this->schedule($employee, 'MONDAY');
        $employee->schedules()->create(['day_of_week' => 2, 'schedule_type' => 'class', 'class_mode' => 'lecture',
            'subject' => 'TUESDAY', 'section' => 'BSIT 1A', 'start_time' => '10:00', 'end_time' => '11:00']);
        EntranceAttendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-05', 'time_in' => '08:00', 'time_out' => '17:00', 'schedule_snapshot' => []]);
        $user = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]);
        $this->travelTo(Carbon::parse('2026-10-05 15:59:00', 'UTC'));
        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p->where('scheduleDate', '2026-10-05')
            ->where('todaySchedule.0.status', 'absent')->where('presentToday', 1));
        $this->travelTo(Carbon::parse('2026-10-05 16:00:00', 'UTC'));
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('scheduleDate', '2026-10-06')
            ->where('scheduleAsOf', '2026-10-06T00:00:00+08:00')->has('todaySchedule', 1)
            ->where('todaySchedule.0.subject', 'TUESDAY')->where('todaySchedule.0.status', 'upcoming')->where('presentToday', 0));
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 1);
    }

    public function test_reloading_dashboard_reflects_hardware_in_and_out_without_school_attendance(): void
    {
        $employee = $this->faculty();
        $this->schedule($employee);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id]));
        $this->travelTo(Carbon::parse('2026-10-05 09:59', 'Asia/Manila'));
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('todaySchedule.0.status', 'upcoming'));
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('todaySchedule.0.status', 'awaiting_in'));
        $hardware = app(ClassAttendanceService::class);
        $this->assertSame('IN', $hardware->record($employee, 'rfid', Carbon::now('Asia/Manila'))['action']);
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('todaySchedule.0.status', 'checked_in')
            ->where('todaySchedule.0.time_in', '10:00:00')->where('todayScheduleSummary.finished', 0));
        $this->travelTo(Carbon::parse('2026-10-05 10:59', 'Asia/Manila'));
        $this->assertSame('OUT', $hardware->record($employee, 'rfid', Carbon::now('Asia/Manila'))['action']);
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('todaySchedule.0.status', 'finished')
            ->where('todaySchedule.0.time_out', '10:59:00')->where('todaySchedule.0.undertime_minutes', 1)
            ->where('todayScheduleSummary.finished', 1)->where('presentToday', 0));
        $this->assertDatabaseCount('entrance_attendances', 0);
    }
}

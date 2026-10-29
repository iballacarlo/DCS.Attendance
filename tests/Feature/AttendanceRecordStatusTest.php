<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\User;
use App\Services\AttendanceRecordService;
use App\Services\ClassAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceRecordStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-13 18:00', 'Asia/Manila'));
    }

    private function faculty(string $number = 'ONE'): Employee
    {
        $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'status' => 'active', 'rate_amount' => 0]);
        foreach (range(1, 5) as $day) {
            $employee->schedules()->create(['day_of_week' => $day, 'schedule_type' => 'class', 'subject' => 'DCIT 24',
                'section' => 'BSIT 1A', 'start_time' => '08:00', 'end_time' => '10:00', 'class_mode' => 'lecture']);
        }

        return $employee;
    }

    public static function flows(): array
    {
        return ['class' => ['/attendance', AttendanceLog::class], 'school' => ['/entrance', EntranceAttendance::class]];
    }

    private function record(string $model, Employee $employee, string $date, ?string $in, ?string $out): void
    {
        $attributes = ['employee_id' => $employee->id, 'attendance_date' => $date, 'time_in' => $in, 'time_out' => $out];
        if ($model === AttendanceLog::class) {
            $schedule = $employee->schedules()->where('day_of_week', Carbon::parse($date)->dayOfWeek)->firstOrFail();
            $attributes += ClassAttendanceService::snapshot($schedule);
        } else {
            $attributes['schedule_snapshot'] = [[28800, 36000]];
        }
        $model::create($attributes);
    }

    #[DataProvider('flows')]
    public function test_lists_show_and_filter_real_statuses_and_virtual_absences_without_changing_logs(string $path, string $model): void
    {
        $employee = $this->faculty();
        foreach ([['05', '07:55', '10:05'], ['06', '08:05', '10:00'], ['07', '08:00', '09:50'],
            ['08', '08:05', '09:50'], ['12', '08:00', null], ['13', '09:00', '08:00']] as [$day, $in, $out]) {
            $this->record($model, $employee, '2026-10-'.$day, $in, $out);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = $path.'?cutoff_start=2026-10-01&employee_id='.$employee->id;
        foreach (['on_time' => 1, 'late' => 2, 'undertime' => 2, 'late_undertime' => 1, 'for_review' => 2] as $status => $count) {
            $this->get($url.'&status='.$status)->assertOk()->assertInertia(fn (Assert $p) => $p->where('logs.total', $count)
                ->where('logs.data', fn ($rows) => collect($rows)->every(fn ($row) => ! empty($row['attendance_status_label']))));
        }
        $this->get($url.'&status=absent')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('logs.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['attendance_date'] === '2026-10-09'
                && $row['attendance_status'] === 'absent' && $row['time_in'] === null && ! $row['has_record'])
                && collect($rows)->every(fn ($row) => $row['attendance_date'] <= '2026-10-13')));
        $this->assertDatabaseCount($model === AttendanceLog::class ? 'attendance_logs' : 'entrance_attendances', 6);
        $this->assertDatabaseCount($model === AttendanceLog::class ? 'entrance_attendances' : 'attendance_logs', 0);
    }

    #[DataProvider('flows')]
    public function test_missing_records_respect_privacy_exclusions_and_unlinked_accounts(string $path, string $model): void
    {
        $own = $this->faculty();
        $other = $this->faculty('OTHER');
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $own->id]));
        $this->get($path.'?cutoff_start=2026-10-01&employee_id='.$other->id.'&status=absent&per_page=50')
            ->assertInertia(fn (Assert $p) => $p->where('logs.data', fn ($rows) => collect($rows)->isNotEmpty()
                && collect($rows)->every(fn ($row) => $row['employee_id'] === $own->id
                    && ! in_array($row['attendance_date'], ['2026-10-03', '2026-10-04', '2026-10-14'], true))));
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => null]))
            ->get($path.'?cutoff_start=2026-10-01&status=absent')->assertInertia(fn (Assert $p) => $p->where('logs.total', 0));
        CalendarEvent::create(['title' => 'Suspension', 'type' => 'suspension', 'scope_type' => 'campus',
            'start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'is_paid' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get($path.'?cutoff_start=2026-10-01&employee_id='.$own->id.'&status=absent&per_page=50')
            ->assertInertia(fn (Assert $p) => $p->where('logs.data', fn ($rows) => ! collect($rows)->contains('attendance_date', '2026-10-09')));
    }

    public function test_live_statuses_gaps_and_saved_school_snapshots_are_used(): void
    {
        $service = app(AttendanceRecordService::class);
        $intervals = [[28800, 36000], [50400, 57600]];
        $state = $service->classify('11:30', '15:00', $intervals, '2026-10-13');
        $this->assertSame('late_undertime', $state['attendance_status']);
        $this->assertSame(120, $state['late_minutes_display']);
        $this->assertSame(60, $state['undertime_minutes_display']);
        $this->travelTo(Carbon::parse('2026-10-13 09:00', 'Asia/Manila'));
        $this->assertSame('checked_in', $service->classify('08:05', null, [[28800, 36000]], '2026-10-13')['attendance_status']);
        $this->assertSame('awaiting_in', $service->classify(null, null, [[28800, 36000]], '2026-10-13')['attendance_status']);
        $this->assertSame('upcoming', $service->classify(null, null, $intervals, '2026-10-14')['attendance_status']);
        $employee = $this->faculty();
        $this->record(EntranceAttendance::class, $employee, '2026-10-12', '08:05', '09:50');
        $employee->schedules()->delete();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/entrance?cutoff_start=2026-10-01&status=late_undertime')
            ->assertInertia(fn (Assert $p) => $p->where('logs.total', 1)->where('logs.data.0.late_minutes_display', 5)
                ->where('logs.data.0.undertime_minutes_display', 10));
    }
}

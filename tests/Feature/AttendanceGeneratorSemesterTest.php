<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\RfidCard;
use App\Models\User;
use App\Services\AttendanceGenerationService;
use App\Services\SemesterManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceGeneratorSemesterTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Employee $employee;
    private AcademicTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance_generator.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-30 18:00', 'Asia/Manila'));
        $this->app->bind(AttendanceGenerationService::class, fn () => new class extends AttendanceGenerationService
        {
            protected function pick(string $seed, string $key, int $min, int $max): int
            {
                return str_ends_with($key, ':scenario') ? 0 : $min;
            }
        });
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actor = User::factory()->create(['role' => 'generator', 'must_change_password' => false]);
        $this->employee = Employee::create(['employee_no' => 'SEM-GEN', 'first_name' => 'Test', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        RfidCard::create(['employee_id' => $this->employee->id, 'rfid_uid' => 'SEM-GEN-CARD', 'status' => 'active']);
        $service = app(SemesterManagementService::class);
        $this->term = $service->create(['school_year' => '2026-2027', 'semester' => 'First Semester', 'start_date' => '2026-09-01', 'end_date' => '2026-10-31'], $admin);
        $service->save($this->term, $this->employee, [
            ['schedule_type' => 'class', 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 24', 'section' => 'BSIT 2A', 'class_mode' => 'lecture'],
            ['schedule_type' => 'consultation', 'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '11:00'],
            ['schedule_type' => 'lunch_break', 'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00'],
        ], [], 'publish', null, '', $admin);
        CalendarEvent::create(['title' => 'Suspension', 'type' => 'suspension', 'start_date' => '2026-09-14', 'end_date' => '2026-09-14', 'scope_type' => 'campus', 'is_paid' => false]);
        $this->actingAs($this->actor);
    }

    public static function flows(): array
    {
        return ['class' => ['class', AttendanceLog::class, EntranceAttendance::class], 'school' => ['school', EntranceAttendance::class, AttendanceLog::class]];
    }

    private function selection(string $mode = 'class'): array
    {
        return ['mode' => $mode, 'period' => 'semester', 'term_id' => $this->term->id, 'employee_id' => $this->employee->id];
    }

    #[DataProvider('flows')]
    public function test_whole_semester_spans_cutoffs_and_keeps_flows_separate_and_overwrite_safe(string $mode, string $model, string $other): void
    {
        $filters = $this->selection($mode);
        $preview = $this->postJson('/tools/attendance-generator/preview', $filters)->assertOk()
            ->assertJsonPath('preview.start_date', '2026-09-01')->assertJsonPath('preview.end_date', '2026-10-31')
            ->assertJsonPath('preview.to_create', 7)->json();
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token']])
            ->assertOk()->assertJsonPath('result.created', 7);
        $rows = $model::all();
        $this->assertSame(0, $other::count());
        $this->assertSame(3, $rows->filter(fn ($row) => str_starts_with($row->attendance_date, '2026-09'))->count());
        $this->assertSame(4, $rows->filter(fn ($row) => str_starts_with($row->attendance_date, '2026-10'))->count());
        $this->assertTrue($rows->every(fn ($row) => $row->academic_term_id === $this->term->id
            && $row->attendance_date <= '2026-10-30' && $row->time_out > $row->time_in && $row->attendance_date !== '2026-09-14'));
        $ids = $rows->pluck('id')->all();
        $this->postJson('/tools/attendance-generator/generate', $filters)->assertOk()->assertJsonPath('result.created', 0);
        $this->postJson('/tools/attendance-generator/generate', [...$filters, 'overwrite' => true, 'confirm_overwrite' => true])
            ->assertOk()->assertJsonPath('result.updated', 7)->assertJsonPath('result.created', 0);
        $this->assertSame($ids, $model::all()->pluck('id')->all());
        $this->assertSame(0, $other::count());
    }

    public function test_missing_unknown_future_and_closed_semesters_are_rejected(): void
    {
        $this->postJson('/tools/attendance-generator/generate', [...$this->selection(), 'term_id' => null])->assertUnprocessable();
        $this->postJson('/tools/attendance-generator/generate', [...$this->selection(), 'term_id' => 99999])->assertUnprocessable();
        $future = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-15', 'status' => 'scheduled']);
        $this->postJson('/tools/attendance-generator/generate', [...$this->selection(), 'term_id' => $future->id])->assertUnprocessable();
        $this->get('/tools/attendance-generator')->assertInertia(fn (Assert $page) => $page->where('filters.period', 'cutoff')->has('semesters', 1)->where('semesters.0.id', $this->term->id));
        $this->term->update(['status' => 'closed']);
        foreach (['preview', 'generate'] as $action) {
            $this->postJson('/tools/attendance-generator/'.$action, [...$this->selection(), 'overwrite' => true, 'confirm_overwrite' => true])->assertUnprocessable();
        }
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_semester_dates_and_closed_status_are_rechecked_after_preview(): void
    {
        $preview = $this->postJson('/tools/attendance-generator/preview', $this->selection())->assertOk()->json();
        $this->term->update(['end_date' => '2026-11-15']);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token']])->assertUnprocessable()->assertJsonValidationErrors('term_id');
        $preview = $this->postJson('/tools/attendance-generator/preview', $this->selection())->assertOk()->json();
        $this->term->update(['status' => 'closed']);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token'], 'confirm_overwrite' => true])->assertUnprocessable();
        $this->assertDatabaseCount('attendance_logs', 0);
    }
}

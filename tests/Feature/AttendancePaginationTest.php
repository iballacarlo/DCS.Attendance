<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendancePaginationTest extends TestCase
{
    use RefreshDatabase;

    public static function flows(): array
    {
        return ['class' => ['/attendance', AttendanceLog::class, 'present'], 'school' => ['/entrance', EntranceAttendance::class, 'complete']];
    }

    private function records(string $model): array
    {
        $employees = [];
        foreach (['FIRST', 'SECOND'] as $number) {
            $employee = Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => $number, 'status' => 'active', 'rate_amount' => 0]);
            $employees[] = $employee;
            foreach (range(16, 31) as $day) {
                $attributes = ['employee_id' => $employee->id, 'attendance_date' => '2026-10-'.$day, 'time_in' => '08:00', 'time_out' => '10:00'];
                if ($model === AttendanceLog::class) {
                    $attributes += ['schedule_type' => 'class', 'class_mode' => 'lecture', 'subject' => 'DCIT 24', 'section' => 'BSIT 1A',
                        'scheduled_start' => '08:00', 'scheduled_end' => '10:00', 'method_in' => 'rfid', 'is_legacy' => false];
                } else {
                    $attributes['schedule_snapshot'] = [];
                }
                $model::create($attributes);
            }
        }
        return $employees;
    }

    #[DataProvider('flows')]
    public function test_pages_have_stable_order_sizes_ranges_and_filter_links(string $path, string $model, string $status): void
    {
        [$employee] = $this->records($model);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = $path.'?cutoff_start=2026-10-16&status='.$status;
        $first = $this->get($url)->assertInertia(fn (Assert $p) => $p->has('logs.data', 10)
            ->where('logs.total', 32)->where('logs.per_page', 10)->where('logs.from', 1)->where('logs.to', 10)
            ->where('logs.current_page', 1)->where('logs.last_page', 4)->missing('filters.page'));
        $second = $this->get($first->viewData('page')['props']['logs']['next_page_url'])
            ->assertInertia(fn (Assert $p) => $p->has('logs.data', 10)->where('logs.from', 11)->where('logs.to', 20));
        $ids = fn ($response) => collect($response->viewData('page')['props']['logs']['data'])->pluck('id')->all();
        $this->assertEmpty(array_intersect($ids($first), $ids($second)));
        $filtered = $this->get($url.'&employee_id='.$employee->id)->assertInertia(fn (Assert $p) => $p->where('logs.total', 16));
        $next = $filtered->viewData('page')['props']['logs']['next_page_url'];
        foreach (['cutoff_start=2026-10-16', 'status='.$status, 'employee_id='.$employee->id] as $filter) {
            $this->assertStringContainsString($filter, $next);
        }
        $this->get($url.'&per_page=25')->assertInertia(fn (Assert $p) => $p->has('logs.data', 25)->where('logs.per_page', 25)->where('logs.last_page', 2));
        $this->get($url.'&per_page=25&page=2')->assertInertia(fn (Assert $p) => $p->has('logs.data', 7)->where('logs.from', 26)->where('logs.to', 32));
        $this->get($url.'&per_page=50')->assertInertia(fn (Assert $p) => $p->has('logs.data', 32)->where('logs.last_page', 1));
    }

    #[DataProvider('flows')]
    public function test_invalid_pages_are_rejected_and_excess_pages_clamp_without_false_empty_results(string $path, string $model, string $status): void
    {
        $this->records($model);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = $path.'?cutoff_start=2026-10-16';
        $this->get($url.'&page=999')->assertInertia(fn (Assert $p) => $p->where('logs.current_page', 4)->has('logs.data', 2));
        $this->get($path.'?cutoff_start=2026-09-16&page=9')->assertInertia(fn (Assert $p) => $p->where('logs.current_page', 1)->where('logs.total', 0)->has('logs.data', 0));
        $this->get($url.'&per_page=1000')->assertSessionHasErrors('per_page');
        $this->get($url.'&page=0')->assertSessionHasErrors('page');
    }

    #[DataProvider('flows')]
    public function test_faculty_only_receive_own_records_on_every_page(string $path, string $model, string $status): void
    {
        [$own, $other] = $this->records($model);
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => $own->id]));
        $url = $path.'?cutoff_start=2026-10-16&employee_id='.$other->id;
        foreach ([1 => 10, 2 => 6] as $page => $size) {
            $this->get($url.'&page='.$page)->assertInertia(fn (Assert $p) => $p->where('logs.total', 16)->has('logs.data', $size)
                ->where('logs.data', fn ($logs) => collect($logs)->every(fn ($log) => $log['employee_id'] === $own->id)));
        }
        $this->actingAs(User::factory()->create(['role' => 'faculty', 'employee_id' => null]))->get($url)
            ->assertInertia(fn (Assert $p) => $p->where('logs.total', 0));
    }
}

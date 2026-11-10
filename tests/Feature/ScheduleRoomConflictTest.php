<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Employee;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\Room;
use App\Models\User;
use App\Services\SemesterManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ScheduleRoomConflictTest extends TestCase
{
    use RefreshDatabase;

    private AcademicTerm $term;

    private Employee $teacher;

    private Employee $other;

    private Room $room;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 18:00', 'Asia/Manila'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $building = Building::create(['code' => 'ROOM-CHECK', 'name' => 'Room Check', 'is_active' => true]);
        $this->room = Room::create(['building_id' => $building->id, 'code' => 'A-301', 'is_active' => true]);
        $this->teacher = Employee::create(['employee_no' => 'ROOM-ONE', 'first_name' => 'Jemuel', 'last_name' => 'Acibar', 'status' => 'active', 'rate_amount' => 0]);
        $this->other = Employee::create(['employee_no' => 'ROOM-TWO', 'first_name' => 'Other', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        $service = app(SemesterManagementService::class);
        $this->term = $service->create(['school_year' => '2026-2027', 'semester' => 'First Semester', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15'], $this->admin);
        $service->save($this->term, $this->teacher, [$this->block()], [], 'publish', '2026-10-01', '', $this->admin);
        $service->transition($this->term, 'activate', 'Room conflict check', $this->admin);
        $this->actingAs($this->admin);
    }

    private function block(array $changes = []): array
    {
        return array_replace(['schedule_type' => 'class', 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00',
            'subject' => 'DCIT 24', 'section' => 'BSIT 2A', 'class_mode' => 'lecture', 'room_id' => $this->room->id], $changes);
    }

    #[TestWith(['draft'])]
    #[TestWith(['publish'])]
    public function test_room_conflict_identifies_teacher_room_day_and_time_without_overwriting_saved_draft(string $intent): void
    {
        $saved = [$this->block(['day_of_week' => 2])];
        app(SemesterManagementService::class)->save($this->term, $this->other, $saved, [], 'draft', null, '', $this->admin);
        $this->put('/employees/'.$this->other->id.'/schedule', [
            'term_id' => $this->term->id, 'intent' => $intent,
            'blocks' => [$this->block(['start_time' => '09:00', 'end_time' => '11:00'])],
        ])->assertSessionHasErrors('blocks.0.room_id');
        $message = session('errors')->first('blocks.0.room_id');
        foreach (['A-301', 'Monday', '8:00 AM', '10:00 AM', 'Acibar, Jemuel', 'DCIT 24', 'BSIT 2A'] as $detail) {
            $this->assertStringContainsString($detail, $message);
        }
        $this->assertSame($saved, FacultyTermAssignment::where('employee_id', $this->other->id)->firstOrFail()->draft_blocks);
        $this->assertSame(1, FacultyScheduleVersion::count());
    }

    #[TestWith(['07:00', '08:00', 1])]
    #[TestWith(['10:00', '11:00', 1])]
    #[TestWith(['08:00', '10:00', 2])]
    public function test_adjacent_times_and_other_weekdays_can_save_drafts(string $start, string $end, int $day): void
    {
        $this->put('/employees/'.$this->other->id.'/schedule', [
            'term_id' => $this->term->id, 'intent' => 'draft',
            'blocks' => [$this->block(['start_time' => $start, 'end_time' => $end, 'day_of_week' => $day])],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('faculty_term_assignments', ['employee_id' => $this->other->id]);
    }

    public function test_overlapping_times_in_another_room_and_saving_the_teachers_own_schedule_are_allowed(): void
    {
        $otherRoom = Room::create(['building_id' => $this->room->building_id, 'code' => 'A-302', 'is_active' => true]);
        $this->put('/employees/'.$this->other->id.'/schedule', [
            'term_id' => $this->term->id, 'intent' => 'draft', 'blocks' => [$this->block(['room_id' => $otherRoom->id])],
        ])->assertSessionHasNoErrors();
        $this->put('/employees/'.$this->teacher->id.'/schedule', [
            'term_id' => $this->term->id, 'intent' => 'draft', 'blocks' => [$this->block()],
        ])->assertSessionHasNoErrors();
    }
}

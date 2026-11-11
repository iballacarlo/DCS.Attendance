<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\Building;
use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HardwareAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = Room::create(['code' => 'TEST-ROOM', 'name' => 'Test Room', 'is_active' => true]);
        $device = AttendanceDevice::create(['code' => 'TEST-DEVICE', 'name' => 'Test TFT', 'is_active' => true]);
        $device->assignments()->create(['room_id' => $this->room->id, 'effective_from' => '2020-01-01 00:00:00']);
        $this->withHeader('X-Device-Code', 'TEST-DEVICE');
    }

    public function test_rfid_list_requires_the_hardware_key(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $this->getJson('/api/hardware/rfid-cards')->assertUnauthorized();
    }

    public function test_rfid_list_includes_the_current_terminal_room_and_follows_reassignment(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $building = Building::create(['code' => 'ROOM-DISPLAY', 'name' => 'Room Display', 'is_active' => true]);
        $this->room->update(['building_id' => $building->id, 'code' => 'A-301']);
        $this->withHeader('X-Hardware-Key', 'device-secret')->getJson('/api/hardware/rfid-cards')
            ->assertOk()->assertJsonPath('terminal_room', 'A-301')->assertJsonPath('complete', 'true');

        $device = AttendanceDevice::where('code', 'TEST-DEVICE')->firstOrFail();
        $room = Room::create(['building_id' => $building->id, 'code' => 'RM 1', 'name' => 'Room 1', 'is_active' => true]);
        $device->assignments()->update(['effective_to' => now()->subMinute()]);
        $device->assignments()->create(['room_id' => $room->id, 'effective_from' => now()->subMinute()]);
        $this->getJson('/api/hardware/rfid-cards')->assertOk()->assertJsonPath('terminal_room', 'RM 1');

        $room->update(['is_active' => false]);
        $this->getJson('/api/hardware/rfid-cards')->assertOk()
            ->assertJsonPath('terminal_room', '')->assertJsonPath('complete', 'true');
    }

    public function test_rfid_list_keeps_compatibility_with_terminals_without_a_device_header(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $this->withoutHeader('X-Device-Code')->withHeader('X-Hardware-Key', 'device-secret')
            ->getJson('/api/hardware/rfid-cards')->assertOk()->assertJsonMissingPath('terminal_room');
    }

    public function test_rfid_list_tracks_registration_replacement_unlink_and_inactive_faculty(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $employee = Employee::create([
            'employee_no' => 'RFID-CACHE-1',
            'first_name' => 'Cache',
            'last_name' => 'Faculty',
            'faculty_rank_id' => $rank->id,
            'rate_type' => 'hourly',
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
        ]);
        $list = fn () => $this->withHeader('X-Hardware-Key', 'device-secret')
            ->getJson('/api/hardware/rfid-cards');
        $list()->assertOk()->assertJsonPath('complete', 'true')->assertJsonPath('uids', '');
        $card = $employee->rfidCards()->create(['rfid_uid' => 'aabbccdd', 'status' => 'active']);
        $list()->assertOk()->assertJsonPath('uids', 'AABBCCDD');
        $card->update(['rfid_uid' => '11223344556677']);
        $list()->assertOk()->assertJsonPath('uids', '11223344556677');
        $card->update(['status' => 'lost']);
        $list()->assertOk()->assertJsonPath('uids', '');
        $card->update(['status' => 'active']);
        $employee->update(['status' => 'inactive']);
        $list()->assertOk()->assertJsonPath('uids', '');
        $employee->update(['status' => 'active']);
        $card->delete();
        $list()->assertOk()->assertJsonPath('uids', '');
    }

    public function test_hardware_endpoint_rejects_requests_when_server_key_is_missing(): void
    {
        config(['services.hardware.api_key' => null]);

        $this->postJson('/api/hardware/tap', [
            'identifier' => 'RFID-1001',
            'method' => 'rfid',
        ])->assertUnauthorized();
    }

    public function test_hardware_endpoint_rejects_an_incorrect_key(): void
    {
        config(['services.hardware.api_key' => 'correct-key']);

        $this->postJson('/api/hardware/tap', [
            'api_key' => 'incorrect-key',
            'identifier' => 'RFID-1001',
            'method' => 'rfid',
        ])->assertUnauthorized();
    }

    public function test_hardware_endpoint_accepts_the_configured_key(): void
    {
        config(['services.hardware.api_key' => 'correct-key']);

        $this->postJson('/api/hardware/tap', [
            'api_key' => 'correct-key',
            'identifier' => 'UNKNOWN-RFID',
            'method' => 'rfid',
        ])->assertNotFound();
    }

    public function test_hardware_rejects_unscheduled_tap_without_counting_payable_hours(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        Carbon::setTestNow(Carbon::parse('2026-09-20 08:00:00', 'Asia/Manila'));
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $employee = Employee::create([
            'employee_no' => 'COS-9999',
            'first_name' => 'No',
            'last_name' => 'Schedule',
            'faculty_rank_id' => $rank->id,
            'rate_type' => 'hourly',
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
        ]);
        $employee->rfidCards()->create([
            'rfid_uid' => 'NO-SCHEDULE-CARD',
            'status' => 'active',
        ]);

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson('/api/hardware/tap', [
                'identifier' => 'NO-SCHEDULE-CARD',
                'method' => 'rfid',
            ])
            ->assertOk()
            ->assertJsonPath('action', 'NO CLASS');

        $this->assertDatabaseCount('attendance_logs', 0);
        Carbon::setTestNow();
    }

    #[TestWith(['rfid', 'http'])]
    #[TestWith(['rfid', 'https'])]
    #[TestWith(['fingerprint', 'http'])]
    #[TestWith(['fingerprint', 'https'])]
    public function test_hardware_allows_time_out_only_from_ten_minutes_before_class_end(string $method, string $scheme): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $startedAt = Carbon::parse('2026-09-21 08:00:00', 'Asia/Manila');
        Carbon::setTestNow($startedAt);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $employee = Employee::create([
            'employee_no' => 'COS-COOLDOWN-1',
            'first_name' => 'Two',
            'last_name' => 'Minutes',
            'faculty_rank_id' => $rank->id,
            'rate_type' => 'hourly',
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
        ]);
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'break_minutes' => 0, 'room_id' => $this->room->id]);
        $identifier = $method === 'rfid' ? 'TWO-MINUTE-CARD' : 'FP-2';
        if ($method === 'rfid') {
            $employee->rfidCards()->create(['rfid_uid' => $identifier, 'status' => 'active']);
        } else {
            $employee->fingerprintTemplates()->create(['fingerprint_code' => $identifier, 'status' => 'active']);
        }
        $tap = fn () => $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson($scheme.'://localhost/api/hardware/tap', [
                'identifier' => $identifier,
                'method' => $method,
            ]);

        $tap()->assertOk()->assertJsonPath('action', 'IN');
        Carbon::setTestNow($startedAt->copy()->addMinutes(2));
        $tap()->assertOk()->assertJsonPath('action', 'WAIT')
            ->assertJsonPath('time_out_available_at', (string) $startedAt->copy()->addHour()->addMinutes(50)->timestamp);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id, 'time_out' => null]);
        Carbon::setTestNow($startedAt->copy()->addHour()->addMinutes(50));
        $tap()->assertOk()->assertJsonPath('action', 'OUT');
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id, 'time_out' => '09:50:00', 'method_out' => $method,
        ]);

        Carbon::setTestNow();
    }

    public function test_offline_tap_uses_its_original_time_and_is_idempotent(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Asia/Manila'));
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $employee = Employee::create([
            'employee_no' => 'COS-OFFLINE-1',
            'first_name' => 'Offline',
            'last_name' => 'Faculty',
            'faculty_rank_id' => $rank->id,
            'rate_type' => 'hourly',
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
        ]);
        $employee->schedules()->create(['day_of_week' => 2, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'break_minutes' => 0, 'room_id' => $this->room->id]);
        $employee->rfidCards()->create([
            'rfid_uid' => 'OFFLINE-CARD',
            'status' => 'active',
        ]);
        $payload = [
            'identifier' => 'OFFLINE-CARD',
            'method' => 'rfid',
            'event_id' => 'device-001-1791244800-0001',
            'occurred_at' => '2026-10-06 08:15:30',
        ];

        $tap = fn () => $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson('/api/hardware/tap', $payload);

        $tap()->assertOk()->assertJsonPath('action', 'IN')->assertJsonPath('display_time', '08:15 AM');
        $tap()->assertOk()->assertJsonPath('action', 'IN')->assertJsonPath('display_time', '08:15 AM');

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson('/api/hardware/tap', [
                'identifier' => 'OFFLINE-CARD',
                'method' => 'rfid',
                'event_id' => 'device-001-1791251400-0002',
                'occurred_at' => '2026-10-06 09:50:00',
            ])
            ->assertOk()
            ->assertJsonPath('action', 'OUT')
            ->assertJsonPath('display_time', '09:50 AM');

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseCount('hardware_attendance_events', 2);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-06',
            'time_in' => '08:15:30',
            'time_out' => '09:50:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_next_faculty_waits_for_room_then_releases_missing_out_at_own_start(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $faculty = collect([
            ['number' => 'ROOM-ONE', 'first' => 'First', 'card' => 'ROOM-CARD-ONE', 'start' => '08:00', 'end' => '09:00'],
            ['number' => 'ROOM-TWO', 'first' => 'Next', 'card' => 'ROOM-CARD-TWO', 'start' => '09:00', 'end' => '10:00'],
        ])->map(function ($item) use ($rank) {
            $employee = Employee::create(['employee_no' => $item['number'], 'first_name' => $item['first'], 'last_name' => 'Faculty',
                'faculty_rank_id' => $rank->id, 'rate_type' => 'hourly', 'rate_amount' => $rank->rate_amount, 'status' => 'active']);
            $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'subject' => 'ITEC 100', 'section' => 'BSIT 1A',
                'class_mode' => 'lecture', 'start_time' => $item['start'], 'end_time' => $item['end'], 'break_minutes' => 0, 'room_id' => $this->room->id]);
            $employee->rfidCards()->create(['rfid_uid' => $item['card'], 'status' => 'active']);

            return $employee;
        });
        $tap = fn (string $card, string $time) => $this->withHeader('X-Hardware-Key', 'device-secret')->postJson('/api/hardware/tap', [
            'identifier' => $card, 'method' => 'rfid', 'occurred_at' => '2026-10-05 '.$time,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-05 09:05:00', 'Asia/Manila'));
        $tap('ROOM-CARD-ONE', '08:00:00')->assertOk()->assertJsonPath('action', 'IN')->assertJsonPath('school_attendance_warning', true);
        $tap('ROOM-CARD-TWO', '08:40:00')->assertOk()->assertJsonPath('action', 'ROOM RESERVED')
            ->assertJsonPath('display_time', '09:00 AM');
        $tap('ROOM-CARD-TWO', '09:00:00')->assertOk()->assertJsonPath('action', 'IN');

        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $faculty[0]->id, 'time_out' => null, 'room_release_reason' => 'next_session_started']);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $faculty[1]->id, 'time_in' => '09:00:00', 'room_code' => 'TEST-ROOM']);
        Carbon::setTestNow();
    }

    public function test_next_faculty_can_start_on_time_when_previous_faculty_never_tapped_but_not_early(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $first = Employee::create(['employee_no' => 'ROOM-RESERVE-1', 'first_name' => 'Acibar', 'last_name' => 'Faculty', 'faculty_rank_id' => $rank->id, 'rate_type' => 'hourly', 'rate_amount' => $rank->rate_amount, 'status' => 'active']);
        $next = Employee::create(['employee_no' => 'ROOM-RESERVE-2', 'first_name' => 'Morata', 'last_name' => 'Faculty', 'faculty_rank_id' => $rank->id, 'rate_type' => 'hourly', 'rate_amount' => $rank->rate_amount, 'status' => 'active']);
        $first->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'subject' => 'ITEC 100', 'section' => 'BSIT 1A', 'class_mode' => 'lecture', 'start_time' => '13:00', 'end_time' => '13:10', 'break_minutes' => 0, 'room_id' => $this->room->id]);
        $next->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'subject' => 'ITEC 101', 'section' => 'BSIT 1B', 'class_mode' => 'lecture', 'start_time' => '13:10', 'end_time' => '13:20', 'break_minutes' => 0, 'room_id' => $this->room->id]);
        $first->rfidCards()->create(['rfid_uid' => 'RESERVE-FIRST', 'status' => 'active']);
        $next->rfidCards()->create(['rfid_uid' => 'RESERVE-NEXT', 'status' => 'active']);
        $tap = fn (string $card, string $time) => $this->withHeader('X-Hardware-Key', 'device-secret')->postJson('/api/hardware/tap', ['identifier' => $card, 'method' => 'rfid', 'occurred_at' => '2026-10-05 '.$time]);

        Carbon::setTestNow(Carbon::parse('2026-10-05 13:20:00', 'Asia/Manila'));
        $tap('RESERVE-NEXT', '13:05:00')->assertOk()->assertJsonPath('action', 'ROOM RESERVED');
        $tap('RESERVE-NEXT', '13:10:00')->assertOk()->assertJsonPath('action', 'IN');
        $tap('RESERVE-FIRST', '13:10:01')->assertOk()->assertJsonPath('action', 'ROOM OCCUPIED');
        $this->assertDatabaseCount('attendance_logs', 1);
        Carbon::setTestNow();
    }

    public function test_faculty_cannot_tap_in_from_a_device_assigned_to_the_wrong_room(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $otherRoom = Room::create(['code' => 'OTHER-ROOM', 'is_active' => true]);
        $otherDevice = AttendanceDevice::create(['code' => 'OTHER-DEVICE', 'name' => 'Other TFT', 'is_active' => true]);
        $otherDevice->assignments()->create(['room_id' => $otherRoom->id, 'effective_from' => '2020-01-01 00:00:00']);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $employee = Employee::create(['employee_no' => 'WRONG-ROOM', 'first_name' => 'Wrong', 'last_name' => 'Room',
            'faculty_rank_id' => $rank->id, 'rate_type' => 'hourly', 'rate_amount' => $rank->rate_amount, 'status' => 'active']);
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'subject' => 'ITEC 101', 'section' => 'BSIT 1A',
            'class_mode' => 'lecture', 'start_time' => '08:00', 'end_time' => '10:00', 'break_minutes' => 0, 'room_id' => $this->room->id]);
        $employee->rfidCards()->create(['rfid_uid' => 'WRONG-ROOM-CARD', 'status' => 'active']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'Asia/Manila'));

        $this->withHeaders(['X-Hardware-Key' => 'device-secret', 'X-Device-Code' => 'OTHER-DEVICE'])->postJson('/api/hardware/tap', [
            'identifier' => 'WRONG-ROOM-CARD', 'method' => 'rfid',
        ])->assertOk()->assertJsonPath('action', 'WRONG ROOM')->assertJsonPath('room', 'OTHER-ROOM')->assertJsonPath('expected_room', 'TEST-ROOM');
        $this->assertDatabaseCount('attendance_logs', 0);
        Carbon::setTestNow();
    }
}

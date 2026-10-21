<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FacultyRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfid_list_requires_the_hardware_key(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $this->getJson('/api/hardware/rfid-cards')->assertUnauthorized();
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

    public function test_hardware_allows_time_out_after_three_minutes(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $startedAt = Carbon::parse('2026-09-21 08:00:00', 'Asia/Manila');
        Carbon::setTestNow($startedAt);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $employee = Employee::create([
            'employee_no' => 'COS-COOLDOWN-1',
            'first_name' => 'Three',
            'last_name' => 'Minutes',
            'faculty_rank_id' => $rank->id,
            'rate_type' => 'hourly',
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
        ]);
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'break_minutes' => 0]);
        $employee->rfidCards()->create([
            'rfid_uid' => 'THREE-MINUTE-CARD',
            'status' => 'active',
        ]);
        $tap = fn () => $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson('/api/hardware/tap', [
                'identifier' => 'THREE-MINUTE-CARD',
                'method' => 'rfid',
            ]);

        $tap()->assertOk()->assertJsonPath('action', 'IN');
        Carbon::setTestNow($startedAt->copy()->addMinutes(2)->addSeconds(59));
        $tap()->assertOk()->assertJsonPath('action', 'WAIT');
        Carbon::setTestNow($startedAt->copy()->addMinutes(3));
        $tap()->assertOk()->assertJsonPath('action', 'OUT');

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
        $employee->schedules()->create(['day_of_week' => 2, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'break_minutes' => 0]);
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
                'event_id' => 'device-001-1791245100-0002',
                'occurred_at' => '2026-10-06 08:20:30',
            ])
            ->assertOk()
            ->assertJsonPath('action', 'OUT')
            ->assertJsonPath('display_time', '08:20 AM');

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseCount('hardware_attendance_events', 2);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-06',
            'time_in' => '08:15:30',
            'time_out' => '08:20:30',
        ]);

        Carbon::setTestNow();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\Building;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TerminalIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_id_survives_room_edits_reassignment_and_reactivation(): void
    {
        $building = Building::create(['code' => 'IDENTITY', 'name' => 'Identity', 'is_active' => true]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'A-301', 'is_active' => true]);
        $other = Room::create(['building_id' => $building->id, 'code' => 'RM 1', 'is_active' => true]);
        $device = AttendanceDevice::create(['code' => 'TERM-X3NGEZJROW', 'name' => 'Terminal', 'is_active' => true]);
        $device->assignments()->create(['room_id' => $room->id, 'effective_from' => now()->subDay()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->put(route('settings.rooms.update', $room), [
            'building_id' => $building->id, 'code' => 'A-301 UPDATED', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('TERM-X3NGEZJROW', $device->fresh()->code);

        foreach ([false, true] as $active) {
            $this->put(route('settings.devices.assign', $device), [
                'room_id' => $other->id, 'is_active' => $active, 'code' => 'TERM-UNWANTED',
            ])->assertSessionHasNoErrors();
            $this->assertSame('TERM-X3NGEZJROW', $device->fresh()->code);
            $this->assertSame($active, $device->fresh()->is_active);
        }
        $this->assertSame($other->id, $device->assignments()->whereNull('effective_to')->sole()->room_id);
    }

    public function test_existing_terminal_id_cannot_be_replaced(): void
    {
        $device = AttendanceDevice::create(['code' => 'TERM-X3NGEZJROW', 'name' => 'Terminal', 'is_active' => true]);
        try {
            $device->update(['code' => 'TERM-REPLACED']);
            $this->fail('Changing a permanent terminal ID should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertSame('TERM-X3NGEZJROW', $device->fresh()->code);
    }
}

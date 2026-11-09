<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\Building;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RoomDeviceController extends Controller
{
    public function index()
    {
        $devices = AttendanceDevice::with(['assignments' => fn ($query) => $query->with('room')->latest('effective_from')])
            ->get()
            ->sortBy(function (AttendanceDevice $device) {
                $assignment = $device->assignments->first(fn ($item) => ! $item->effective_to) ?? $device->assignments->first();

                return $assignment?->room?->code ?? "\u{FFFF}";
            }, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('Settings/RoomsDevices', [
            'buildings' => Building::orderBy('name')->get(),
            'rooms' => Room::with('building')->orderBy('building_id')->orderBy('code')->get(),
            'devices' => $devices,
        ]);
    }

    public function storeBuilding(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        Building::create(['name' => trim($data['name']), 'code' => $this->nextBuildingCode($data['name']), 'is_active' => true]);

        return back()->with('success', 'Building added.');
    }

    public function updateBuilding(Request $request, Building $building)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);
        if (! $data['is_active'] && $building->rooms()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['building' => 'Set all rooms in this building inactive before deactivating it.']);
        }
        $building->update(['name' => trim($data['name']), 'is_active' => $data['is_active']]);

        return back()->with('success', 'Building updated.');
    }

    public function storeRoom(Request $request)
    {
        $data = $request->validate(['building_id' => ['required', Rule::exists('buildings', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ._-]+$/', 'unique:rooms,code']]);
        Room::create([...$data, 'code' => strtoupper(trim($data['code'])), 'is_active' => true]);

        return back()->with('success', 'Room added.');
    }

    public function updateRoom(Request $request, Room $room)
    {
        $data = $request->validate(['building_id' => ['required', Rule::exists('buildings', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:40', Rule::unique('rooms', 'code')->ignore($room)], 'is_active' => ['required', 'boolean']]);
        $room->update([...$data, 'code' => strtoupper(trim($data['code']))]);

        return back()->with('success', 'Room updated.');
    }

    public function storeDevice(Request $request)
    {
        $data = $request->validate(['room_id' => ['required', Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNotNull('building_id'))]]);
        $this->ensureRoomHasNoTerminal((int) $data['room_id']);
        DB::transaction(function () use ($data, $request) {
            $device = AttendanceDevice::create(['code' => $this->nextDeviceCode(), 'name' => 'Classroom terminal', 'is_active' => true]);
            $device->assignments()->create(['room_id' => $data['room_id'], 'effective_from' => now(), 'assigned_by' => $request->user()->id]);
        });

        return back()->with('success', 'Classroom terminal added and assigned. Copy its Terminal ID into the terminal configuration.');
    }

    public function assign(Request $request, AttendanceDevice $device)
    {
        $data = $request->validate(['room_id' => ['required', Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNotNull('building_id'))], 'is_active' => ['required', 'boolean']]);
        $this->ensureRoomHasNoTerminal((int) $data['room_id'], $device->id);
        DB::transaction(function () use ($device, $data, $request) {
            $now = now();
            $current = $device->assignments()->whereNull('effective_to')->lockForUpdate()->first();
            if (! $current || (int) $current->room_id !== (int) $data['room_id']) {
                $device->assignments()->whereNull('effective_to')->update(['effective_to' => $now]);
                $device->assignments()->create(['room_id' => $data['room_id'], 'effective_from' => $now, 'assigned_by' => $request->user()->id]);
            }
            $device->update(['is_active' => $data['is_active']]);
        });

        return back()->with('success', 'Device room assignment updated.');
    }

    public function destroyBuilding(Building $building)
    {
        if ($building->rooms()->exists()) {
            throw ValidationException::withMessages(['building' => 'Move or delete this building’s rooms before deleting the building.']);
        }

        $building->delete();

        return back()->with('success', 'Building deleted.');
    }

    public function destroyRoom(Room $room)
    {
        $isUsed = DB::table('faculty_schedules')->where('room_id', $room->id)->exists()
            || DB::table('attendance_logs')->where('room_id', $room->id)->exists()
            || DB::table('attendance_device_room_assignments')->where('room_id', $room->id)->exists()
            || (Schema::hasTable('class_session_room_changes') && DB::table('class_session_room_changes')->where('original_room_id', $room->id)->orWhere('assigned_room_id', $room->id)->exists());
        if ($isUsed) {
            throw ValidationException::withMessages(['room' => 'This room is used by schedules or attendance history. Set it inactive instead to preserve those records.']);
        }

        $room->delete();

        return back()->with('success', 'Room deleted.');
    }

    public function destroyDevice(AttendanceDevice $device)
    {
        $device->delete();

        return back()->with('success', 'Classroom terminal deleted.');
    }

    private function nextBuildingCode(string $name): string
    {
        $base = strtoupper(Str::limit(Str::slug($name, '-'), 34, '')) ?: 'BUILDING';
        $code = $base;
        $suffix = 2;

        while (Building::where('code', $code)->exists()) {
            $code = Str::limit($base, 34 - strlen((string) $suffix), '').'-'.$suffix++;
        }

        return $code;
    }

    private function nextDeviceCode(): string
    {
        do {
            // This is the terminal identity, not its display type. Existing
            // TFT-prefixed IDs remain valid; new IDs use a neutral prefix.
            $code = 'TERM-'.strtoupper(Str::random(10));
        } while (AttendanceDevice::where('code', $code)->exists());

        return $code;
    }

    private function ensureRoomHasNoTerminal(int $roomId, ?int $exceptDeviceId = null): void
    {
        $query = DB::table('attendance_device_room_assignments')
            ->join('attendance_devices', 'attendance_devices.id', '=', 'attendance_device_room_assignments.attendance_device_id')
            ->where('attendance_device_room_assignments.room_id', $roomId)
            ->whereNull('attendance_device_room_assignments.effective_to')
            ->where('attendance_devices.is_active', true);
        if ($exceptDeviceId) {
            $query->where('attendance_devices.id', '!=', $exceptDeviceId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['room_id' => 'This room already has an active classroom terminal.']);
        }
    }
}

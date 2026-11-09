<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\Building;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RoomDeviceController extends Controller
{
    public function index()
    {
        $devices = AttendanceDevice::with(['assignments' => fn ($query) => $query->with('room')->latest('effective_from')])->orderBy('name')->get();

        return Inertia::render('Settings/RoomsDevices', [
            'buildings' => Building::orderBy('code')->get(),
            'rooms' => Room::with('building')->orderBy('building_id')->orderBy('code')->get(),
            'devices' => $devices,
        ]);
    }

    public function storeBuilding(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ._-]+$/', 'unique:buildings,code'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        Building::create([...$data, 'code' => strtoupper(trim($data['code'])), 'is_active' => true]);

        return back()->with('success', 'Building added.');
    }

    public function updateBuilding(Request $request, Building $building)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('buildings', 'code')->ignore($building)],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);
        if (! $data['is_active'] && $building->rooms()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['building' => 'Set all rooms in this building inactive before deactivating it.']);
        }
        $building->update([...$data, 'code' => strtoupper(trim($data['code']))]);

        return back()->with('success', 'Building updated.');
    }

    public function storeRoom(Request $request)
    {
        $data = $request->validate(['building_id' => ['required', Rule::exists('buildings', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ._-]+$/', 'unique:rooms,code'], 'name' => ['nullable', 'string', 'max:120']]);
        Room::create([...$data, 'code' => strtoupper(trim($data['code'])), 'is_active' => true]);

        return back()->with('success', 'Room added.');
    }

    public function updateRoom(Request $request, Room $room)
    {
        $data = $request->validate(['building_id' => ['required', Rule::exists('buildings', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:40', Rule::unique('rooms', 'code')->ignore($room)], 'name' => ['nullable', 'string', 'max:120'], 'is_active' => ['required', 'boolean']]);
        $room->update([...$data, 'code' => strtoupper(trim($data['code']))]);

        return back()->with('success', 'Room updated.');
    }

    public function storeDevice(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:attendance_devices,code'], 'name' => ['required', 'string', 'max:120'], 'room_id' => ['required', Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNotNull('building_id'))]]);
        DB::transaction(function () use ($data, $request) {
            $device = AttendanceDevice::create(['code' => strtoupper($data['code']), 'name' => $data['name'], 'is_active' => true]);
            $device->assignments()->create(['room_id' => $data['room_id'], 'effective_from' => now(), 'assigned_by' => $request->user()->id]);
        });

        return back()->with('success', 'Attendance device added and assigned.');
    }

    public function assign(Request $request, AttendanceDevice $device)
    {
        $data = $request->validate(['room_id' => ['required', Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNotNull('building_id'))], 'is_active' => ['required', 'boolean']]);
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
}

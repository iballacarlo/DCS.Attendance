<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceRoomAssignment;
use App\Models\ClassSessionRoomChange;
use App\Models\FacultySchedule;
use App\Models\Room;
use Illuminate\Support\Carbon;

class RoomAttendanceService
{
    public function deviceRoom(string $deviceCode, Carbon $at): array
    {
        $device = AttendanceDevice::where('code', strtoupper(trim($deviceCode)))->where('is_active', true)->first();
        if (! $device) {
            throw new \DomainException('Unknown or inactive attendance device.');
        }
        $assignment = AttendanceDeviceRoomAssignment::with('room.building')
            ->where('attendance_device_id', $device->id)
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->latest('effective_from')->first();
        if (! $assignment || ! $assignment->room?->is_active || ! $assignment->room->building?->is_active) {
            throw new \DomainException('This attendance device is not assigned to an active room.');
        }
        $device->forceFill(['last_seen_at' => now()])->save();

        return [$device, $assignment->room];
    }

    public function effectiveRoom(FacultySchedule $schedule, Carbon|string $date): ?Room
    {
        $day = Carbon::parse($date, 'Asia/Manila')->toDateString();
        $change = ClassSessionRoomChange::with('room')
            ->where('faculty_schedule_id', $schedule->id)->whereDate('session_date', $day)
            ->whereIn('status', ['approved', 'accepted'])->first();

        return $change?->room ?? ($schedule->room_id ? Room::find($schedule->room_id) : null);
    }
}

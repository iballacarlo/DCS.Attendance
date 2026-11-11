<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\Room;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RoomAwareClassAttendanceService
{
    public function record(Employee $employee, string $method, Carbon $occurredAt, Room $deviceRoom): array
    {
        return DB::transaction(function () use ($employee, $method, $occurredAt, $deviceRoom) {
            app(SemesterManagementService::class)->lock();
            Room::whereKey($deviceRoom->id)->lockForUpdate()->firstOrFail();
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $now = $occurredAt->copy()->setTimezone('Asia/Manila');
            $date = $now->toDateString();
            SemesterScheduleService::assertRecordable($now);
            $logs = AttendanceLog::where('employee_id', $employee->id)->where('attendance_date', $date)
                ->where('is_legacy', false)->where('schedule_type', 'class')->whereNull('voided_at')->lockForUpdate()->get();
            // Include the room's existing class logs when evaluating schedule
            // reservations. A prior faculty who has already recorded a valid
            // OUT no longer reserves the room, even if the next class has not
            // reached its scheduled start yet.
            $roomLogs = AttendanceLog::where('room_id', $deviceRoom->id)->where('attendance_date', $date)
                ->where('is_legacy', false)->where('schedule_type', 'class')->whereNull('voided_at')
                ->lockForUpdate()->get();
            $open = $logs->whereNotNull('time_in')->whereNull('time_out')->sortByDesc('scheduled_start')->first();
            if ($open) {
                return $this->recordOut($open, $method, $now, $deviceRoom);
            }

            $rooms = app(RoomAttendanceService::class);
            // First find the faculty's classes that are actually eligible at
            // this time, regardless of terminal room. This lets the device
            // distinguish "no class" from "you are in the wrong room".
            $available = SemesterScheduleService::schedules($employee, $now)->where('schedule_type', 'class')
                ->where('day_of_week', $now->dayOfWeek)->sortBy('start_time')->filter(function ($schedule) use ($now, $date, $logs) {
                    $start = Carbon::parse($date.' '.$schedule->start_time, 'Asia/Manila');
                    $end = Carbon::parse($date.' '.$schedule->end_time, 'Asia/Manila');

                    return $now->gte($start->copy()->subMinutes(30)) && $now->lt($end)
                        && ! $logs->contains('faculty_schedule_id', $schedule->id);
                });
            $eligible = $available->filter(fn ($schedule) => $rooms->effectiveRoom($schedule, $date)?->id === $deviceRoom->id);
            $schedule = $eligible->first(fn ($item) => $now->gte(Carbon::parse($date.' '.$item->start_time, 'Asia/Manila'))) ?? $eligible->first();
            if (! $schedule) {
                $scheduledElsewhere = $available->first(fn ($item) => $rooms->effectiveRoom($item, $date)?->id !== $deviceRoom->id);
                if ($scheduledElsewhere) {
                    $expectedRoom = $rooms->effectiveRoom($scheduledElsewhere, $date);
                    if ($expectedRoom) {
                        return [...$this->result('WRONG ROOM', 'You are scheduled in '.$expectedRoom->code.', not '.$deviceRoom->code.'.', null, $deviceRoom),
                            'expected_room' => $expectedRoom->code];
                    }

                    return $this->result('ROOM NOT ASSIGNED', 'Your scheduled class does not have a room assignment. Ask an administrator to update the schedule.', null, $deviceRoom);
                }

                // A late tap from an earlier room session must never be able to
                // take back a room already in use by the next faculty member.
                $expiredHere = SemesterScheduleService::schedules($employee, $now)->where('schedule_type', 'class')
                    ->where('day_of_week', $now->dayOfWeek)
                    ->filter(fn ($item) => $rooms->effectiveRoom($item, $date)?->id === $deviceRoom->id)
                    ->filter(fn ($item) => $now->gte(Carbon::parse($date.' '.$item->end_time, 'Asia/Manila')))
                    ->sortByDesc('end_time')->first();
                $activeOccupant = $this->openRoomLogs($deviceRoom, $date)
                    ->first(fn ($log) => $log->employee_id !== $employee->id);
                if ($expiredHere && $activeOccupant) {
                    return $this->result('ROOM OCCUPIED', 'This room is now in use by '.$activeOccupant->employee?->full_name.'. Your scheduled class has already ended.', null, $deviceRoom);
                }

                return $this->result('NO CLASS', 'No eligible class is scheduled for you in '.$deviceRoom->code.'.', null, $deviceRoom);
            }
            $start = Carbon::parse($date.' '.$schedule->start_time, 'Asia/Manila');
            $roomSessions = $this->roomSessions($deviceRoom, $now);

            // A room remains reserved for an earlier scheduled session even if
            // its faculty has not tapped yet. This is what prevents a later
            // faculty member from claiming the room during the early-IN window.
            if ($now->lt($start)) {
                $reservation = $roomSessions->filter(fn ($session) => $session['employee_id'] !== $employee->id
                    && $session['start']->lt($start) && $session['end']->gt($now)
                    && ! $roomLogs->contains(function ($log) use ($session) {
                        return (int) $log->employee_id === (int) $session['employee_id']
                            && (int) $log->faculty_schedule_id === (int) $session['schedule']->id
                            && ($log->time_out !== null || $log->room_released_at !== null);
                    }))
                    ->sortBy(fn ($session) => $session['end']->timestamp)->first();
                if ($reservation) {
                    return [...$this->result('ROOM RESERVED', $reservation['employee']->full_name.' is scheduled in '.$deviceRoom->code
                        .' until '.$reservation['end']->format('h:i A').'. Your IN is available at '.$start->format('h:i A').'.', null, $deviceRoom),
                        'time' => $start->format('h:i A'), 'available_at' => $start->format('h:i A')];
                }
            }

            // A delayed tap from an earlier schedule must never displace a
            // later faculty member who has already started using the room.
            // The existing open-log check below covers the common case, while
            // this schedule check also handles a previous faculty who forgot
            // to tap IN but is still the scheduled occupant of the room.
            $scheduledLater = $roomSessions
                ->filter(fn ($session) => $session['employee_id'] !== $employee->id
                    && $session['start']->lte($now)
                    && $session['start']->gte($start)
                    && $session['end']->gt($now))
                ->sortBy('start')->first();
            if ($scheduledLater) {
                return $this->result('ROOM OCCUPIED', 'Room '.$deviceRoom->code.' is currently reserved for '.$scheduledLater['employee']->full_name.'.', null, $deviceRoom);
            }

            $occupants = $this->openRoomLogs($deviceRoom, $date);
            // At the new session's start, only an earlier open session may be
            // released. A later/equal session is an actual room conflict and
            // must never be displaced by a delayed earlier tap.
            $blocking = $occupants->first(function ($occupant) use ($start) {
                return Carbon::parse($occupant->attendance_date.' '.$occupant->scheduled_start, 'Asia/Manila')->gte($start);
            });
            if ($blocking) {
                return $this->result('ROOM OCCUPIED', 'Room '.$deviceRoom->code.' is currently in use by '.$blocking->employee?->full_name.'.', null, $deviceRoom);
            }
            if ($now->gte($start)) {
                $occupants->filter(function ($occupant) use ($start) {
                    return Carbon::parse($occupant->attendance_date.' '.$occupant->scheduled_start, 'Asia/Manila')->lt($start);
                })->each(fn ($occupant) => $occupant->update([
                    'room_released_at' => $now,
                    'room_release_reason' => 'next_session_started',
                ]));
            }
            $schoolIn = EntranceAttendance::where('employee_id', $employee->id)->whereDate('attendance_date', $date)
                ->whereNotNull('time_in')->where('time_in', '<=', $now->format('H:i:s'))->exists();
            $warning = ! $schoolIn;
            $log = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => $date,
                ...ClassAttendanceService::snapshot($schedule), 'room_id' => $deviceRoom->id, 'room_code' => $deviceRoom->code,
                'time_in' => $now->format('H:i:s'), 'method_in' => $method, 'school_attendance_warning' => $warning]);
            AttendanceCalculator::recalculate($log);

            return $this->result('IN', 'Scheduled class IN recorded.'.($warning ? ' Warning: no School IN was recorded before this tap.' : ''), $log->fresh(), $deviceRoom, $warning, $now->format('h:i A'));
        });
    }

    private function recordOut(AttendanceLog $open, string $method, Carbon $now, Room $room): array
    {
        $date = $now->toDateString();
        if ((int) $open->room_id !== (int) $room->id) {
            return $this->result('WRONG ROOM', 'Return to '.($open->room_code ?: 'the original room').' to record this class OUT.', $open, $room);
        }
        if ($open->room_released_at) {
            return $this->result('FOR REVIEW', 'This missing OUT was released for the next class. Ask the administrator to correct it.', $open, $room);
        }
        $available = Carbon::parse($date.' '.$open->scheduled_end, 'Asia/Manila')->subMinutes(10);
        if ($now->lt($available)) {
            return [...$this->result('WAIT', 'Class OUT is available 10 minutes before the scheduled end.', $open, $room),
                'time' => $available->format('h:i A'), 'available_at' => $available->format('h:i A'),
                'time_out_available_at' => (string) $available->timestamp];
        }
        $takenOver = AttendanceLog::where('room_id', $room->id)->where('attendance_date', $date)
            ->whereNull('voided_at')->whereNotNull('time_in')->where('id', '!=', $open->id)->where('scheduled_start', '>=', $open->scheduled_end)->exists();
        if ($takenOver) {
            $open->update(['room_released_at' => $now, 'room_release_reason' => 'next_session_started']);

            return $this->result('FOR REVIEW', 'The next room session has started. Ask the administrator to correct the missing OUT.', $open, $room);
        }
        $open->update(['time_out' => $now->format('H:i:s'), 'method_out' => $method]);
        AttendanceCalculator::recalculate($open);

        return $this->result('OUT', 'Scheduled class OUT recorded.', $open->fresh(), $room, $open->school_attendance_warning, $now->format('h:i A'));
    }

    private function result(string $action, string $message, ?AttendanceLog $log, Room $room, bool $warning = false, string $time = ''): array
    {
        return ['action' => $action, 'message' => $message, 'time' => $time, 'log' => $log,
            'room' => $room->code, 'school_attendance_warning' => $warning];
    }

    private function openRoomLogs(Room $room, string $date)
    {
        return AttendanceLog::with('employee')->where('room_id', $room->id)->where('attendance_date', $date)
            ->whereNull('voided_at')->whereNotNull('time_in')->whereNull('time_out')->whereNull('room_released_at')
            ->lockForUpdate()->get();
    }

    private function roomSessions(Room $room, Carbon $now)
    {
        $date = $now->toDateString();
        $rooms = app(RoomAttendanceService::class);

        return Employee::withoutGlobalScopes()->where('status', 'active')->whereNull('archived_at')->get()
            ->flatMap(function (Employee $faculty) use ($now, $date, $room, $rooms) {
                return SemesterScheduleService::schedules($faculty, $now)->where('schedule_type', 'class')
                    ->where('day_of_week', $now->dayOfWeek)
                    ->filter(fn ($schedule) => $rooms->effectiveRoom($schedule, $date)?->id === $room->id)
                    ->map(fn ($schedule) => [
                        'employee_id' => $faculty->id,
                        'employee' => $faculty,
                        'schedule' => $schedule,
                        'start' => Carbon::parse($date.' '.$schedule->start_time, 'Asia/Manila'),
                        'end' => Carbon::parse($date.' '.$schedule->end_time, 'Asia/Manila'),
                    ]);
            });
    }
}

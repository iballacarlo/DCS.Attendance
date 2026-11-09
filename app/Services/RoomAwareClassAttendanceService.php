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
                ->where('is_legacy', false)->where('schedule_type', 'class')->lockForUpdate()->get();
            $open = $logs->whereNotNull('time_in')->whereNull('time_out')->sortByDesc('scheduled_start')->first();
            if ($open) {
                return $this->recordOut($open, $method, $now, $deviceRoom);
            }

            $rooms = app(RoomAttendanceService::class);
            $eligible = SemesterScheduleService::schedules($employee, $now)->where('schedule_type', 'class')
                ->where('day_of_week', $now->dayOfWeek)->sortBy('start_time')->filter(function ($schedule) use ($now, $date, $logs, $deviceRoom, $rooms) {
                    $start = Carbon::parse($date.' '.$schedule->start_time, 'Asia/Manila');
                    $end = Carbon::parse($date.' '.$schedule->end_time, 'Asia/Manila');

                    return $rooms->effectiveRoom($schedule, $date)?->id === $deviceRoom->id
                        && $now->gte($start->copy()->subMinutes(30)) && $now->lt($end)
                        && ! $logs->contains('faculty_schedule_id', $schedule->id);
                });
            $schedule = $eligible->first(fn ($item) => $now->gte(Carbon::parse($date.' '.$item->start_time, 'Asia/Manila'))) ?? $eligible->first();
            if (! $schedule) {
                return $this->result('NO CLASS', 'No eligible class is scheduled for you in '.$deviceRoom->code.'.', null, $deviceRoom);
            }
            $start = Carbon::parse($date.' '.$schedule->start_time, 'Asia/Manila');
            $occupants = AttendanceLog::where('room_id', $deviceRoom->id)->where('attendance_date', $date)
                ->whereNotNull('time_in')->whereNull('time_out')->whereNull('room_released_at')->lockForUpdate()->get();
            if ($occupants->isNotEmpty() && $now->lt($start)) {
                return $this->result('ROOM OCCUPIED', 'The previous faculty must record OUT before your early IN.', null, $deviceRoom);
            }
            foreach ($occupants as $occupant) {
                $occupant->update(['room_released_at' => $now, 'room_release_reason' => 'next_session_started']);
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
            ->whereNotNull('time_in')->where('id', '!=', $open->id)->where('scheduled_start', '>=', $open->scheduled_end)->exists();
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
}

<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use Illuminate\Support\Carbon;

class AttendanceCalculator
{
    public static function recalculate(AttendanceLog $log): void
    {
        if ($log->is_legacy) {
            return;
        } // Preserve the original daily audit source.
        $seconds = self::workedSeconds($log);
        $log->worked_seconds = $seconds;
        $log->late_minutes = 0;
        $log->undertime_minutes = 0;
        $log->total_hours = round($seconds / 3600, 4);
        $log->status = $log->time_in && $log->time_out ? 'present' : 'incomplete';
        $log->save();
    }

    public static function workedSeconds(AttendanceLog $log): int
    {
        if ($log->is_legacy || ! $log->time_in || ! $log->time_out || ! $log->scheduled_start || ! $log->scheduled_end) {
            return 0;
        }
        $date = $log->attendance_date;
        $start = max(Carbon::parse($date.' '.$log->time_in, 'Asia/Manila')->timestamp,
            Carbon::parse($date.' '.$log->scheduled_start, 'Asia/Manila')->timestamp);
        $end = min(Carbon::parse($date.' '.$log->time_out, 'Asia/Manila')->timestamp,
            Carbon::parse($date.' '.$log->scheduled_end, 'Asia/Manila')->timestamp);

        return max(0, $end - $start);
    }

    public static function scheduleFor(AttendanceLog $log): ?FacultySchedule
    {
        return self::schedulesFor($log)->first();
    }

    public static function schedulesFor(AttendanceLog $log)
    {
        return $log->employee?->schedules()
            ->where('schedule_type', 'class')->where('day_of_week', Carbon::parse($log->attendance_date, 'Asia/Manila')->dayOfWeek)
            ->orderBy('start_time')
            ->get() ?? collect();
    }

    public static function hasSchedule(Employee $employee, Carbon|string $date): bool
    {
        $attendanceDate = $date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila');

        return $employee->schedules()
            ->where('schedule_type', 'class')->where('day_of_week', $attendanceDate->dayOfWeek)
            ->exists();
    }

    public static function scheduledHours(FacultySchedule $schedule): float
    {
        return round(self::scheduledMinutes($schedule) / 60, 2);
    }

    public static function scheduledHoursForDay(Employee $employee, Carbon|string $date): float
    {
        $attendanceDate = $date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila');

        $breaks = $employee->scheduleBreaks()->where('schedule_type', 'class')->where('day_of_week', $attendanceDate->dayOfWeek)->get();

        return $employee->schedules()
            ->where('schedule_type', 'class')->where('day_of_week', $attendanceDate->dayOfWeek)
            ->get()
            ->sum(fn (FacultySchedule $schedule) => self::scheduledMinutes($schedule, $breaks) / 60);
    }

    private static function appendRemark(?string $remarks, string $message): string
    {
        return str_contains((string) $remarks, $message) ? (string) $remarks : trim((string) $remarks.' '.$message);
    }

    private static function scheduledMinutes(FacultySchedule $schedule, $breaks = null): int
    {
        $start = Carbon::parse('2000-01-01 '.$schedule->start_time, 'Asia/Manila');
        $end = Carbon::parse('2000-01-01 '.$schedule->end_time, 'Asia/Manila');

        return self::minutesExcludingBreaks($start, $end, $breaks ?? collect(), $schedule->break_minutes);
    }

    private static function minutesExcludingBreaks(Carbon $start, Carbon $end, $breaks, int $legacyBreakMinutes): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $intervals = $breaks->map(function ($break) use ($start, $end) {
            $breakStart = Carbon::parse($start->toDateString().' '.$break->start_time, 'Asia/Manila');
            $breakEnd = Carbon::parse($start->toDateString().' '.$break->end_time, 'Asia/Manila');

            return [max($start->timestamp, $breakStart->timestamp), min($end->timestamp, $breakEnd->timestamp)];
        })->filter(fn ($interval) => $interval[1] > $interval[0])->sortBy(fn ($interval) => $interval[0]);

        $excludedSeconds = 0;
        $coveredUntil = $start->timestamp;
        foreach ($intervals as [$from, $to]) {
            $excludedSeconds += max(0, $to - max($from, $coveredUntil));
            $coveredUntil = max($coveredUntil, $to);
        }

        return max(0, (int) (($end->timestamp - $start->timestamp - $excludedSeconds) / 60) - ($breaks->isEmpty() ? $legacyBreakMinutes : 0));
    }
}

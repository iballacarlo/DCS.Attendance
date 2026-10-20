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
        $schedules = self::schedulesFor($log);

        if ($schedules->isEmpty()) {
            $log->late_minutes = 0;
            $log->undertime_minutes = 0;
            $log->total_hours = 0;
            $log->status = 'incomplete';
            $log->remarks = self::appendRemark($log->remarks, 'No schedule configured for this day; no payable hours counted.');
            $log->save();

            return;
        }

        $scheduledIn = Carbon::parse($log->attendance_date.' '.$schedules->first()->start_time, 'Asia/Manila');
        $scheduledOut = Carbon::parse($log->attendance_date.' '.$schedules->last()->end_time, 'Asia/Manila');
        $breaks = $log->employee->scheduleBreaks()->where('day_of_week', $scheduledIn->dayOfWeek)->get();
        $late = 0;
        $undertime = 0;
        $workedMinutes = 0;
        $scheduledMinutes = (int) $schedules->sum(function (FacultySchedule $schedule) use ($breaks) {
            return self::scheduledMinutes($schedule, $breaks);
        });

        if ($log->time_in) {
            $actualIn = Carbon::parse($log->attendance_date.' '.$log->time_in, 'Asia/Manila');
            $late = $actualIn->greaterThan($scheduledIn) ? $scheduledIn->diffInMinutes($actualIn) : 0;
        }

        if ($log->time_in && $log->time_out) {
            $actualIn = Carbon::parse($log->attendance_date.' '.$log->time_in, 'Asia/Manila');
            $actualOut = Carbon::parse($log->attendance_date.' '.$log->time_out, 'Asia/Manila');

            // Count only actual overlap with each assigned class block. Gaps between blocks are never payable.
            $workedMinutes = (int) $schedules->sum(function (FacultySchedule $schedule) use ($log, $actualIn, $actualOut, $breaks) {
                $blockStart = Carbon::parse($log->attendance_date.' '.$schedule->start_time, 'Asia/Manila');
                $blockEnd = Carbon::parse($log->attendance_date.' '.$schedule->end_time, 'Asia/Manila');
                $payableIn = $actualIn->greaterThan($blockStart) ? $actualIn : $blockStart;
                $payableOut = $actualOut->lessThan($blockEnd) ? $actualOut : $blockEnd;

                return self::minutesExcludingBreaks($payableIn, $payableOut, $breaks, $schedule->break_minutes);
            });
            $undertime = $actualOut->lessThan($scheduledOut) ? $actualOut->diffInMinutes($scheduledOut) : 0;
        }

        $penaltyMinutes = $late + $undertime;
        $payableMinutes = $log->time_in && $log->time_out
            ? min($workedMinutes, max(0, $scheduledMinutes - $penaltyMinutes))
            : 0;

        $log->late_minutes = $late;
        $log->undertime_minutes = $undertime;
        $log->total_hours = round($payableMinutes / 60, 4);
        $log->status = ! $log->time_in || ! $log->time_out
            ? 'incomplete'
            : ($late > 0 ? 'late' : ($undertime > 0 ? 'undertime' : 'present'));
        $log->save();
    }

    public static function scheduleFor(AttendanceLog $log): ?FacultySchedule
    {
        return self::schedulesFor($log)->first();
    }

    public static function schedulesFor(AttendanceLog $log)
    {
        return $log->employee?->schedules()
            ->where('day_of_week', Carbon::parse($log->attendance_date, 'Asia/Manila')->dayOfWeek)
            ->orderBy('start_time')
            ->get() ?? collect();
    }

    public static function hasSchedule(Employee $employee, Carbon|string $date): bool
    {
        $attendanceDate = $date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila');

        return $employee->schedules()
            ->where('day_of_week', $attendanceDate->dayOfWeek)
            ->exists();
    }

    public static function scheduledHours(FacultySchedule $schedule): float
    {
        return round(self::scheduledMinutes($schedule) / 60, 2);
    }

    public static function scheduledHoursForDay(Employee $employee, Carbon|string $date): float
    {
        $attendanceDate = $date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila');

        $breaks = $employee->scheduleBreaks()->where('day_of_week', $attendanceDate->dayOfWeek)->get();

        return $employee->schedules()
            ->where('day_of_week', $attendanceDate->dayOfWeek)
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

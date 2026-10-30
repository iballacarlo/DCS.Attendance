<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClassAttendanceService
{
    public static function snapshot(FacultySchedule $schedule): array
    {
        return ['academic_term_id' => $schedule->academic_term_id, 'faculty_schedule_id' => $schedule->id, 'schedule_type' => $schedule->schedule_type, 'class_mode' => $schedule->class_mode, 'subject' => $schedule->subject,
            'section' => $schedule->section, 'scheduled_start' => $schedule->start_time,
            'scheduled_end' => $schedule->end_time, 'is_legacy' => false];
    }

    public function record(Employee $employee, string $method, Carbon $occurredAt): array
    {
        return DB::transaction(function () use ($employee, $method, $occurredAt) {
            app(SemesterManagementService::class)->lock();
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $now = $occurredAt->copy()->setTimezone('Asia/Manila');
            $date = $now->toDateString();
            SemesterScheduleService::assertRecordable($now);
            $logs = AttendanceLog::where('employee_id', $employee->id)->where('attendance_date', $date)
                ->where('is_legacy', false)->where('schedule_type', 'class')->lockForUpdate()->get();
            $latest = $logs->sortByDesc('scheduled_start')->first();
            $open = $latest && $latest->time_in && ! $latest->time_out ? $latest : null;
            $schedules = SemesterScheduleService::schedules($employee, $now)->where('schedule_type', 'class')
                ->where('day_of_week', $now->dayOfWeek)->sortBy('start_time');
            $eligible = $schedules->filter(function ($schedule) use ($now, $date, $logs) {
                $start = Carbon::parse($date.' '.$schedule->start_time, 'Asia/Manila');
                $end = Carbon::parse($date.' '.$schedule->end_time, 'Asia/Manila');
                $conflict = $logs->contains(fn ($log) => $log->faculty_schedule_id !== $schedule->id
                    && $log->scheduled_start < $schedule->end_time && $log->scheduled_end > $schedule->start_time);

                return $now->gte($start->copy()->subMinutes(30)) && $now->lt($end)
                    && ! $conflict && ! $logs->contains('faculty_schedule_id', $schedule->id);
            });
            $schedule = $eligible->first(fn ($item) => $now->gte(Carbon::parse($date.' '.$item->start_time, 'Asia/Manila')))
                ?? $eligible->first();
            if ($open && ($now->lte(Carbon::parse($date.' '.$open->scheduled_end, 'Asia/Manila')) || ! $schedule)) {
                $available = Carbon::parse($date.' '.$open->time_in, 'Asia/Manila')->addMinutes(2);
                if ($now->lt($available)) {
                    return ['action' => 'WAIT', 'message' => 'Please wait before recording time out.', 'time' => $available->format('h:i A'),
                        'available_at' => $available->format('h:i A'), 'time_out_available_at' => (string) $available->timestamp, 'log' => $open];
                }
                $open->update(['time_out' => $now->format('H:i:s'), 'method_out' => $method]);
                AttendanceCalculator::recalculate($open);

                return ['action' => 'OUT', 'message' => 'Scheduled session time out recorded.', 'time' => $now->format('h:i A'), 'log' => $open->fresh()];
            }
            if (! $schedule) {
                return ['action' => 'NO CLASS', 'message' => 'No eligible class. Time in is available 30 minutes before the scheduled session.', 'time' => '', 'log' => null];
            }
            $log = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => $date,
                ...self::snapshot($schedule), 'time_in' => $now->format('H:i:s'), 'method_in' => $method]);
            AttendanceCalculator::recalculate($log);

            return ['action' => 'IN', 'message' => 'Scheduled session time in recorded.', 'time' => $now->format('h:i A'), 'log' => $log->fresh()];
        });
    }
}

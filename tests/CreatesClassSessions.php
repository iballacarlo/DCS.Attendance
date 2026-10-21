<?php

namespace Tests;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use App\Services\ClassAttendanceService;
use Illuminate\Support\Carbon;

trait CreatesClassSessions
{
    private function classLog(array $values): AttendanceLog
    {
        $employee = Employee::findOrFail($values['employee_id']);
        $schedule = $employee->schedules()->where('schedule_type', 'class')
            ->where('day_of_week', Carbon::parse($values['attendance_date'])->dayOfWeek)->firstOrFail();
        if (isset($values['total_hours']) && $values['total_hours'] > 0 && ! isset($values['time_in'])) {
            $values['time_in'] = $schedule->start_time;
            $values['time_out'] = Carbon::parse('2000-01-01 '.$schedule->start_time)->addSeconds((int) ($values['total_hours'] * 3600))->format('H:i:s');
        }
        $log = AttendanceLog::create([...$values, ...ClassAttendanceService::snapshot($schedule)]);
        AttendanceCalculator::recalculate($log);

        return $log->fresh();
    }
}

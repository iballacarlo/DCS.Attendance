<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Employee;
use Illuminate\Support\Carbon;

class SchoolCalendarService
{
    public static function paidEventFor(Employee $employee, Carbon|string $date): ?CalendarEvent
    {
        $day = ($date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila'))->toDateString();

        return CalendarEvent::query()
            ->where(fn ($event) => $event->where('is_paid', true)->orWhere('type', 'regular_holiday'))
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->where(function ($query) use ($employee) {
                $query->where('scope_type', 'campus')
                    ->orWhere(function ($department) use ($employee) {
                        $department->where('scope_type', 'department')
                            ->where('department', $employee->department);
                    })
                    ->orWhere(function ($faculty) use ($employee) {
                        $faculty->where('scope_type', 'faculty')
                            ->whereHas('employees', fn ($employees) => $employees->whereKey($employee->id));
                    });
            })
            ->orderByRaw("CASE type WHEN 'suspension' THEN 1 WHEN 'asynchronous' THEN 2 WHEN 'special_holiday' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->first();
    }
}

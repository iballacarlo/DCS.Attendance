<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

class LeaveService
{
    public static function approvedLeaveFor(Employee $employee, Carbon|string $date): ?LeaveRequest
    {
        $day = ($date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila'))->toDateString();

        return LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->oldest('id')
            ->first();
    }
}

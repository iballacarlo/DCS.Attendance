<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DtrSignatory;
use App\Models\Employee;
use Illuminate\Support\Carbon;

class DtrService
{
    public function build(Employee $employee, Carbon|string $startDate, Carbon|string $endDate): array
    {
        $start = $startDate instanceof Carbon ? $startDate->copy() : Carbon::parse($startDate, 'Asia/Manila');
        $end = $endDate instanceof Carbon ? $endDate->copy() : Carbon::parse($endDate, 'Asia/Manila');
        $employee->loadMissing(['schedules', 'scheduleBreaks']);
        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn ($log) => Carbon::parse($log->attendance_date)->toDateString());
        $rows = collect();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateString = $date->toDateString();
            $schedules = $employee->schedules->where('day_of_week', $date->dayOfWeek)->sortBy('start_time')->values();
            $scheduledHours = round(AttendanceCalculator::scheduledHoursForDay($employee, $date), 4);
            // Keep unscheduled taps in the audit log, but never expose or pay
            // them through the official DTR.
            $log = $scheduledHours > 0 ? $logs->get($dateString) : null;
            $calendarEvent = $scheduledHours > 0 ? SchoolCalendarService::paidEventFor($employee, $date) : null;
            $leave = $scheduledHours > 0 && ! $calendarEvent ? LeaveService::approvedLeaveFor($employee, $date) : null;
            $attendanceException = $calendarEvent || $leave;
            $isPaidException = (bool) $calendarEvent || (bool) ($leave?->leaveType?->is_paid);
            $late = $attendanceException ? 0 : (int) ($log?->late_minutes ?? 0);
            $undertime = $attendanceException ? 0 : (int) ($log?->undertime_minutes ?? 0);
            $payableHours = $attendanceException
                ? ($isPaidException ? $scheduledHours : 0)
                : round((float) ($log?->total_hours ?? 0), 4);
            $variance = $late + $undertime;

            $rows->push([
                'date' => $dateString,
                'day' => $date->day,
                'weekday' => $date->format('D'),
                'has_schedule' => $scheduledHours > 0,
                'scheduled_hours' => $scheduledHours,
                'schedule_label' => $schedules->isEmpty()
                    ? null
                    : $this->shortTime($schedules->first()->start_time).'-'.$this->shortTime($schedules->last()->end_time),
                'am_arrival' => $attendanceException ? null : $this->timeForColumn($log?->time_in, 'am'),
                'am_departure' => $attendanceException ? null : $this->timeForColumn($log?->time_out, 'am'),
                'pm_arrival' => $attendanceException ? null : $this->timeForColumn($log?->time_in, 'pm'),
                'pm_departure' => $attendanceException ? null : $this->timeForColumn($log?->time_out, 'pm'),
                'late_minutes' => $late,
                'undertime_minutes' => $undertime,
                'variance_hours' => intdiv($variance, 60),
                'variance_minutes' => $variance % 60,
                'payable_hours' => $payableHours,
                'margin_hours' => $isPaidException
                    ? $payableHours
                    : ($log?->time_in && $log?->time_out && $variance === 0 ? $payableHours : null),
                'event' => $calendarEvent ? [
                    'id' => $calendarEvent->id,
                    'title' => $calendarEvent->title,
                    'type' => $calendarEvent->type,
                    'label' => $this->eventLabel($calendarEvent->type),
                    'paid' => true,
                ] : ($leave ? [
                    'id' => $leave->id,
                    'title' => $leave->leaveType->name,
                    'type' => 'leave',
                    'label' => strtoupper($leave->leaveType->code.' - LEAVE'),
                    'paid' => (bool) $leave->leaveType->is_paid,
                ] : null),
                'status' => $calendarEvent ? 'paid_event' : ($leave ? ($isPaidException ? 'paid_leave' : 'unpaid_leave') : ($log?->status ?? ($scheduledHours > 0 ? 'absent' : 'not_scheduled'))),
                'time_in' => $attendanceException ? null : $log?->time_in,
                'time_out' => $attendanceException ? null : $log?->time_out,
                'method_in' => $attendanceException ? null : $log?->method_in,
                'method_out' => $attendanceException ? null : $log?->method_out,
            ]);
        }

        $scheduledRows = $rows->where('has_schedule', true);
        $presentRows = $scheduledRows->filter(fn ($row) => ($row['event']['paid'] ?? false) || $row['time_in']);
        $paidRows = $scheduledRows->filter(fn ($row) => $row['payable_hours'] > 0);
        $signatory = DtrSignatory::whereDate('effective_from', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $end->toDateString()))
            ->orderByDesc('effective_from')->first();
        $dtrSignatory = $signatory ? [
            'name' => strtoupper($signatory->name),
            'designation' => 'In Charge',
        ] : null;

        return [
            'employee' => $employee,
            'cutoff' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'rows' => $rows->values(),
            'signatory' => $dtrSignatory,
            'summary' => [
                'scheduled_days' => $scheduledRows->count(),
                'days_present' => $presentRows->count(),
                'paid_days' => $paidRows->count(),
                'absent_days' => $scheduledRows->where('status', 'absent')->count(),
                'total_hours' => round($rows->sum('payable_hours'), 4),
                'late_minutes' => (int) $rows->sum('late_minutes'),
                'undertime_minutes' => (int) $rows->sum('undertime_minutes'),
            ],
        ];
    }

    private function timeForColumn(?string $time, string $period): ?string
    {
        if (! $time) {
            return null;
        }

        $parsed = Carbon::parse('2000-01-01 '.$time, 'Asia/Manila');
        $actualPeriod = $parsed->hour < 12 ? 'am' : 'pm';

        return $actualPeriod === $period ? $parsed->format('H:i') : null;
    }

    private function shortTime(string $time): string
    {
        return Carbon::parse('2000-01-01 '.$time, 'Asia/Manila')->format('g:i A');
    }

    private function eventLabel(string $type): string
    {
        return match ($type) {
            'suspension' => 'SUSPENSION',
            'asynchronous' => 'ASYNCHRONOUS',
            'special_holiday' => 'SPECIAL HOLIDAY',
            default => 'REGULAR HOLIDAY',
        };
    }
}

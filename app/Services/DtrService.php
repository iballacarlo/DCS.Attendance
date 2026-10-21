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
            ->where('is_legacy', false)->orderBy('scheduled_start')->get()->groupBy(fn ($log) => Carbon::parse($log->attendance_date)->toDateString());
        $rows = collect();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateString = $date->toDateString();
            $schedules = $employee->schedules->where('schedule_type', 'class')->where('day_of_week', $date->dayOfWeek)->sortBy('start_time')->values();
            $sessions = $logs->get($dateString, collect());
            $historicalSchedules = $schedules->map(function ($schedule) use ($sessions) {
                $snapshot = $sessions->firstWhere('faculty_schedule_id', $schedule->id);

                return (object) ['id' => $schedule->id, 'start_time' => $snapshot?->scheduled_start ?? $schedule->start_time,
                    'end_time' => $snapshot?->scheduled_end ?? $schedule->end_time];
            });
            foreach ($sessions as $session) {
                if (! $historicalSchedules->contains('id', $session->faculty_schedule_id)) {
                    $historicalSchedules->push((object) ['id' => $session->faculty_schedule_id, 'start_time' => $session->scheduled_start, 'end_time' => $session->scheduled_end]);
                }
            }
            $historicalSchedules = $historicalSchedules->sortBy('start_time')->values();
            $scheduledHours = $historicalSchedules->sum(function ($schedule) use ($dateString) {
                return Carbon::parse($dateString.' '.$schedule->start_time)->diffInSeconds(Carbon::parse($dateString.' '.$schedule->end_time)) / 3600;
            });
            // Persisted snapshots remain authoritative after a schedule edit/removal.
            $seconds = $sessions->sum(fn ($session) => AttendanceCalculator::workedSeconds($session));
            // Form 48 shows only the day's earliest IN and latest OUT.
            // Keep the real tap direction and place each time in its AM/PM column.
            $first = $sessions->filter(fn ($session) => $session->time_in)->sortBy('time_in')->first();
            $last = $sessions->filter(fn ($session) => $session->time_out)->sortByDesc('time_out')->first();
            $hasSchedule = $schedules->isNotEmpty() || $sessions->isNotEmpty();
            $calendarEvent = $hasSchedule ? SchoolCalendarService::paidEventFor($employee, $date) : null;
            $leave = $hasSchedule ? LeaveService::approvedLeaveFor($employee, $date) : null;
            $rows->push([
                'date' => $dateString, 'day' => $date->day, 'weekday' => $date->format('D'),
                'has_schedule' => $hasSchedule, 'scheduled_hours' => round($scheduledHours, 4),
                'schedule_label' => $historicalSchedules->isEmpty() ? null : $this->shortTime($historicalSchedules->first()->start_time).'-'.$this->shortTime($historicalSchedules->last()->end_time),
                'am_arrival' => $this->timeForColumn($first?->time_in, 'am'), 'am_departure' => $this->timeForColumn($last?->time_out, 'am'),
                'pm_arrival' => $this->timeForColumn($first?->time_in, 'pm'), 'pm_departure' => $this->timeForColumn($last?->time_out, 'pm'),
                'late_minutes' => 0, 'undertime_minutes' => 0, 'variance_hours' => 0, 'variance_minutes' => 0,
                'worked_seconds' => $seconds, 'payable_hours' => round($seconds / 3600, 4), 'margin_hours' => round($seconds / 3600, 4),
                'event' => $calendarEvent ? ['id' => $calendarEvent->id, 'title' => $calendarEvent->title, 'type' => $calendarEvent->type, 'label' => $this->eventLabel($calendarEvent->type), 'paid' => false]
                    : ($leave ? ['id' => $leave->id, 'title' => $leave->leaveType->name, 'type' => 'leave', 'label' => strtoupper($leave->leaveType->code).' - LEAVE', 'paid' => false] : null),
                'status' => $sessions->isNotEmpty() ? ($sessions->contains(fn ($session) => ! $session->time_in || ! $session->time_out) ? 'incomplete' : 'present') : ($leave ? 'on_leave' : ($hasSchedule ? 'absent' : 'not_scheduled')),
                'time_in' => $first?->time_in, 'time_out' => $last?->time_out, 'method_in' => $first?->method_in, 'method_out' => $last?->method_out,
                'sessions' => $sessions->map(fn ($session) => [
                    'id' => $session->id, 'faculty_schedule_id' => $session->faculty_schedule_id,
                    'subject' => $session->subject, 'section' => $session->section, 'class_mode' => $session->class_mode,
                    'scheduled_start' => $session->scheduled_start, 'scheduled_end' => $session->scheduled_end,
                    'time_in' => $session->time_in, 'time_out' => $session->time_out,
                    'hours' => round(AttendanceCalculator::workedSeconds($session) / 3600, 4),
                    'status' => $session->status, 'reconstructed' => (bool) $session->legacy_source_id,
                ])->values(),
            ]);
        }

        $scheduledRows = $rows->where('has_schedule', true);
        $presentRows = $scheduledRows->filter(fn ($row) => $row['time_in']);
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
                'worked_seconds' => $rows->sum('worked_seconds'),
                'total_hours' => round($rows->sum('worked_seconds') / 3600, 4),
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
            'official_school_activity' => 'OFFICIAL SCHOOL ACTIVITY',
            'asynchronous' => 'ASYNCHRONOUS',
            'special_holiday' => 'SPECIAL HOLIDAY',
            default => 'REGULAR HOLIDAY',
        };
    }
}

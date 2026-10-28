<?php

namespace App\Services;

use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use Illuminate\Support\Carbon;

class DtrService
{
    public function build(Employee $employee, Carbon|string $startDate, Carbon|string $endDate): array
    {
        $start = Carbon::parse($startDate, 'Asia/Manila')->startOfDay();
        $end = Carbon::parse($endDate, 'Asia/Manila')->startOfDay();
        $logs = EntranceAttendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])->get()->keyBy('attendance_date');
        $rows = collect();
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $log = $logs->get($date->toDateString());
            $intervals = $log ? $log->schedule_snapshot : DailyScheduleService::snapshot($employee, $date);
            $scheduled = array_sum(array_map(fn ($i) => $i[1] - $i[0], $intervals));
            // Keep unscheduled school visits in entrance history only. The saved
            // daily schedule remains authoritative even after schedule edits.
            $log = $scheduled > 0 ? $log : null;
            $complete = $log && $log->time_in && $log->time_out;
            $seconds = 0;
            if ($complete) {
                $in = Carbon::parse('2000-01-01 '.$log->time_in)->secondsSinceMidnight();
                $out = Carbon::parse('2000-01-01 '.$log->time_out)->secondsSinceMidnight();
                foreach ($intervals as [$from, $to]) {
                    $seconds += max(0, min($out, $to) - max($in, $from));
                }
            }
            $computed = $complete && $scheduled > 0;
            $shortfall = $computed ? (int) round(max(0, $scheduled - $seconds) / 60, 0, PHP_ROUND_HALF_UP) : null;
            $leave = $scheduled > 0 ? LeaveService::approvedLeaveFor($employee, $date) : null;
            $event = $scheduled > 0 ? SchoolCalendarService::eventFor($employee, $date) : null;
            $label = $intervals ? Carbon::today()->startOfDay()->addSeconds($intervals[0][0])->format('g:i A').'-'.Carbon::today()->startOfDay()->addSeconds($intervals[count($intervals) - 1][1])->format('g:i A') : null;
            $rows->push([
                'day' => $date->day, 'date' => $date->toDateString(), 'weekday' => $date->format('D'),
                'has_schedule' => $scheduled > 0, 'scheduled_seconds' => $scheduled, 'scheduled_hours' => round($scheduled / 3600, 4), 'schedule_label' => $label,
                'time_in' => $log?->time_in, 'time_out' => $log?->time_out, 'source' => $log?->source,
                'am_arrival' => $this->column($log?->time_in, true), 'pm_arrival' => $this->column($log?->time_in, false),
                'am_departure' => $this->column($log?->time_out, true), 'pm_departure' => $this->column($log?->time_out, false),
                'worked_seconds' => $computed ? $seconds : null, 'worked_hours' => $computed ? round($seconds / 3600, 4) : null,
                'shortfall_minutes' => $shortfall, 'undertime_hours' => $shortfall >= 60 ? intdiv($shortfall, 60) : null,
                'undertime_minutes' => $shortfall > 0 ? $shortfall % 60 : null,
                'margin_hours' => $computed && $shortfall === 0 ? round($seconds / 3600, 4) : null,
                'status' => $log ? ($complete ? 'present' : 'incomplete') : ($leave ? 'on_leave' : ($event ? 'calendar_event' : ($scheduled > 0 ? ($date->lt(Carbon::today('Asia/Manila')) ? 'absent' : 'pending') : 'not_scheduled'))),
                'event' => $event ? ['title' => $event->title, 'type' => $event->type, 'label' => strtoupper(str_replace('_', ' ', $event->type))] : ($leave ? ['title' => $leave->leaveType->name, 'type' => 'leave', 'label' => strtoupper($leave->leaveType->code).' - LEAVE'] : null),
                'sessions' => [],
            ]);
        }
        $byDay = $rows->keyBy('day');
        $formRows = collect(range(1, 31))->map(fn ($day) => array_merge([
            'day' => $day, 'date' => null, 'schedule_label' => null,
            'am_arrival' => null, 'am_departure' => null, 'pm_arrival' => null, 'pm_departure' => null,
            'worked_seconds' => null, 'worked_hours' => null, 'shortfall_minutes' => null,
            'undertime_hours' => null, 'undertime_minutes' => null, 'margin_hours' => null,
        ], $byDay->get($day, []), ['valid_day' => $day <= $start->daysInMonth, 'in_cutoff' => $byDay->has($day)]));
        $signatory = DtrSignatory::whereDate('effective_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $end->toDateString()))->orderByDesc('effective_from')->first();

        return [
            'employee' => $employee, 'rows' => $rows, 'form_rows' => $formRows,
            'cutoff' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'label' => $start->format('F j').'-'.$end->format('j, Y')],
            'signatory' => $signatory ? ['name' => strtoupper($signatory->name), 'designation' => 'In Charge'] : null,
            'summary' => ['scheduled_days' => $rows->where('has_schedule', true)->count(), 'days_present' => $rows->whereNotNull('time_in')->count(),
                'absent_days' => $rows->where('status', 'absent')->count(), 'incomplete_days' => $rows->where('status', 'incomplete')->count(),
                'worked_seconds' => $rows->sum('worked_seconds'), 'total_hours' => round($rows->sum('worked_seconds') / 3600, 4),
                'undertime_minutes' => $rows->sum('shortfall_minutes')],
        ];
    }

    private function column(?string $time, bool $am): ?string
    {
        return $time && ((int) substr($time, 0, 2) < 12) === $am ? substr($time, 0, 5) : null;
    }
}

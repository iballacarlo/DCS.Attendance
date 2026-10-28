<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Carbon;

class TodayScheduleService
{
    public function __construct(private ClassReportService $reports) {}

    public function build(?Employee $employee, Carbon $at, ?string $selectedDate = null): array
    {
        $now = $at->copy()->setTimezone('Asia/Manila');
        $date = $selectedDate ?? $now->toDateString();
        $day = Carbon::parse($date, 'Asia/Manila');
        if (! $employee) {
            return ['items' => [], 'summary' => ['finished' => 0, 'total' => 0]];
        }

        $basis = $this->reports->build(['employee_id' => $employee->id, 'start_date' => $date, 'end_date' => $date]);
        $items = collect($basis['rows'])->map(function (array $row) use ($date, $now) {
            $start = Carbon::parse($date.' '.$row['scheduled_start'], 'Asia/Manila');
            $end = Carbon::parse($date.' '.$row['scheduled_end'], 'Asia/Manila');
            $in = $row['time_in'] ? Carbon::parse($date.' '.$row['time_in'], 'Asia/Manila') : null;
            $out = $row['time_out'] ? Carbon::parse($date.' '.$row['time_out'], 'Asia/Manila') : null;
            $validPair = $in && $out && $out->gt($in);
            $ended = $now->gte($end);
            $status = $row['exclusion'] ? 'not_required' : ($validPair ? 'finished'
                : ($ended ? (($in || $out || in_array($row['status'], ['incomplete', 'complete'], true)) ? 'for_review' : 'absent')
                    : ($in ? 'checked_in' : ($now->gte($start) ? 'awaiting_in' : 'upcoming'))));

            return ['key' => $row['key'], 'schedule_type' => 'class', 'subject' => $row['subject'],
                'section' => $row['section'], 'class_mode' => $row['class_mode'],
                'start_time' => $row['scheduled_start'], 'end_time' => $row['scheduled_end'],
                'time_in' => $row['time_in'], 'time_out' => $row['time_out'], 'status' => $status,
                'is_current' => ! in_array($status, ['finished', 'not_required'], true) && $now->gte($start) && $now->lt($end),
                'late_minutes' => $status === 'finished' ? $row['late_minutes'] : null,
                'undertime_minutes' => $status === 'finished' ? $row['early_departure_minutes'] : null,
                'exclusion' => $row['exclusion']];
        });

        $employee->loadMissing(['schedules', 'scheduleBreaks']);
        $auxiliary = $employee->schedules->where('schedule_type', '!=', 'class')->concat($employee->scheduleBreaks)
            ->where('day_of_week', $day->dayOfWeek)->map(fn ($schedule) => [
                'key' => 'schedule-'.$schedule->schedule_type.'-'.$schedule->id,
                'schedule_type' => $schedule->schedule_type, 'subject' => null, 'section' => null, 'class_mode' => null,
                'start_time' => $schedule->start_time, 'end_time' => $schedule->end_time,
                'time_in' => null, 'time_out' => null, 'status' => null, 'is_current' => false,
                'late_minutes' => null, 'undertime_minutes' => null, 'exclusion' => null,
            ]);
        $eligible = $items->where('status', '!=', 'not_required');

        return ['items' => $items->concat($auxiliary)->sortBy(fn ($item) => $item['start_time'].'|'.$item['key'])->values()->all(),
            'summary' => ['finished' => $eligible->where('status', 'finished')->count(), 'total' => $eligible->count()]];
    }
}

<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceRecordService
{
    public const FILTERS = 'on_time,late,undertime,late_undertime,absent,for_review,checked_in,awaiting_in,upcoming,not_required,not_scheduled,present,complete,incomplete';

    public function classify(?string $in, ?string $out, array $intervals, string $date, ?string $exclusion = null): array
    {
        $now = Carbon::now('Asia/Manila');
        $seconds = fn ($time) => (int) Carbon::parse('2000-01-01 '.$time, 'Asia/Manila')->secondsSinceMidnight();
        $ended = $intervals && Carbon::parse($date, 'Asia/Manila')->startOfDay()->addSeconds($intervals[array_key_last($intervals)][1])->lte($now);
        $begun = $intervals && Carbon::parse($date, 'Asia/Manila')->startOfDay()->addSeconds($intervals[0][0])->lte($now);
        $late = null;
        $under = null;
        if ($exclusion) {
            $status = 'not_required';
        } elseif (($out && ! $in) || ($in && $out && $seconds($out) <= $seconds($in))) {
            $status = 'for_review';
        } elseif (! $intervals) {
            $status = 'not_scheduled';
        } elseif ($in && $out) {
            // Count only scheduled minutes, excluding lunch and schedule gaps.
            $late = (int) ceil(array_sum(array_map(fn ($i) => max(0, min($seconds($in), $i[1]) - $i[0]), $intervals)) / 60);
            $under = (int) ceil(array_sum(array_map(fn ($i) => max(0, $i[1] - max($seconds($out), $i[0])), $intervals)) / 60);
            $status = $late > 0 ? ($under > 0 ? 'late_undertime' : 'late') : ($under > 0 ? 'undertime' : 'on_time');
        } elseif ($in) {
            $status = $ended ? 'for_review' : 'checked_in';
        } else {
            $status = $ended ? 'absent' : ($begun ? 'awaiting_in' : 'upcoming');
        }
        $labels = ['on_time' => 'On time', 'late' => 'Late', 'undertime' => 'Undertime', 'late_undertime' => 'Late & undertime',
            'absent' => 'Absent', 'for_review' => 'For review', 'checked_in' => 'Checked in', 'awaiting_in' => 'Awaiting IN',
            'upcoming' => 'Upcoming', 'not_required' => 'Not required', 'not_scheduled' => 'Not scheduled'];

        return ['attendance_status' => $status, 'attendance_status_label' => $labels[$status],
            'late_minutes_display' => $late, 'undertime_minutes_display' => $under, 'status_reason' => $exclusion];
    }

    public function classRows(Collection $logs, Collection $employees, Carbon $start, Carbon $end, bool $includeAbsent = true): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }
        $report = app(ClassReportService::class)->build(['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'employee_id' => $employees->count() === 1 ? $employees->first()->id : null]);
        $sessions = $report['rows']->filter(fn ($row) => $employees->has($row['employee_id']));
        $byKey = $sessions->keyBy('key');
        $rows = $logs->map(function ($log) use ($byKey) {
            $date = Carbon::parse($log->attendance_date)->toDateString();
            $session = $byKey->get($log->employee_id.'-'.$date.'-'.$log->faculty_schedule_id.'-'.$log->id);
            $intervals = $this->classInterval($log->scheduled_start, $log->scheduled_end);

            return [...$log->toArray(), ...$this->classify($log->time_in, $log->time_out, $intervals, $date, $session['exclusion'] ?? null), 'has_record' => true];
        });
        if ($includeAbsent) {
            foreach ($sessions->where('status', 'missed') as $session) {
                $employee = $employees->get($session['employee_id']);
                $rows->push(['id' => 'absent:class:'.$session['key'], 'employee_id' => $employee->id, 'employee' => $this->identity($employee),
                    'attendance_date' => $session['date'], 'subject' => $session['subject'], 'section' => $session['section'],
                    'scheduled_start' => $session['scheduled_start'], 'scheduled_end' => $session['scheduled_end'],
                    'time_in' => null, 'time_out' => null, 'has_record' => false,
                    ...$this->classify(null, null, $this->classInterval($session['scheduled_start'], $session['scheduled_end']), $session['date'])]);
            }
        }

        return $rows;
    }

    public function schoolRows(Collection $logs, Collection $employees, Carbon $start, Carbon $end): Collection
    {
        $byDay = $logs->keyBy(fn ($log) => $log->employee_id.'|'.Carbon::parse($log->attendance_date)->toDateString());
        $rows = collect();
        foreach ($employees as $employee) {
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $date = $day->toDateString();
                $log = $byDay->get($employee->id.'|'.$date);
                $intervals = $log ? ($log->schedule_snapshot ?? []) : DailyScheduleService::snapshot($employee, $day);
                if (! $log && ($employee->status !== 'active' || ! $intervals || $day->isFuture())) {
                    continue;
                }
                $leave = $intervals ? LeaveService::approvedLeaveFor($employee, $day) : null;
                $event = $intervals ? SchoolCalendarService::eventFor($employee, $day) : null;
                $state = $this->classify($log?->time_in, $log?->time_out, $intervals, $date, $leave ? 'Approved leave' : $event?->title);
                if (! $log && $state['attendance_status'] !== 'absent') {
                    continue;
                }
                $rows->push([...($log?->toArray() ?? ['id' => 'absent:school:'.$employee->id.':'.$date,
                    'employee_id' => $employee->id, 'employee' => $this->identity($employee), 'attendance_date' => $date,
                    'time_in' => null, 'time_out' => null, 'source' => null]),
                    ...$state, 'has_record' => (bool) $log]);
            }
        }

        return $rows;
    }

    public function paginate(Collection $rows, Request $request, array $filters): LengthAwarePaginator
    {
        $status = $filters['status'] ?? '';
        $rows = $rows->filter(function ($row) use ($status) {
            return match ($status) {
                'present' => $row['attendance_status'] === 'on_time',
                'late', 'undertime' => in_array($row['attendance_status'], [$status, 'late_undertime'], true),
                // Read-only compatibility for existing bookmarked links.
                'complete' => $row['has_record'] && $row['time_in'] && $row['time_out'],
                'incomplete' => $row['has_record'] && (! $row['time_in'] || ! $row['time_out']),
                '' => true,
                default => $row['attendance_status'] === $status,
            };
        })->sortByDesc(fn ($row) => $row['attendance_date'].'|'.($row['scheduled_start'] ?? '').'|'.($row['time_in'] ?? '').'|'.str_pad((string) $row['id'], 15, '0', STR_PAD_LEFT))->values();
        $size = $filters['per_page'];
        $page = min(max(1, $request->integer('page', 1)), max(1, (int) ceil($rows->count() / $size)));

        return (new LengthAwarePaginator($rows->forPage($page, $size)->values(), $rows->count(), $size, $page,
            ['path' => $request->url(), 'query' => $request->query()]))->onEachSide(1);
    }

    private function classInterval(?string $start, ?string $end): array
    {
        if (! $start || ! $end) {
            return [];
        }
        $from = (int) Carbon::parse('2000-01-01 '.$start)->secondsSinceMidnight();
        $to = (int) Carbon::parse('2000-01-01 '.$end)->secondsSinceMidnight();

        return $to > $from ? [[$from, $to]] : [];
    }

    private function identity(Employee $employee): array
    {
        return $employee->only(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix']);
    }
}

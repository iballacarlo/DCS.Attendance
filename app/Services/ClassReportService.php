<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ClassReportService
{
    private Collection $calendar;

    private Collection $leaves;

    public function build(array $filters): array
    {
        $start = Carbon::parse($filters['start_date'], 'Asia/Manila');
        $end = Carbon::parse($filters['end_date'], 'Asia/Manila');
        $now = Carbon::now('Asia/Manila');
        $employees = Employee::withoutGlobalScopes()->when(empty($filters['term_id']) || empty($filters['employee_id']), fn ($q) => $q->whereNull('archived_at'))->with(['schedules', 'scheduleBreaks'])->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();
        $this->calendar = CalendarEvent::with('employees:id')->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->get();
        $this->leaves = LeaveRequest::whereIn('employee_id', $employees->pluck('id'))->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->get();
        $logs = AttendanceLog::withoutGlobalScopes()->where('is_legacy', false)->where('schedule_type', 'class')
            ->whereIn('employee_id', $employees->pluck('id'))->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])->get()
            ->groupBy(fn ($l) => $l->employee_id.'|'.Carbon::parse($l->attendance_date)->toDateString());
        $rows = collect();
        foreach ($employees as $employee) {
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $term = SemesterScheduleService::termFor($date);
                if (! empty($filters['term_id']) && $filters['term_id'] !== 'legacy' && (int) $filters['term_id'] !== $term?->id) {
                    continue;
                }
                $historicalEmployee = SemesterScheduleService::profile($employee, $date);
                $dayLogs = $logs->get($employee->id.'|'.$date->toDateString(), collect());
                $dayLogs = $dayLogs->filter(fn ($log) => SemesterScheduleService::matches($log->academic_term_id, (string) ($filters['term_id'] ?? ''), $date));
                $sessions = $dayLogs->map(fn ($l) => ['schedule_id' => $l->faculty_schedule_id, 'start' => $l->scheduled_start, 'end' => $l->scheduled_end, 'subject' => $l->subject, 'section' => $l->section, 'class_mode' => $l->class_mode, 'log' => $l]);
                foreach ((($filters['term_id'] ?? '') === 'legacy' ? collect() : SemesterScheduleService::schedules($employee, $date))->where('schedule_type', 'class')->where('day_of_week', $date->dayOfWeek) as $schedule) {
                    if (! $dayLogs->contains(fn ($log) => $log->faculty_schedule_id === $schedule->id
                        || ($log->subject === $schedule->subject && $log->section === $schedule->section && $log->class_mode === $schedule->class_mode
                        && substr($log->scheduled_start ?? '', 0, 5) === substr($schedule->start_time, 0, 5)
                        && substr($log->scheduled_end ?? '', 0, 5) === substr($schedule->end_time, 0, 5)))
                        && ($employee->status === 'active' || $term !== null)) {
                        $sessions->push(['schedule_id' => $schedule->id, 'start' => $schedule->start_time, 'end' => $schedule->end_time, 'subject' => $schedule->subject, 'section' => $schedule->section, 'class_mode' => $schedule->class_mode, 'log' => null]);
                    }
                }
                $excluded = $this->excluded($employee, $date);
                foreach ($sessions as $session) {
                    foreach (['subject', 'section', 'class_mode'] as $field) {
                        if (! empty($filters[$field]) && $session[$field] !== $filters[$field]) {
                            continue 2;
                        }
                    }
                    $log = $session['log'];
                    $ended = Carbon::parse($date->toDateString().' '.$session['end'], 'Asia/Manila')->lte($now);
                    $begun = Carbon::parse($date->toDateString().' '.$session['start'], 'Asia/Manila')->lte($now);
                    $complete = $log && $log->time_in && $log->time_out;
                    $status = $excluded ? 'excluded' : ($complete ? 'complete' : ($ended ? ($log ? 'incomplete' : 'missed') : ($begun ? 'in_progress' : 'upcoming')));
                    $difference = fn ($a, $b) => max(0, (int) ceil((Carbon::parse($date->toDateString().' '.$a, 'Asia/Manila')->timestamp - Carbon::parse($date->toDateString().' '.$b, 'Asia/Manila')->timestamp) / 60));
                    $rows->push(['key' => $employee->id.'-'.$date->toDateString().'-'.$session['schedule_id'].'-'.($log?->id ?? 'expected'),
                        'employee_id' => $employee->id, 'faculty' => $historicalEmployee->full_name, 'academic_term_id' => $term?->id, 'term_label' => $term?->label ?? 'Legacy / Unassigned', 'date' => $date->toDateString(),
                        'subject' => $session['subject'], 'section' => $session['section'], 'class_mode' => $session['class_mode'],
                        'scheduled_start' => $session['start'], 'scheduled_end' => $session['end'], 'time_in' => $log?->time_in, 'time_out' => $log?->time_out,
                        'late_minutes' => $log?->time_in ? $difference($log->time_in, $session['start']) : null,
                        'early_departure_minutes' => $log?->time_out ? $difference($session['end'], $log->time_out) : null,
                        'status' => $status, 'exclusion' => $excluded, 'ended' => $ended]);
                }
            }
        }
        $rows = $rows->sortBy(fn ($r) => $r['date'].' '.$r['scheduled_start'].' '.$r['faculty'])->values();
        $summary = $this->summary($rows);
        $breakdown = fn ($field) => $rows->groupBy($field)->map(fn ($group, $label) => ['value' => $group->first()[$field], 'label' => $field === 'employee_id' ? $group->first()['faculty'] : ($label ?: 'Unspecified'), ...$this->summary($group)])->values();

        return ['filters' => $filters, 'rows' => $rows, 'summary' => $summary,
            'trend' => $breakdown('date'), 'faculty_breakdown' => $breakdown('employee_id'), 'subject_breakdown' => $breakdown('subject')];
    }

    private function summary($rows): array
    {
        $eligible = $rows->where('status', '!=', 'excluded');
        $ended = $eligible->where('ended', true);
        $complete = $ended->where('status', 'complete')->count();
        $verified = $ended->where('status', 'complete');

        return ['expected' => $eligible->count(), 'ended' => $ended->count(), 'complete' => $complete,
            'incomplete' => $ended->where('status', 'incomplete')->count(), 'missed' => $ended->where('status', 'missed')->count(),
            'late' => $verified->where('late_minutes', '>', 0)->count(),
            'undertime' => $verified->where('early_departure_minutes', '>', 0)->count(),
            'on_time' => $ended->where('status', 'complete')->where('late_minutes', 0)->where('early_departure_minutes', 0)->count(),
            'total_late_minutes' => (int) $verified->sum('late_minutes'),
            'total_undertime_minutes' => (int) $verified->sum('early_departure_minutes'),
            'excluded' => $rows->where('status', 'excluded')->count(),
            'completion_rate' => $ended->count() ? round($complete / $ended->count() * 100, 1) : null];
    }

    private function excluded(Employee $employee, Carbon $date): ?string
    {
        $frozen = SemesterScheduleService::frozen($employee, $date);
        if ($frozen !== null) {
            return $frozen['exclusion'];
        }
        $day = $date->toDateString();
        $leave = $this->leaves->first(fn ($l) => $l->employee_id === $employee->id && $l->start_date->toDateString() <= $day && $l->end_date->toDateString() >= $day);
        if ($leave) {
            return 'Approved leave';
        }
        $event = $this->calendar->first(fn ($e) => $e->start_date->toDateString() <= $day && $e->end_date->toDateString() >= $day
            && in_array($e->type, ['regular_holiday', 'special_holiday', 'suspension', 'official_school_activity', 'asynchronous'], true)
            && ($e->scope_type === 'campus' || ($e->scope_type === 'department' && $e->department === $employee->department)
                || ($e->scope_type === 'faculty' && $e->employees->contains('id', $employee->id))));

        return $event?->title ?? RegularHolidayService::eventFor($date)?->title;
    }
}

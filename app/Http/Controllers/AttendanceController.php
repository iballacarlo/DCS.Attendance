<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FingerprintTemplate;
use App\Models\RfidCard;
use App\Services\AttendanceCalculator;
use App\Services\DtrService;
use App\Services\SchoolCalendarService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    private const TIME_OUT_COOLDOWN_MINUTES = 3;

    public function index(Request $request)
    {
        $user = $request->user();
        $isFaculty = $user->role === 'faculty';
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'status' => ['nullable', 'in:present,late,undertime,incomplete,absent'],
            'method' => ['nullable', 'in:rfid,fingerprint,manual'],
        ]);
        $query = AttendanceLog::with('employee')
            ->orderByDesc('attendance_date')
            ->orderByDesc('time_in');

        if ($isFaculty) {
            $query->where('employee_id', $user->employee_id);
        } else {
            $terms = preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $query->whereHas('employee', function ($employeeQuery) use ($term) {
                    $employeeQuery->where(function ($nameQuery) use ($term) {
                        $nameQuery->where('employee_no', 'like', "%{$term}%")
                            ->orWhere('first_name', 'like', "%{$term}%")
                            ->orWhere('middle_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('suffix', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
                });
            }
            $query->when($filters['date_from'] ?? null, fn ($logs, $date) => $logs->whereDate('attendance_date', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($logs, $date) => $logs->whereDate('attendance_date', '<=', $date))
                ->when($filters['status'] ?? null, fn ($logs, $status) => $logs->where('status', $status))
                ->when($filters['method'] ?? null, function ($logs, $method) {
                    $logs->where(fn ($methods) => $methods->where('method_in', $method)->orWhere('method_out', $method));
                });
        }

        return Inertia::render('Attendance/Index', [
            'employees' => $isFaculty ? [] : Employee::where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $query->paginate(15)->withQueryString(),
            'dtr' => $isFaculty ? $this->facultyDtr($user->employee_id) : null,
            'filters' => $isFaculty ? [] : [
                'search' => $filters['search'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'status' => $filters['status'] ?? '',
                'method' => $filters['method'] ?? '',
            ],
        ]);
    }

    public function tap(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'max:100'],
            'method' => ['required', 'in:rfid,fingerprint'],
        ]);

        $employee = $data['method'] === 'rfid'
            ? optional(RfidCard::where('rfid_uid', $data['identifier'])->where('status', 'active')->first())->employee
            : optional(FingerprintTemplate::where('fingerprint_code', $data['identifier'])->where('status', 'active')->first())->employee;

        if (! $employee) {
            return back()->withErrors(['identifier' => 'No active faculty record found for that RFID/fingerprint code.']);
        }

        if (! AttendanceCalculator::hasSchedule($employee, Carbon::now('Asia/Manila'))) {
            return back()->withErrors(['identifier' => $employee->full_name.' has no assigned schedule today. Attendance was not recorded.']);
        }

        if ($event = SchoolCalendarService::paidEventFor($employee, Carbon::now('Asia/Manila'))) {
            return redirect()->route('attendance.index')->with('success', "Attendance is not required today: {$event->title}. Scheduled hours are paid automatically.");
        }
        if ($leave = LeaveService::approvedLeaveFor($employee, Carbon::now('Asia/Manila'))) {
            return redirect()->route('attendance.index')->with('success', "Attendance is not required today: approved {$leave->leaveType->name}.");
        }

        $result = $this->recordAttendance($employee, $data['method']);

        if ($result['action'] === 'WAIT') {
            return back()->withErrors(['identifier' => 'Please wait until '.$result['available_at'].' before recording Time Out for '.$employee->full_name.'.']);
        }

        return redirect()->route('attendance.index')->with('success', $result['action'].' recorded for '.$employee->full_name.'.');
    }

    public function manual(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'attendance_date' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'max:500'],
        ]);

        $employee = Employee::with('schedules')->findOrFail($data['employee_id']);

        if (! $this->hasScheduleOnDate($employee, $data['attendance_date'])) {
            return back()->withErrors(['attendance_date' => $employee->full_name.' has no assigned schedule on this date. Manual attendance can only be saved on a scheduled day.']);
        }

        if ($event = SchoolCalendarService::paidEventFor($employee, $data['attendance_date'])) {
            return back()->withErrors(['attendance_date' => "Attendance is not required on this paid calendar date: {$event->title}."]);
        }
        if ($leave = LeaveService::approvedLeaveFor($employee, $data['attendance_date'])) {
            return back()->withErrors(['attendance_date' => "Attendance is not required during approved {$leave->leaveType->name}."]);
        }

        $log = AttendanceLog::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'attendance_date' => $data['attendance_date']],
            [
                'time_in' => $data['time_in'],
                'time_out' => $data['time_out'],
                'method_in' => $data['time_in'] ? 'manual' : null,
                'method_out' => $data['time_out'] ? 'manual' : null,
                'remarks' => $data['remarks'] ?? null,
            ]
        );

        $this->recalculate($log);

        return redirect()->route('attendance.index')->with('success', 'Manual attendance saved.');
    }

    public function generateScheduledTestAttendance(Request $request)
    {
        $data = $request->validate([
            'target_mode' => ['required', Rule::in(['all', 'selected'])],
            'employee_ids' => [Rule::requiredIf($request->input('target_mode') === 'selected'), 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'generation_mode' => ['required', Rule::in(['fill_missing', 'overwrite'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $start = Carbon::parse($data['start_date'], 'Asia/Manila')->startOfDay();
        $end = Carbon::parse($data['end_date'], 'Asia/Manila')->startOfDay();

        if ($start->diffInDays($end) > 31) {
            return back()->withErrors(['end_date' => 'Test attendance can cover up to 31 days at a time.']);
        }
        $today = Carbon::today('Asia/Manila');
        if ($start->greaterThan($today)) {
            return back()->withErrors(['start_date' => 'Future attendance cannot be generated. Select a cutoff that has already started.']);
        }
        $effectiveEnd = $end->lessThan($today) ? $end->copy() : $today;

        $employees = Employee::with('schedules')
            ->where('status', 'active')
            ->when($data['target_mode'] === 'selected', fn ($query) => $query->whereIn('id', $data['employee_ids']))
            ->orderBy('last_name')
            ->get();
        if ($employees->isEmpty()) {
            return back()->withErrors(['employee_ids' => 'Select at least one active faculty member.']);
        }
        $created = 0;
        $overwritten = 0;
        $removed = 0;
        $skipped = 0;
        $generated = [
            'clean' => 0,
            'late' => 0,
            'undertime' => 0,
            'late_and_undertime' => 0,
            'incomplete' => 0,
            'absent' => 0,
        ];

        foreach ($employees as $employee) {
            for ($date = $start->copy(); $date->lte($effectiveEnd); $date->addDay()) {
                $schedules = $employee->schedules
                    ->where('day_of_week', $date->dayOfWeek)
                    ->sortBy('start_time');

                if ($schedules->isEmpty()) {
                    continue;
                }

                if (SchoolCalendarService::paidEventFor($employee, $date)) {
                    continue;
                }
                if (LeaveService::approvedLeaveFor($employee, $date)) {
                    continue;
                }

                $existingLog = AttendanceLog::where('employee_id', $employee->id)
                    ->whereDate('attendance_date', $date->toDateString())
                    ->first();
                if ($data['generation_mode'] === 'fill_missing' && $existingLog) {
                    $skipped++;

                    continue;
                }

                $scheduledIn = Carbon::parse($date->toDateString().' '.$schedules->first()->start_time, 'Asia/Manila');
                $scheduledOut = Carbon::parse($date->toDateString().' '.$schedules->last()->end_time, 'Asia/Manila');
                $roll = random_int(1, 100);
                $scenario = match (true) {
                    $roll <= 40 => 'clean',
                    $roll <= 60 => 'late',
                    $roll <= 75 => 'undertime',
                    $roll <= 87 => 'late_and_undertime',
                    $roll <= 95 => 'incomplete',
                    default => 'absent',
                };

                // An absent day intentionally has no attendance record, which is
                // how the DTR distinguishes absence from an incomplete tap.
                if ($scenario === 'absent') {
                    if ($data['generation_mode'] === 'overwrite' && $existingLog) {
                        $existingLog->delete();
                        $removed++;
                    }
                    $generated['absent']++;

                    continue;
                }

                $timeIn = $scheduledIn->copy()->subMinutes(random_int(0, 10));
                $timeOut = $scheduledOut->copy()->addMinutes(random_int(0, 10));
                if (in_array($scenario, ['late', 'late_and_undertime'], true)) {
                    $timeIn = $scheduledIn->copy()->addMinutes(random_int(1, 35));
                }
                if (in_array($scenario, ['undertime', 'late_and_undertime'], true)) {
                    $timeOut = $scheduledOut->copy()->subMinutes(random_int(1, 50));
                }

                $timeInValue = $timeIn->format('H:i:s');
                $timeOutValue = $timeOut->format('H:i:s');
                if ($scenario === 'incomplete') {
                    if (random_int(0, 1) === 0) {
                        $timeOutValue = null;
                    } else {
                        $timeInValue = null;
                    }
                }

                $log = AttendanceLog::updateOrCreate(
                    ['employee_id' => $employee->id, 'attendance_date' => $date->toDateString()],
                    [
                        'time_in' => $timeInValue,
                        'time_out' => $timeOutValue,
                        'method_in' => $timeInValue ? 'manual' : null,
                        'method_out' => $timeOutValue ? 'manual' : null,
                        'remarks' => 'Random test attendance: '.str_replace('_', ' ', $scenario).'.',
                    ]
                );

                $log->setRelation('employee', $employee);
                $this->recalculate($log);
                if ($log->wasRecentlyCreated) {
                    $created++;
                } else {
                    $overwritten++;
                }
                $generated[$scenario]++;
            }
        }

        $breakdown = collect($generated)
            ->filter()
            ->map(fn ($count, $scenario) => str_replace('_', ' ', $scenario).": {$count}")
            ->implode(', ');

        $modeSummary = $data['generation_mode'] === 'overwrite'
            ? "Created {$created}, overwritten {$overwritten}, and removed {$removed} existing log(s) for absent days."
            : "Created {$created} and preserved {$skipped} existing log(s).";

        return redirect()->route('attendance.index')->with('success', "Generated random attendance through {$effectiveEnd->format('M j, Y')} for {$employees->count()} faculty ({$breakdown}). {$modeSummary}");
    }

    private function recordAttendance(Employee $employee, string $method): array
    {
        $now = Carbon::now('Asia/Manila');
        $log = AttendanceLog::firstOrNew([
            'employee_id' => $employee->id,
            'attendance_date' => $now->toDateString(),
        ]);

        if (! $log->time_in) {
            $log->time_in = $now->format('H:i:s');
            $log->method_in = $method;
            $action = 'Time In';
        } elseif (! $log->time_out) {
            $timeIn = Carbon::parse($log->attendance_date.' '.$log->time_in, 'Asia/Manila');
            $canTimeOutAt = $timeIn->copy()->addMinutes(self::TIME_OUT_COOLDOWN_MINUTES);

            if ($now->lessThan($canTimeOutAt)) {
                return ['action' => 'WAIT', 'available_at' => $canTimeOutAt->format('h:i A')];
            }

            $log->time_out = $now->format('H:i:s');
            $log->method_out = $method;
            $action = 'Time Out';
        } else {
            return ['action' => 'Already Out'];
        }

        $log->save();
        $this->recalculate($log);

        return ['action' => $action];
    }

    private function recalculate(AttendanceLog $log): void
    {
        AttendanceCalculator::recalculate($log);
    }

    private function hasScheduleOnDate(Employee $employee, string $date): bool
    {
        return AttendanceCalculator::hasSchedule($employee, $date);
    }

    private function facultyDtr(?int $employeeId): ?array
    {
        if (! $employeeId) {
            return null;
        }

        $now = Carbon::now('Asia/Manila');
        $employee = Employee::with(['schedules', 'scheduleBreaks'])->find($employeeId);

        if (! $employee) {
            return null;
        }

        $periodStart = $now->day <= 15 ? $now->copy()->startOfMonth() : $now->copy()->day(16);
        $periodEnd = $now->day <= 15 ? $now->copy()->day(15) : $now->copy()->endOfMonth();
        $elapsedEnd = $now->lessThan($periodEnd) ? $now->copy() : $periodEnd->copy();
        $ledger = app(DtrService::class)->build($employee, $periodStart, $periodEnd);
        $elapsedRows = $ledger['rows']->filter(fn ($row) => $row['date'] <= $elapsedEnd->toDateString());
        $todayRow = $ledger['rows']->firstWhere('date', $now->toDateString());
        $todayLog = $todayRow && ($todayRow['time_in'] || $todayRow['event']) ? [
            'employee_id' => $employee->id,
            'attendance_date' => $todayRow['date'],
            'time_in' => $todayRow['time_in'],
            'time_out' => $todayRow['time_out'],
            'method_in' => $todayRow['method_in'],
            'method_out' => $todayRow['method_out'],
            'late_minutes' => $todayRow['late_minutes'],
            'undertime_minutes' => $todayRow['undertime_minutes'],
            'total_hours' => $todayRow['payable_hours'],
            'status' => $todayRow['status'],
            'calendar_event' => $todayRow['event'],
        ] : null;
        $todaySchedules = $employee->schedules
            ->where('day_of_week', $now->dayOfWeek)
            ->sortBy('start_time')
            ->values()
            ->map(fn ($schedule) => [
                'id' => $schedule->id,
                'type' => $schedule->schedule_type,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ]);

        return [
            'server_time' => $now->toIso8601String(),
            'date' => $now->toDateString(),
            'cutoff' => [
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ],
            'today_log' => $todayLog,
            'today_schedules' => $todaySchedules,
            'summary' => [
                'scheduled_days' => $elapsedRows->where('has_schedule', true)->count(),
                'days_present' => $elapsedRows->filter(fn ($row) => $row['has_schedule'] && ($row['time_in'] || $row['event']))->count(),
                'absent_days' => max(0, $elapsedRows->where('has_schedule', true)->count()
                    - $elapsedRows->filter(fn ($row) => $row['has_schedule'] && ($row['time_in'] || $row['event']))->count()),
                'total_hours' => round((float) $elapsedRows->sum('payable_hours'), 2),
                'late_minutes' => (int) $elapsedRows->sum('late_minutes'),
                'undertime_minutes' => (int) $elapsedRows->sum('undertime_minutes'),
            ],
        ];
    }
}

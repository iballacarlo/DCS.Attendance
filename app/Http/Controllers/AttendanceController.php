<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Services\AttendanceCalculator;
use App\Services\ClassAttendanceService;
use App\Services\CutoffService;
use App\Services\DtrService;
use App\Services\TestAttendanceGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $faculty = $request->user()->role === 'faculty';
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'cutoff_start' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'in:present,late_undertime'], 'method' => ['nullable', 'in:rfid,fingerprint,manual'],
        ]);
        [$start, $end] = CutoffService::range($filters['cutoff_start'] ?? null);
        $filters = array_merge(['search' => '', 'employee_id' => '', 'status' => '', 'method' => ''], $filters, ['cutoff_start' => $start->toDateString()]);
        $query = AttendanceLog::with('employee')->where('is_legacy', false)->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()]);
        if ($faculty) {
            $query->where('employee_id', $request->user()->employee_id ?? 0);
        } else {
            $query->when($filters['employee_id'], fn ($q, $id) => $q->where('employee_id', $id));
            foreach (preg_split('/\s+/', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $query->whereHas('employee', fn ($q) => $q->where(function ($q) use ($term) {
                    foreach (['employee_no', 'first_name', 'middle_name', 'last_name', 'email'] as $field) {
                        $q->orWhere($field, 'like', "%{$term}%");
                    }
                }));
            }
        }
        $query->when($filters['status'], fn ($q, $status) => $q->attendanceStatus($status))
            ->when($filters['method'], fn ($q, $method) => $q->where(fn ($q) => $q->where('method_in', $method)->orWhere('method_out', $method)));
        $employee = $faculty ? Employee::with(['schedules', 'scheduleBreaks'])->find($request->user()->employee_id) : null;

        return Inertia::render('Attendance/Index', [
            'employees' => $faculty ? [] : Employee::with('schedules')->where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $query->orderByDesc('attendance_date')->orderByDesc('scheduled_start')->orderByDesc('time_in')->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'dtr' => $employee ? app(DtrService::class)->build($employee, $start, $end) : null,
        ]);
    }

    public function generateScheduledTestAttendance(Request $request, TestAttendanceGenerator $generator)
    {
        $data = $request->validate([
            'target_mode' => ['required', 'in:all,selected'], 'employee_ids' => [Rule::requiredIf($request->input('target_mode') === 'selected'), 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'], 'generation_mode' => ['required', 'in:fill_missing,overwrite'],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $start = Carbon::parse($data['start_date'], 'Asia/Manila');
        $end = Carbon::parse($data['end_date'], 'Asia/Manila');
        if ($start->diffInDays($end) > 31 || $start->isAfter(Carbon::today('Asia/Manila'))) {
            return back()->withErrors(['start_date' => 'Choose up to 31 days, starting no later than today.']);
        }
        $replaceEnd = $end->copy();
        $end = $end->min(Carbon::today('Asia/Manila'));
        $employees = Employee::with('schedules')->where('status', 'active')->when($data['target_mode'] === 'selected', fn ($q) => $q->whereIn('id', $data['employee_ids']))->orderBy('id')->get();
        $created = 0;
        $deleted = 0;
        DB::transaction(function () use ($employees, $start, $end, $replaceEnd, $data, $generator, &$created, &$deleted) {
            foreach ($employees as $employee) {
                Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $now = Carbon::now('Asia/Manila');
                $overwrite = $data['generation_mode'] === 'overwrite';
                if ($overwrite) {
                    $deleted += AttendanceLog::where('employee_id', $employee->id)
                        ->whereBetween('attendance_date', [$start->toDateString(), $replaceEnd->toDateString()])->delete();
                }
                $days = [];
                for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                    $schedules = $employee->schedules->whereIn('schedule_type', FacultySchedule::PAYABLE_TYPES)->where('day_of_week', $date->dayOfWeek)->sortBy('start_time')->values();
                    $existing = AttendanceLog::where('employee_id', $employee->id)->where('attendance_date', $date->toDateString())->where('is_legacy', false)->get()->keyBy('faculty_schedule_id');
                    if ($schedules->contains(fn ($s) => Carbon::parse($date->toDateString().' '.$s->end_time, 'Asia/Manila')->lte($now) && ($overwrite || ! $existing->has($s->id)))) {
                        $days[$date->toDateString()] = ['date' => $date->copy(), 'schedules' => $schedules, 'existing' => $existing];
                    }
                }
                // At most one in five generated days gets a late/early-out example.
                $shortfallDays = collect(array_keys($days))->shuffle()->take(intdiv(count($days), 5))->flip();
                foreach ($days as $dateString => $day) {
                    $pairs = $generator->pairs($day['schedules'], $day['date'], $now, $day['existing'], $overwrite, $shortfallDays->has($dateString));
                    foreach ($day['schedules'] as $schedule) {
                        if (! isset($pairs[$schedule->id])) {
                            continue;
                        }
                        $log = AttendanceLog::firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $dateString, 'faculty_schedule_id' => $schedule->id]);
                        $log->fill([...ClassAttendanceService::snapshot($schedule), 'time_in' => $pairs[$schedule->id]['in']->format('H:i:s'), 'time_out' => $pairs[$schedule->id]['out']->format('H:i:s'),
                            'method_in' => 'rfid', 'method_out' => 'rfid', 'legacy_source_id' => null, 'is_legacy' => false, 'remarks' => 'Generated class test attendance.']);
                        AttendanceCalculator::recalculate($log);
                        $created++;
                    }
                }
            }
        });

        return redirect()->route('attendance.index', ['cutoff_start' => $start->copy()->day($start->day <= 15 ? 1 : 16)->toDateString()])
            ->with('success', ($data['generation_mode'] === 'overwrite' ? "Replaced {$deleted} old logs in the selected range. " : '')."Generated {$created} class attendance sessions through ".$end->format('m/d/Y').'.');
    }
}

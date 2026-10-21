<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FingerprintTemplate;
use App\Models\RfidCard;
use App\Services\AttendanceCalculator;
use App\Services\ClassAttendanceService;
use App\Services\CutoffService;
use App\Services\DtrService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $faculty = $request->user()->role === 'faculty';
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'cutoff_start' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:present,incomplete'], 'method' => ['nullable', 'in:rfid,fingerprint,manual'],
        ]);
        [$start, $end] = CutoffService::range($filters['cutoff_start'] ?? null);
        $filters = array_merge(['search' => '', 'status' => '', 'method' => ''], $filters, ['cutoff_start' => $start->toDateString()]);
        $query = AttendanceLog::with('employee')->where('is_legacy', false)->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()]);
        if ($faculty) {
            $query->where('employee_id', $request->user()->employee_id ?? 0);
        } else {
            foreach (preg_split('/\s+/', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $query->whereHas('employee', fn ($q) => $q->where(function ($q) use ($term) {
                    foreach (['employee_no', 'first_name', 'middle_name', 'last_name', 'email'] as $field) {
                        $q->orWhere($field, 'like', "%{$term}%");
                    }
                }));
            }
        }
        $query->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['method'], fn ($q, $method) => $q->where(fn ($q) => $q->where('method_in', $method)->orWhere('method_out', $method)));
        $employee = $faculty ? Employee::with(['schedules', 'scheduleBreaks'])->find($request->user()->employee_id) : null;

        return Inertia::render('Attendance/Index', [
            'employees' => $faculty ? [] : Employee::with('schedules')->where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $query->orderByDesc('attendance_date')->orderBy('scheduled_start')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'cutoffOptions' => CutoffService::options(AttendanceLog::min('attendance_date') ?: $start->copy()->subYears(2)->toDateString(), $start),
            'dtr' => $employee ? app(DtrService::class)->build($employee, $start, $end) : null,
        ]);
    }

    public function tap(Request $request, ClassAttendanceService $service)
    {
        $data = $request->validate(['identifier' => ['required', 'max:100'], 'method' => ['required', 'in:rfid,fingerprint']]);
        $credential = $data['method'] === 'rfid' ? RfidCard::where('rfid_uid', $data['identifier'])->where('status', 'active')->first()
            : FingerprintTemplate::where('fingerprint_code', $data['identifier'])->where('status', 'active')->first();
        $employee = $credential?->employee;
        if (! $employee || $employee->status !== 'active') {
            return back()->withErrors(['identifier' => 'No active faculty found for this credential.']);
        }
        $result = $service->record($employee, $data['method'], Carbon::now('Asia/Manila'));
        if (in_array($result['action'], ['WAIT', 'NO CLASS'], true)) {
            return back()->withErrors(['identifier' => $result['message']]);
        }

        return back()->with('success', $result['message'].' '.$employee->full_name);
    }

    public function manual(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'faculty_schedule_id' => ['required', 'integer'],
            'attendance_date' => ['required', 'date_format:Y-m-d'], 'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', Rule::when($request->filled('time_in'), 'after:time_in')], 'remarks' => ['nullable', 'max:500'],
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        $existing = AttendanceLog::where('employee_id', $employee->id)->where('attendance_date', $data['attendance_date'])
            ->where('faculty_schedule_id', $data['faculty_schedule_id'])->where('is_legacy', false)->first();
        $schedule = $employee->schedules()->whereKey($data['faculty_schedule_id'])
            ->where('day_of_week', Carbon::parse($data['attendance_date'], 'Asia/Manila')->dayOfWeek)->where('schedule_type', 'class')->first();
        if (! $existing && ! $schedule) {
            return back()->withErrors(['faculty_schedule_id' => 'Select a class assigned to this faculty on this date.']);
        }
        DB::transaction(function () use ($employee, $data, $schedule) {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $log = AttendanceLog::firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $data['attendance_date'], 'faculty_schedule_id' => $data['faculty_schedule_id']]);
            if (! $log->exists) {
                $conflict = AttendanceLog::where('employee_id', $employee->id)->where('attendance_date', $data['attendance_date'])->where('is_legacy', false)
                    ->where('scheduled_start', '<', $schedule->end_time)->where('scheduled_end', '>', $schedule->start_time)->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['faculty_schedule_id' => 'This class overlaps an attendance snapshot already recorded for this date.']);
                }
                $log->fill(ClassAttendanceService::snapshot($schedule));
            }
            $log->fill(['time_in' => $data['time_in'] ?? null, 'time_out' => $data['time_out'] ?? null,
                'method_in' => ! empty($data['time_in']) ? 'manual' : null, 'method_out' => ! empty($data['time_out']) ? 'manual' : null, 'remarks' => $data['remarks'] ?? null]);
            AttendanceCalculator::recalculate($log);
        });

        return back()->with('success', 'Class attendance saved.');
    }

    public function generateScheduledTestAttendance(Request $request)
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
        $end = $end->min(Carbon::today('Asia/Manila'));
        $employees = Employee::with('schedules')->where('status', 'active')->when($data['target_mode'] === 'selected', fn ($q) => $q->whereIn('id', $data['employee_ids']))->get();
        $created = 0;
        foreach ($employees as $employee) {
            DB::transaction(function () use ($employee, $start, $end, $data, &$created) {
                Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                    foreach ($employee->schedules->where('schedule_type', 'class')->where('day_of_week', $date->dayOfWeek) as $schedule) {
                        if (Carbon::parse($date->toDateString().' '.$schedule->end_time, 'Asia/Manila')->isFuture()) {
                            continue;
                        }
                        $log = AttendanceLog::firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $date->toDateString(), 'faculty_schedule_id' => $schedule->id]);
                        if ($log->exists && $data['generation_mode'] === 'fill_missing') {
                            continue;
                        }
                        $in = Carbon::parse($date->toDateString().' '.$schedule->start_time, 'Asia/Manila');
                        $out = Carbon::parse($date->toDateString().' '.$schedule->end_time, 'Asia/Manila');
                        $in->addMinutes(random_int(0, min(15, max(0, (int) $in->diffInMinutes($out) - 1))));
                        $log->fill([...ClassAttendanceService::snapshot($schedule), 'time_in' => $in->format('H:i:s'), 'time_out' => $out->format('H:i:s'),
                            'method_in' => 'rfid', 'method_out' => 'rfid', 'remarks' => 'Generated class test attendance.']);
                        AttendanceCalculator::recalculate($log);
                        $created++;
                    }
                }
            });
        }

        return redirect()->route('attendance.index', ['cutoff_start' => $start->copy()->day($start->day <= 15 ? 1 : 16)->toDateString()])
            ->with('success', "Generated {$created} class attendance sessions through ".$end->format('m/d/Y').'.');
    }
}

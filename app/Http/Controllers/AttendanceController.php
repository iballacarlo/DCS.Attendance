<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Room;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceRecordService;
use App\Services\ClassReportService;
use App\Services\CutoffService;
use App\Services\SemesterScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceRecordService $records, ClassReportService $reports)
    {
        $faculty = $request->user()->role === 'faculty';
        $termSelection = SemesterScheduleService::selection($request);
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'search' => ['nullable', 'string', 'max:100'], 'cutoff_start' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'in:'.AttendanceRecordService::FILTERS], 'method' => ['nullable', 'in:rfid,fingerprint,manual'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'], 'week_start' => ['nullable', 'date_format:Y-m-d'],
        ]);
        unset($filters['page']);
        $filters['per_page'] = (int) ($filters['per_page'] ?? 10);
        [$start, $end] = CutoffService::range($filters['cutoff_start'] ?? null, $termSelection);
        $weekStart = Carbon::parse($filters['week_start'] ?? now('Asia/Manila'), 'Asia/Manila')->startOfWeek(Carbon::MONDAY);
        $filters = array_merge(['search' => '', 'employee_id' => '', 'status' => '', 'method' => '', 'room_id' => ''], $filters, ['cutoff_start' => $start->toDateString(), 'week_start' => $weekStart->toDateString()]);
        $filters['term_id'] = $termSelection;
        $people = Employee::withoutGlobalScopes()->when($termSelection === '', fn ($q) => $q->whereNull('archived_at'))->with(['schedules', 'scheduleBreaks'])
            ->when($faculty, fn ($q) => $q->whereKey($request->user()->employee_id ?? 0))
            ->when(! $faculty && $filters['employee_id'], fn ($q) => $q->whereKey($filters['employee_id']));
        if (! $faculty) {
            foreach (preg_split('/\s+/', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $people->where(function ($q) use ($term) {
                    foreach (['employee_no', 'first_name', 'middle_name', 'last_name', 'email'] as $field) {
                        $q->orWhere($field, 'like', "%{$term}%");
                    }
                });
            }
        }
        $people = $people->get()->keyBy('id');
        $query = AttendanceLog::withoutGlobalScopes()->with('employee')->whereIn('employee_id', $people->keys())
            ->where('is_legacy', false)->where('schedule_type', 'class')
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->when($filters['method'], fn ($q, $method) => $q->where(fn ($q) => $q->where('method_in', $method)->orWhere('method_out', $method)));
        $rows = $records->classRows($query->get(), $people, $start, $end, ! $filters['method']);
        if ($filters['room_id']) {
            $rows = $rows->where('room_id', (int) $filters['room_id'])->values();
        }
        $logs = $records->paginate($rows, $request, $filters);
        $weekly = $reports->build(['start_date' => $weekStart->toDateString(), 'end_date' => $weekStart->copy()->addDays(5)->toDateString(),
            'employee_id' => $faculty ? $request->user()->employee_id : ($filters['employee_id'] ?: null), 'room_id' => $filters['room_id'] ?: null]);

        return Inertia::render('Attendance/Index', [
            'employees' => $faculty ? [] : Employee::with('schedules')->where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $logs,
            'filters' => $filters,
            'rooms' => Room::where('is_active', true)->orderBy('code')->get(['id', 'code']),
            'weekly' => $weekly,
        ]);
    }

    public function correct(Request $request, AttendanceLog $attendanceLog)
    {
        $data = $request->validate(['time_out' => ['required', 'date_format:H:i'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        if (! $attendanceLog->time_in || $attendanceLog->time_in >= $data['time_out'].':00') {
            throw ValidationException::withMessages(['time_out' => 'Corrected OUT must be later than the recorded IN.']);
        }
        $next = AttendanceLog::where('room_id', $attendanceLog->room_id)->whereDate('attendance_date', $attendanceLog->attendance_date)
            ->where('id', '!=', $attendanceLog->id)->whereNotNull('time_in')->where('scheduled_start', '>', $attendanceLog->scheduled_start)->orderBy('scheduled_start')->first();
        if ($next && $next->time_in < $data['time_out'].':00') {
            throw ValidationException::withMessages(['time_out' => 'Corrected OUT cannot overlap the next recorded room user.']);
        }
        DB::transaction(function () use ($request, $attendanceLog, $data) {
            AttendanceCorrection::create(['attendance_log_id' => $attendanceLog->id, 'corrected_by' => $request->user()->id,
                'previous_time_in' => $attendanceLog->time_in, 'previous_time_out' => $attendanceLog->time_out,
                'corrected_time_in' => $attendanceLog->time_in, 'corrected_time_out' => $data['time_out'], 'reason' => $data['reason']]);
            $attendanceLog->update(['time_out' => $data['time_out'], 'method_out' => 'manual', 'room_released_at' => null, 'room_release_reason' => null, 'remarks' => $data['reason']]);
            AttendanceCalculator::recalculate($attendanceLog);
        });

        return back()->with('success', 'Class attendance OUT corrected with an audit record.');
    }
}

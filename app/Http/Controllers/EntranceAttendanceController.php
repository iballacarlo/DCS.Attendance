<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Services\AttendanceRecordService;
use App\Services\CutoffService;
use App\Services\EntranceAttendanceService;
use App\Services\SemesterScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class EntranceAttendanceController extends Controller
{
    public function index(Request $request, AttendanceRecordService $records)
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cutoff_start' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'in:'.AttendanceRecordService::FILTERS],
        ]);
        $faculty = $request->user()->role === 'faculty';
        $termSelection = SemesterScheduleService::selection($request);
        $cutoff = $data['cutoff_start'] ?? null;
        // Old daily links still select the period containing that date.
        if (! $cutoff && ! empty($data['date'])) {
            $anchor = Carbon::parse($data['date'], 'Asia/Manila');
            $cutoff = $anchor->copy()->day($anchor->day <= 15 ? 1 : 16)->toDateString();
        }
        [$start, $end] = CutoffService::range($cutoff, $termSelection);
        $filters = ['per_page' => (int) ($data['per_page'] ?? 10), 'cutoff_start' => $start->toDateString(),
            'employee_id' => $faculty ? '' : ($data['employee_id'] ?? ''), 'status' => $data['status'] ?? ''];
        $filters['term_id'] = $termSelection;
        $people = Employee::withoutGlobalScopes()->when($termSelection === '', fn ($q) => $q->whereNull('archived_at'))->with(['schedules', 'scheduleBreaks'])
            ->when($faculty, fn ($q) => $q->whereKey($request->user()->employee_id ?? 0))
            ->when(! $faculty && $filters['employee_id'], fn ($q) => $q->whereKey($filters['employee_id']))
            ->get()->keyBy('id');
        $logs = EntranceAttendance::withoutGlobalScopes()->with('employee')->whereIn('employee_id', $people->keys())
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])->get();
        $rows = $records->schoolRows($logs, $people, $start, $end);
        $records = $records->paginate($rows, $request, $filters);

        return Inertia::render('Entrance/Index', [
            'filters' => $filters,
            'employees' => $faculty ? [] : Employee::orderBy('last_name')->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix']),
            'canSelectFaculty' => ! $faculty,
            'canRecord' => false,
            'logs' => $records,
        ]);
    }

    public function kiosk()
    {
        return Inertia::render('Entrance/Kiosk');
    }

    public function store(Request $request, EntranceAttendanceService $service)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'], 'expected_action' => ['required', 'in:IN,OUT'],
            'occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);
        $at = ! empty($data['occurred_at']) ? Carbon::createFromFormat('Y-m-d\TH:i', $data['occurred_at'], 'Asia/Manila')->second(0) : Carbon::now('Asia/Manila');
        $log = $service->recordAutomatically($data['identifier'], $at, $request->user()->id, $data['expected_action']);
        $action = $log->time_out ? 'OUT' : 'IN';

        return redirect()->route('entrance.kiosk')->with('success', 'School '.$action.' recorded for '.$log->employee->full_name.' at '.$at->format('g:i A, M j, Y').'.');
    }

    public function preview(Request $request, EntranceAttendanceService $service)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ]);
        $at = ! empty($data['occurred_at']) ? Carbon::createFromFormat('Y-m-d\TH:i', $data['occurred_at'], 'Asia/Manila') : Carbon::now('Asia/Manila');

        return response()->json($service->preview($data['identifier'], $at))->header('Cache-Control', 'no-store');
    }
}

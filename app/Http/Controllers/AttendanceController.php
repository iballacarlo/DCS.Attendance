<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AttendanceRecordService;
use App\Services\CutoffService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceRecordService $records)
    {
        $faculty = $request->user()->role === 'faculty';
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'search' => ['nullable', 'string', 'max:100'], 'cutoff_start' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'in:'.AttendanceRecordService::FILTERS], 'method' => ['nullable', 'in:rfid,fingerprint,manual'],
        ]);
        unset($filters['page']);
        $filters['per_page'] = (int) ($filters['per_page'] ?? 10);
        [$start, $end] = CutoffService::range($filters['cutoff_start'] ?? null);
        $filters = array_merge(['search' => '', 'employee_id' => '', 'status' => '', 'method' => ''], $filters, ['cutoff_start' => $start->toDateString()]);
        $people = Employee::with(['schedules', 'scheduleBreaks'])
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
        $query = AttendanceLog::with('employee')->whereIn('employee_id', $people->keys())
            ->where('is_legacy', false)->where('schedule_type', 'class')
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->when($filters['method'], fn ($q, $method) => $q->where(fn ($q) => $q->where('method_in', $method)->orWhere('method_out', $method)));
        $rows = $records->classRows($query->get(), $people, $start, $end, ! $filters['method']);
        $logs = $records->paginate($rows, $request, $filters);

        return Inertia::render('Attendance/Index', [
            'employees' => $faculty ? [] : Employee::with('schedules')->where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $logs,
            'filters' => $filters,
        ]);
    }
}

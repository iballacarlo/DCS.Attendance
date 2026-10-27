<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\CutoffService;
use Illuminate\Http\Request;
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
        $query = AttendanceLog::with('employee')->where('is_legacy', false)->where('schedule_type', 'class')->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()]);
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

        return Inertia::render('Attendance/Index', [
            'employees' => $faculty ? [] : Employee::with('schedules')->where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $query->orderByDesc('attendance_date')->orderByDesc('scheduled_start')->orderByDesc('time_in')->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $filters,
        ]);
    }
}

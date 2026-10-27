<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Services\ClassReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ClassReportController extends Controller
{
    public function index(Request $request, ClassReportService $service)
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'subject' => ['nullable', 'string', 'max:255'], 'section' => ['nullable', 'string', 'max:255'],
            'class_mode' => ['nullable', 'in:lecture,laboratory'],
        ]);
        $filters['start_date'] = $filters['start_date'] ?? Carbon::today('Asia/Manila')->startOfMonth()->toDateString();
        $filters['end_date'] = $filters['end_date'] ?? Carbon::today('Asia/Manila')->toDateString();
        $start = Carbon::parse($filters['start_date']);
        $end = Carbon::parse($filters['end_date']);
        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Choose an ordered date range of up to 366 days.']);
        }

        return Inertia::render('Reports/Classes', [
            'report' => $service->build($filters),
            'employees' => Employee::orderBy('last_name')->get(),
            'subjects' => FacultySchedule::where('schedule_type', 'class')->whereNotNull('subject')->pluck('subject')
                ->merge(AttendanceLog::where('schedule_type', 'class')->where('is_legacy', false)->whereNotNull('subject')->pluck('subject'))->unique()->sort()->values(),
            'sections' => FacultySchedule::where('schedule_type', 'class')->whereNotNull('section')->pluck('section')
                ->merge(AttendanceLog::where('schedule_type', 'class')->where('is_legacy', false)->whereNotNull('section')->pluck('section'))->unique()->sort()->values(),
        ]);
    }
}

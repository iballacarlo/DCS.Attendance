<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Services\ClassReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ClassReportService $reports)
    {
        if ($request->user()->role === 'guard') {
            return Inertia::render('Guard/Dashboard');
        }

        $today = Carbon::today('Asia/Manila');
        $faculty = $request->user()->role === 'faculty';
        $employeeId = $faculty ? ($request->user()->employee_id ?? 0) : null;
        $employee = $faculty ? Employee::with(['schedules', 'scheduleBreaks'])->find($employeeId) : null;
        $entrance = EntranceAttendance::with('employee')->where('attendance_date', $today->toDateString())
            ->when($faculty, fn ($q) => $q->where('employee_id', $employeeId))->orderByDesc('time_in')->get();
        $report = ! $faculty ? $reports->build(['start_date' => $today->copy()->subDays(6)->toDateString(), 'end_date' => $today->toDateString()]) : null;

        return Inertia::render('Dashboard', [
            'employeeCount' => ! $faculty ? Employee::where('status', 'active')->count() : null,
            'entrance' => $entrance, 'presentToday' => $entrance->count(), 'incompleteToday' => $entrance->whereNull('time_out')->count(),
            'classAnalytics' => $report ? collect($report)->except(['rows'])->all() : null,
            'todaySchedule' => $employee ? $employee->schedules->concat($employee->scheduleBreaks)->where('day_of_week', $today->dayOfWeek)->sortBy('start_time')->values() : [],
            'scheduleDate' => $today->toDateString(),
            'contractWarnings' => Employee::where('status', 'active')->when($faculty, fn ($q) => $q->whereKey($employeeId))
                ->whereBetween('contract_end', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->get()
                ->each(fn ($e) => $e->setAttribute('days_remaining', (int) $today->diffInDays(Carbon::parse($e->contract_end), false))),
        ]);
    }
}

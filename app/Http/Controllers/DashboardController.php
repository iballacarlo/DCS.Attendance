<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        $user = Auth::user();

        if ($user->role === 'faculty') {
            $employeeId = $user->employee_id;
            $employee = $employeeId ? Employee::with(['schedules', 'scheduleBreaks'])->find($employeeId) : null;
            $day = Carbon::today('Asia/Manila')->dayOfWeek;
            $todaySchedule = $employee ? $employee->schedules->concat($employee->scheduleBreaks)
                ->filter(fn ($block) => (int) $block->day_of_week === $day)->sortBy('start_time')->values()
                ->map(fn ($block) => $block->only(['id', 'schedule_type', 'start_time', 'end_time', 'subject', 'section', 'class_mode'])) : collect();

            return Inertia::render('Dashboard', [
                'contractWarnings' => $employeeId ? $this->contractWarnings($employeeId) : collect(),
                'latestPayrolls' => $employeeId ? PayrollRecord::with(['employee', 'payrollPeriod'])->where('employee_id', $employeeId)->where('status', 'released')->latest()->take(5)->get() : collect(),
                'recentAttendance' => $employeeId ? $this->recentAttendance($employeeId) : collect(),
                'todaySchedule' => $todaySchedule,
                'scheduleDate' => $today,
            ]);
        }

        return Inertia::render('Dashboard', [
            'contractWarnings' => $this->contractWarnings(),
            'employeeCount' => Employee::count(),
            'presentToday' => AttendanceLog::where('attendance_date', $today)->where('is_legacy', false)->whereNotNull('time_in')->distinct()->count('employee_id'),
            'openPeriods' => PayrollPeriod::where('status', '!=', 'finalized')->count(),
            'attendanceTrend' => $this->attendanceTrend(),
            'latestPayrolls' => PayrollRecord::with(['employee', 'payrollPeriod'])->latest()->take(5)->get(),
            'recentAttendance' => $this->recentAttendance(),
        ]);
    }

    private function contractWarnings(?int $employeeId = null)
    {
        $today = Carbon::today();

        return Employee::query()
            ->when($employeeId, fn ($query) => $query->whereKey($employeeId))
            ->where('status', 'active')
            ->whereBetween('contract_end', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->orderBy('contract_end')
            ->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix', 'contract_end'])
            ->map(function (Employee $employee) use ($today) {
                $employee->setAttribute('days_remaining', (int) $today->diffInDays(Carbon::parse($employee->contract_end), false));

                return $employee;
            });
    }

    private function attendanceTrend(?int $employeeId = null): array
    {
        return collect(range(6, 0))->filter(fn ($daysAgo) => ! Carbon::today('Asia/Manila')->subDays($daysAgo)->isSunday())->map(function (int $daysAgo) use ($employeeId) {
            $date = Carbon::today('Asia/Manila')->subDays($daysAgo);
            $count = AttendanceLog::query()
                ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
                ->where('attendance_date', $date->toDateString())
                ->whereNotNull('time_in')
                ->where('is_legacy', false)->distinct()->count('employee_id');

            return ['label' => $date->format('D'), 'count' => $count];
        })->values()->all();
    }

    private function recentAttendance(?int $employeeId = null)
    {
        return AttendanceLog::with('employee')->where('is_legacy', false)
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->orderByDesc('attendance_date')
            ->orderByDesc('time_in')
            ->orderByDesc('id')
            ->take(8)
            ->get();
    }
}

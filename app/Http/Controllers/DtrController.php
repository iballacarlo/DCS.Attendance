<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\DtrService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DtrController extends Controller
{
    public function show(Request $request, DtrService $dtrService)
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'start_date' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $employeeId = $user->role === 'faculty' ? $user->employee_id : ($data['employee_id'] ?? Employee::orderBy('last_name')->value('id'));
        abort_unless($employeeId, 404, 'No faculty record is available for DTR generation.');
        $employee = Employee::findOrFail($employeeId);
        $start = isset($data['start_date']) ? Carbon::parse($data['start_date'], 'Asia/Manila') : $this->currentCutoffStart();
        [$start, $end] = $this->cutoff($start);
        $dtr = $dtrService->build($employee, $start, $end);

        return Inertia::render('Dtr/Show', [
            'dtr' => $dtr,
            'employees' => $user->role === 'faculty' ? [] : Employee::orderBy('last_name')->get(),
            'canSelectFaculty' => $user->role !== 'faculty',
        ]);
    }

    private function cutoff(Carbon $start): array
    {
        if (! in_array($start->day, [1, 16], true)) {
            throw ValidationException::withMessages(['start_date' => 'DTR cutoff must start on day 1 or day 16.']);
        }

        $end = $start->day === 1 ? $start->copy()->day(15) : $start->copy()->endOfMonth();

        return [$start->copy()->startOfDay(), $end->startOfDay()];
    }

    private function currentCutoffStart(): Carbon
    {
        $today = Carbon::now('Asia/Manila');

        return $today->day <= 15 ? $today->copy()->startOfMonth() : $today->copy()->day(16);
    }
}

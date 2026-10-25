<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\CutoffService;
use App\Services\DtrService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DtrController extends Controller
{
    public function show(Request $request, DtrService $dtrService)
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = $request->user();
        $employeeId = $user->role === 'faculty' ? $user->employee_id : ($data['employee_id'] ?? Employee::orderBy('last_name')->value('id'));
        abort_unless($employeeId, 404, 'No faculty record is available for DTR generation.');
        $employee = Employee::findOrFail($employeeId);
        $start = isset($data['start_date']) ? Carbon::parse($data['start_date'], 'Asia/Manila') : $this->currentCutoffStart();
        abort_unless($start->year >= 2000 && $start->year <= 2100, 422, 'Choose a DTR year from 2000 to 2100.');
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
        return CutoffService::range($start->toDateString());
    }

    private function currentCutoffStart(): Carbon
    {
        $today = Carbon::now('Asia/Manila');

        return $today->day <= 15 ? $today->copy()->startOfMonth() : $today->copy()->day(16);
    }
}

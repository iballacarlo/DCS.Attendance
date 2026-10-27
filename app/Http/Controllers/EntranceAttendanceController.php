<?php

namespace App\Http\Controllers;

use App\Models\EntranceAttendance;
use App\Services\EntranceAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class EntranceAttendanceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? Carbon::today('Asia/Manila')->toDateString();

        return Inertia::render('Entrance/Index', [
            'date' => $date,
            'canRecord' => false,
            'logs' => EntranceAttendance::with('employee')->where('attendance_date', $date)
                ->when($request->user()->role === 'faculty', fn ($q) => $q->where('employee_id', $request->user()->employee_id ?? 0))
                ->orderByDesc('time_in')->get(),
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

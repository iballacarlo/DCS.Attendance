<?php

namespace App\Http\Controllers;

use App\Models\AttendanceConflictResolution;
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
            ->where('is_legacy', false)->where('schedule_type', 'class')->whereNull('voided_at')
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->when($filters['method'], fn ($q, $method) => $q->where(fn ($q) => $q->where('method_in', $method)->orWhere('method_out', $method)));
        $activeLogs = $query->get();
        $rows = $records->classRows($activeLogs, $people, $start, $end, ! $filters['method']);
        $conflicts = $this->roomConflicts($activeLogs);
        $rows = $rows->map(function (array $row) use ($conflicts) {
            $row['room_conflicts'] = $row['has_record'] ?? false
                ? ($conflicts[$row['id']] ?? []) : [];

            return $row;
        });
        if ($filters['room_id']) {
            $rows = $rows->where('room_id', (int) $filters['room_id'])->values();
        }
        $logs = $records->paginate($rows, $request, $filters);
        // The weekly plot is intentionally scoped to one faculty member for admins.
        // Rendering every faculty member in the same Monday-Saturday grid makes the
        // monitoring view unreadable; the attendance list below may still show all.
        $weeklyFacultyRequired = ! $faculty && ! $filters['employee_id'];
        $weekly = $weeklyFacultyRequired
            ? ['rows' => []]
            : $reports->build(['start_date' => $weekStart->toDateString(), 'end_date' => $weekStart->copy()->addDays(5)->toDateString(),
                'employee_id' => $faculty ? $request->user()->employee_id : $filters['employee_id'], 'room_id' => $filters['room_id'] ?: null]);

        return Inertia::render('Attendance/Index', [
            'employees' => $faculty ? [] : Employee::with('schedules')->where('status', 'active')->orderBy('last_name')->get(),
            'logs' => $logs,
            'filters' => $filters,
            'rooms' => Room::where('is_active', true)->orderBy('code')->get(['id', 'code']),
            'weekly' => $weekly,
            'weeklyFacultyRequired' => $weeklyFacultyRequired,
        ]);
    }

    public function correct(Request $request, AttendanceLog $attendanceLog)
    {
        $data = $request->validate(['time_out' => ['required', 'date_format:H:i'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        if (! $attendanceLog->time_in || $attendanceLog->time_in >= $data['time_out'].':00') {
            throw ValidationException::withMessages(['time_out' => 'Corrected OUT must be later than the recorded IN.']);
        }
        $next = AttendanceLog::with('employee')->where('room_id', $attendanceLog->room_id)->whereDate('attendance_date', $attendanceLog->attendance_date)
            ->whereNull('voided_at')
            ->where('id', '!=', $attendanceLog->id)->whereNotNull('time_in')->where('scheduled_start', '>', $attendanceLog->scheduled_start)->orderBy('scheduled_start')->first();
        if ($next && $next->time_in < $data['time_out'].':00') {
            $nextName = $next->employee?->full_name ?: 'the next faculty';
            $nextTime = Carbon::parse($next->attendance_date.' '.$next->time_in, 'Asia/Manila')->format('h:i A');
            throw ValidationException::withMessages(['time_out' => "Corrected OUT must be before {$nextName}'s recorded IN at {$nextTime}. If both records are duplicates, use Resolve room conflict instead."]);
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

    public function resolveRoomConflict(Request $request, AttendanceLog $attendanceLog)
    {
        $data = $request->validate([
            'valid_attendance_log_id' => ['required', 'integer', 'different:attendanceLog', 'exists:attendance_logs,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $attendanceLog, $data) {
            $invalid = AttendanceLog::withoutGlobalScopes()->lockForUpdate()->findOrFail($attendanceLog->id);
            $valid = AttendanceLog::withoutGlobalScopes()->lockForUpdate()->findOrFail($data['valid_attendance_log_id']);
            if ($invalid->voided_at || $valid->voided_at || $valid->schedule_type !== 'class'
                || $invalid->schedule_type !== 'class' || ! $this->logsConflict($invalid, $valid)) {
                throw ValidationException::withMessages(['valid_attendance_log_id' => 'Choose a valid overlapping class attendance record in the same room and date.']);
            }

            $invalidSnapshot = $this->conflictSnapshot($invalid);
            $validSnapshot = $this->conflictSnapshot($valid);
            $invalid->update([
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $data['reason'],
                'resolved_against_log_id' => $valid->id,
                'room_released_at' => null,
                'room_release_reason' => 'resolved_room_conflict',
            ]);
            AttendanceConflictResolution::create([
                'room_id' => $invalid->room_id,
                'attendance_date' => $invalid->attendance_date,
                'kept_attendance_log_id' => $valid->id,
                'voided_attendance_log_id' => $invalid->id,
                'resolved_by' => $request->user()->id,
                'reason' => $data['reason'],
                'kept_snapshot' => $validSnapshot,
                'voided_snapshot' => $invalidSnapshot,
            ]);
        });

        return back()->with('success', 'Room conflict resolved. The invalid duplicate is retained in the audit trail and excluded from attendance results.');
    }

    private function roomConflicts($logs): array
    {
        $byLog = [];
        foreach ($logs->filter(fn ($log) => $log->room_id && $log->time_in)->groupBy(fn ($log) => $log->room_id.'|'.$log->attendance_date) as $roomLogs) {
            foreach ($roomLogs as $log) {
                $matches = $roomLogs->filter(fn ($other) => $other->id !== $log->id && $this->logsConflict($log, $other))
                    ->map(fn ($other) => $this->conflictSnapshot($other))->values()->all();
                if ($matches) {
                    $byLog[$log->id] = $matches;
                }
            }
        }

        return $byLog;
    }

    private function logsConflict(AttendanceLog $first, AttendanceLog $second): bool
    {
        if (! $first->room_id || $first->room_id !== $second->room_id || $first->voided_at || $second->voided_at
            || $first->schedule_type !== 'class' || $second->schedule_type !== 'class'
            || Carbon::parse($first->attendance_date)->toDateString() !== Carbon::parse($second->attendance_date)->toDateString()
            || ! $first->time_in || ! $second->time_in || ! $first->scheduled_end || ! $second->scheduled_end) {
            return false;
        }
        $date = Carbon::parse($first->attendance_date)->toDateString();
        $start = fn (AttendanceLog $log) => Carbon::parse($date.' '.$log->time_in, 'Asia/Manila');
        $end = fn (AttendanceLog $log) => Carbon::parse($date.' '.($log->time_out ?: $log->scheduled_end), 'Asia/Manila');

        return $start($first)->lt($end($second)) && $start($second)->lt($end($first));
    }

    private function conflictSnapshot(AttendanceLog $log): array
    {
        return [
            'id' => $log->id,
            'faculty' => $log->employee?->full_name ?? Employee::withoutGlobalScopes()->find($log->employee_id)?->full_name,
            'subject' => $log->subject,
            'section' => $log->section,
            'room' => $log->room_code,
            'scheduled_start' => $log->scheduled_start,
            'scheduled_end' => $log->scheduled_end,
            'time_in' => $log->time_in,
            'time_out' => $log->time_out,
        ];
    }
}

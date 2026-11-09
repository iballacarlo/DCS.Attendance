<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AttendanceLog;
use App\Models\ClassSessionRoomChange;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Services\RoomAttendanceService;
use App\Services\SemesterScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomChangeController extends Controller
{
    public function store(Request $request)
    {
        $employee = Employee::findOrFail($request->user()->employee_id);
        $data = $request->validate(['faculty_schedule_id' => ['required', 'integer', 'exists:faculty_schedules,id'],
            'session_date' => ['required', 'date_format:Y-m-d'], 'room_id' => ['required', Rule::exists('rooms', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNotNull('building_id'))]]);
        $date = Carbon::parse($data['session_date'], 'Asia/Manila');
        $schedule = FacultySchedule::whereKey($data['faculty_schedule_id'])->where('employee_id', $employee->id)->where('schedule_type', 'class')->firstOrFail();
        $term = AcademicTerm::find($schedule->academic_term_id);
        $start = Carbon::parse($date->toDateString().' '.$schedule->start_time, 'Asia/Manila');
        if ($date->dayOfWeek !== (int) $schedule->day_of_week || ! $term || $term->status === 'closed' || $date->lt($term->start_date) || $date->gt($term->end_date) || now('Asia/Manila')->gte($start)) {
            throw ValidationException::withMessages(['session_date' => 'Choose a future occurrence of this class inside its open semester.']);
        }
        if (AttendanceLog::where('faculty_schedule_id', $schedule->id)->whereDate('attendance_date', $date)->exists()) {
            throw ValidationException::withMessages(['session_date' => 'The room cannot be changed after attendance has been recorded.']);
        }
        $original = app(RoomAttendanceService::class)->effectiveRoom($schedule, $date);
        if (! $original || (int) $original->id === (int) $data['room_id']) {
            throw ValidationException::withMessages(['room_id' => $original ? 'Choose a different room.' : 'Ask the administrator to assign the original schedule room first.']);
        }
        $occupant = $this->occupant($date, $schedule, (int) $data['room_id']);
        DB::transaction(function () use ($request, $employee, $schedule, $date, $data, $original, $occupant) {
            $change = ClassSessionRoomChange::updateOrCreate(['faculty_schedule_id' => $schedule->id, 'session_date' => $date->toDateString()], [
                'employee_id' => $employee->id, 'original_room_id' => $original->id, 'assigned_room_id' => $data['room_id'],
                'swap_schedule_id' => $occupant?->id, 'swap_employee_id' => $occupant?->employee_id,
                'status' => $occupant ? 'pending' : 'approved', 'requested_by' => $request->user()->id,
                'responded_by' => null, 'responded_at' => null,
            ]);
            $this->audit($request, $occupant ? 'room_swap.requested' : 'room_move.approved', $change,
                $occupant ? 'Requested a one-session room swap.' : 'Moved one class session to a vacant room.');
        });

        return back()->with('success', $occupant ? 'Room swap request sent to the other faculty.' : 'Room moved for this class session.');
    }

    public function respond(Request $request, ClassSessionRoomChange $change)
    {
        $data = $request->validate(['action' => ['required', 'in:accept,reject']]);
        abort_unless($change->status === 'pending' && (int) $change->swap_employee_id === (int) $request->user()->employee_id, 403);
        if ($data['action'] === 'reject') {
            $change->update(['status' => 'rejected', 'responded_by' => $request->user()->id, 'responded_at' => now()]);
            $this->audit($request, 'room_swap.rejected', $change, 'Rejected a one-session room swap.');

            return back()->with('success', 'Room swap request rejected.');
        }
        DB::transaction(function () use ($change, $request) {
            $change = ClassSessionRoomChange::lockForUpdate()->findOrFail($change->id);
            if ($change->status !== 'pending') {
                throw ValidationException::withMessages(['room_change' => 'This room swap has already been handled.']);
            }
            $date = $change->session_date;
            $schedules = FacultySchedule::whereIn('id', [$change->faculty_schedule_id, $change->swap_schedule_id])->lockForUpdate()->get()->keyBy('id');
            $first = $schedules->get($change->faculty_schedule_id);
            $second = $schedules->get($change->swap_schedule_id);
            if (! $first || ! $second) {
                throw ValidationException::withMessages(['room_change' => 'One of the classes in this swap is no longer available.']);
            }
            $terms = AcademicTerm::whereIn('id', [$first->academic_term_id, $second->academic_term_id])->get()->keyBy('id');
            if ($terms->get($first->academic_term_id)?->status === 'closed' || $terms->get($second->academic_term_id)?->status === 'closed') {
                throw ValidationException::withMessages(['room_change' => 'A room swap cannot be accepted in a closed semester.']);
            }
            $starts = [Carbon::parse($date->toDateString().' '.$first->start_time, 'Asia/Manila'), Carbon::parse($date->toDateString().' '.$second->start_time, 'Asia/Manila')];
            $firstStart = $starts[0]->lte($starts[1]) ? $starts[0] : $starts[1];
            if (now('Asia/Manila')->gte($firstStart) || AttendanceLog::whereDate('attendance_date', $date)->whereIn('faculty_schedule_id', [$first->id, $second->id])->exists()) {
                throw ValidationException::withMessages(['room_change' => 'The swap can no longer be accepted because a class has started or attendance exists.']);
            }
            $ignored = [$first->id, $second->id];
            if ($this->roomHasConflict($date, $first, (int) $change->assigned_room_id, $ignored)
                || $this->roomHasConflict($date, $second, (int) $change->original_room_id, $ignored)) {
                throw ValidationException::withMessages(['room_change' => 'The swap now conflicts with another class. Refresh the schedule and choose another room.']);
            }
            $change->update(['status' => 'accepted', 'responded_by' => $request->user()->id, 'responded_at' => now()]);
            ClassSessionRoomChange::updateOrCreate(['faculty_schedule_id' => $second->id, 'session_date' => $date->toDateString()], [
                'employee_id' => $second->employee_id, 'original_room_id' => $change->assigned_room_id,
                'assigned_room_id' => $change->original_room_id, 'swap_schedule_id' => $first->id,
                'swap_employee_id' => $first->employee_id, 'status' => 'accepted', 'requested_by' => $change->requested_by,
                'responded_by' => $request->user()->id, 'responded_at' => now(),
            ]);
            $this->audit($request, 'room_swap.accepted', $change, 'Accepted a one-session room swap.');
        });

        return back()->with('success', 'Room swap accepted.');
    }

    public function cancel(Request $request, ClassSessionRoomChange $change)
    {
        abort_unless((int) $change->employee_id === (int) $request->user()->employee_id && in_array($change->status, ['pending', 'approved'], true), 403);
        $change->update(['status' => 'cancelled', 'responded_by' => $request->user()->id, 'responded_at' => now()]);
        $this->audit($request, 'room_change.cancelled', $change, 'Cancelled a one-session room change.');

        return back()->with('success', 'Room change cancelled.');
    }

    private function occupant(Carbon $date, FacultySchedule $source, int $roomId): ?FacultySchedule
    {
        $employees = Employee::where('status', 'active')->get();
        foreach ($employees as $employee) {
            foreach (SemesterScheduleService::schedules($employee, $date)->where('schedule_type', 'class')->where('day_of_week', $date->dayOfWeek) as $schedule) {
                if ($schedule->id === $source->id || app(RoomAttendanceService::class)->effectiveRoom($schedule, $date)?->id !== $roomId) {
                    continue;
                }
                if ($schedule->start_time < $source->end_time && $schedule->end_time > $source->start_time) {
                    return $schedule;
                }
            }
        }

        return null;
    }

    private function roomHasConflict(Carbon $date, FacultySchedule $source, int $roomId, array $ignoredScheduleIds): bool
    {
        $employees = Employee::where('status', 'active')->get();
        foreach ($employees as $employee) {
            foreach (SemesterScheduleService::schedules($employee, $date)->where('schedule_type', 'class')->where('day_of_week', $date->dayOfWeek) as $schedule) {
                if (in_array($schedule->id, $ignoredScheduleIds, true)
                    || (int) app(RoomAttendanceService::class)->effectiveRoom($schedule, $date)?->id !== $roomId) {
                    continue;
                }
                if ($schedule->start_time < $source->end_time && $schedule->end_time > $source->start_time) {
                    return true;
                }
            }
        }

        return false;
    }

    private function audit(Request $request, string $action, ClassSessionRoomChange $change, string $description): void
    {
        DB::table('audit_trails')->insert([
            'action' => $action, 'table_name' => 'class_session_room_changes', 'record_id' => $change->id,
            'description' => $description.' By user #'.$request->user()->id.'.', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

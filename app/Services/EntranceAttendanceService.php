<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\RfidCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntranceAttendanceService
{
    public function preview(string $uid, Carbon $at): array
    {
        $card = RfidCard::whereRaw('UPPER(rfid_uid) = ?', [strtoupper(trim($uid))])->where('status', 'active')->first();
        $employee = $card?->employee;
        if (! $employee || $employee->status !== 'active') {
            throw ValidationException::withMessages(['identifier' => 'RFID is not registered to an active faculty member.']);
        }
        $log = EntranceAttendance::where('employee_id', $employee->id)->where('attendance_date', $at->copy()->setTimezone('Asia/Manila')->toDateString())->first();

        return ['employee' => $employee->full_name, 'next_action' => $log ? ($log->time_out ? null : 'OUT') : 'IN', 'time_in' => $log?->time_in, 'time_out' => $log?->time_out];
    }

    public function recordAutomatically(string $uid, Carbon $at, int $actorId, string $expectedAction): EntranceAttendance
    {
        return $this->record($uid, null, $at, $actorId, $expectedAction);
    }

    public function record(string $uid, ?string $action, Carbon $at, int $actorId, ?string $expectedAction = null): EntranceAttendance
    {
        return DB::transaction(function () use ($uid, $action, $at, $actorId, $expectedAction) {
            $card = RfidCard::whereRaw('UPPER(rfid_uid) = ?', [strtoupper(trim($uid))])->where('status', 'active')->first();
            $employee = $card ? Employee::whereKey($card->employee_id)->lockForUpdate()->first() : null;
            if (! $employee || $employee->status !== 'active') {
                throw ValidationException::withMessages(['identifier' => 'RFID is not registered to an active faculty member.']);
            }
            $at = $at->copy()->setTimezone('Asia/Manila');
            $log = EntranceAttendance::where('employee_id', $employee->id)->where('attendance_date', $at->toDateString())->lockForUpdate()->first();
            if ($action === null) {
                if ($log?->time_out) {
                    throw ValidationException::withMessages(['action' => 'School IN and OUT are already complete for this day.']);
                }
                $action = $log ? 'OUT' : 'IN';
                if ($expectedAction !== $action) {
                    throw ValidationException::withMessages(['action' => 'Attendance has changed. Check the updated IN/OUT status and submit again.']);
                }
            }
            if ($action === 'IN') {
                if ($log) {
                    throw ValidationException::withMessages(['action' => 'School IN already exists for this day. Existing times were kept.']);
                }
                $employee->load(['schedules', 'scheduleBreaks']);

                return EntranceAttendance::create([
                    'employee_id' => $employee->id, 'attendance_date' => $at->toDateString(),
                    'time_in' => $at->format('H:i:s'), 'source' => 'simulator', 'recorded_by' => $actorId,
                    'schedule_snapshot' => DailyScheduleService::snapshot($employee, $at),
                ]);
            }
            if (! $log || $log->time_out || $at->format('H:i:s') <= $log->time_in) {
                throw ValidationException::withMessages(['action' => 'Record IN first, then a later OUT on the same day. Completed records cannot be overwritten.']);
            }
            $log->update(['time_out' => $at->format('H:i:s'), 'out_recorded_by' => $actorId]);

            return $log->fresh();
        });
    }
}

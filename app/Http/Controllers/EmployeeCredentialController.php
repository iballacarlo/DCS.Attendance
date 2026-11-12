<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HardwareEnrollment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class EmployeeCredentialController extends Controller
{
    public function registerNfc(Request $request, Employee $employee): JsonResponse
    {
        if (is_string($request->input('identifier'))) {
            $request->merge(['identifier' => strtoupper(preg_replace('/[:\s-]/', '', $request->input('identifier')))]);
        }
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:100', 'regex:/\A(?:[0-9A-F]{2})+\z/'],
            'current_identifier' => ['present', 'nullable', 'string', 'max:100'],
            'confirmation' => ['required', 'in:REGISTER'],
        ]);
        try {
            DB::transaction(function () use ($request, $employee, $data) {
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                $credential = $employee->rfidCards()->first();
                $previous = $credential?->rfid_uid;
                abort_unless(strtoupper($previous ?? '') === strtoupper($data['current_identifier'] ?? ''), 409,
                    'The RFID registration has changed. Reload the faculty profile before trying again.');
                abort_if(HardwareEnrollment::where('employee_id', $employee->id)->where('method', 'rfid')
                    ->whereIn('status', ['pending', 'processing'])
                    ->where('expires_at', '>', DB::raw('CURRENT_TIMESTAMP'))->exists(), 409,
                    'Finish or cancel the RFID hardware registration before registering through NFC.');
                // Include archived assignments; their cards must not be reassigned.
                if (DB::table('rfid_cards')->whereRaw('UPPER(rfid_uid) = ?', [$data['identifier']])
                    ->when($credential, fn ($query) => $query->where('id', '!=', $credential->id))->exists()) {
                    throw ValidationException::withMessages(['identifier' => 'This RFID card is already assigned to another faculty member.']);
                }
                $credential ??= $employee->rfidCards()->make();
                $credential->fill(['rfid_uid' => $data['identifier'], 'status' => 'active', 'registered_at' => now()])->save();
                $this->audit($request->user()->id, $previous ? 'credential.replaced' : 'credential.registered', $employee->id,
                    'RFID '.($previous ? $previous.' replaced with ' : '').$data['identifier'].' registered through cellphone NFC.');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identifier' => 'This RFID card is already assigned to another faculty member.']);
        }

        return response()->json(['method' => 'rfid', 'operation' => 'replace', 'status' => 'completed',
            'identifier' => $data['identifier'], 'message' => 'RFID card registered through NFC.']);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:rfid,fingerprint'],
            'finger_label' => ['nullable', 'required_if:method,fingerprint', 'string', 'max:50'],
            'attendance_device_id' => ['required', 'integer', Rule::exists('attendance_devices', 'id')->where(fn ($query) => $query->where('is_active', true))],
        ]);

        $enrollment = DB::transaction(function () use ($request, $employee, $data) {
            HardwareEnrollment::where('employee_id', $employee->id)
                ->where('method', $data['method'])
                ->where('attendance_device_id', $data['attendance_device_id'])
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled', 'message' => 'Replaced by a new enrollment request.']);

            $currentIdentifier = $data['method'] === 'rfid'
                ? $employee->rfidCards()->value('rfid_uid')
                : $employee->fingerprintTemplates()->value('fingerprint_code');

            return $this->createEnrollment([
                'employee_id' => $employee->id,
                'requested_by' => $request->user()->id,
                'attendance_device_id' => $data['attendance_device_id'],
                'method' => $data['method'],
                'operation' => 'replace',
                'finger_label' => $data['finger_label'] ?? null,
                'current_identifier' => $currentIdentifier,
                'status' => 'pending',
            ]);
        });

        return response()->json($this->payload($enrollment), 201);
    }

    public function unlink(Request $request, Employee $employee, string $method): JsonResponse
    {
        abort_unless(in_array($method, ['rfid', 'fingerprint'], true), 404);

        $data = $request->validate([
            'confirmation' => ['required', 'in:UNLINK'],
            'current_identifier' => ['required', 'string', 'max:100'],
            'attendance_device_id' => [$method === 'fingerprint' ? 'required' : 'nullable', 'integer', Rule::exists('attendance_devices', 'id')->where(fn ($query) => $query->where('is_active', true))],
        ]);

        $result = DB::transaction(function () use ($request, $employee, $method, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $relation = $method === 'rfid' ? $employee->rfidCards() : $employee->fingerprintTemplates();
            $column = $method === 'rfid' ? 'rfid_uid' : 'fingerprint_code';
            $identifier = $relation->value($column);

            abort_unless($identifier && hash_equals($identifier, strtoupper($data['current_identifier'])), 409);

            if ($method === 'rfid') {
                $relation->delete();
                $this->audit($request->user()->id, 'credential.unlinked', $employee->id,
                    "RFID {$identifier} unlinked from {$employee->full_name}.");

                return ['immediate' => true, 'identifier' => $identifier];
            }

            HardwareEnrollment::where('employee_id', $employee->id)
                ->where('method', 'fingerprint')
                ->where('attendance_device_id', $data['attendance_device_id'])
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled', 'message' => 'Replaced by an unlink request.']);

            return ['immediate' => false, 'enrollment' => $this->createEnrollment([
                'employee_id' => $employee->id,
                'requested_by' => $request->user()->id,
                'attendance_device_id' => $data['attendance_device_id'],
                'method' => 'fingerprint',
                'operation' => 'unlink',
                'current_identifier' => $identifier,
                'status' => 'pending',
            ])];
        });

        if ($result['immediate']) {
            return response()->json([
                'method' => 'rfid', 'operation' => 'unlink', 'status' => 'completed',
                'identifier' => $result['identifier'], 'message' => 'RFID card unlinked successfully.',
            ]);
        }

        return response()->json($this->payload($result['enrollment']), 201);
    }

    public function show(Employee $employee, HardwareEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->employee_id === $employee->id, 404);
        $this->expireIfNeeded($enrollment);

        return response()->json($this->payload($enrollment->fresh()));
    }

    public function destroy(Employee $employee, HardwareEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->employee_id === $employee->id, 404);

        if (in_array($enrollment->status, ['pending', 'processing'], true)) {
            $enrollment->update(['status' => 'cancelled', 'message' => 'Cancelled by administrator.']);
        }

        return response()->json($this->payload($enrollment->fresh()));
    }

    private function expireIfNeeded(HardwareEnrollment $enrollment): void
    {
        $expired = DB::table('hardware_enrollments')
            ->where('id', $enrollment->id)
            ->where('expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'))
            ->exists() || $enrollment->expires_at?->isPast();
        if (in_array($enrollment->status, ['pending', 'processing'], true) && $expired) {
            $enrollment->update(['status' => 'expired', 'message' => 'The registration request expired.']);
        }
    }

    private function createEnrollment(array $attributes): HardwareEnrollment
    {
        // Use the database clock for the expiry value. This prevents local and
        // Railway MySQL timezone differences from expiring a fresh request.
        $minutes = (int) config('services.hardware.enrollment_expiry_minutes', 15);
        $attributes['expires_at'] = now()->addMinutes($minutes);
        $enrollment = HardwareEnrollment::create($attributes);
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::table('hardware_enrollments')->where('id', $enrollment->id)->update([
                'expires_at' => DB::raw("DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$minutes} MINUTE)"),
            ]);
            $enrollment->refresh();
        }
        return $enrollment;
    }

    private function payload(HardwareEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'method' => $enrollment->method,
            'operation' => $enrollment->operation,
            'status' => $enrollment->status,
            'identifier' => $enrollment->identifier,
            'message' => $enrollment->message,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
            'attendance_device_id' => $enrollment->attendance_device_id,
        ];
    }

    private function audit(int $userId, string $action, int $employeeId, string $description): void
    {
        DB::table('audit_trails')->insert([
            'action' => $action,
            'table_name' => 'employees',
            'record_id' => $employeeId,
            'description' => $description." Verified by administrator #{$userId}.",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

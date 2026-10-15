<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HardwareEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeCredentialController extends Controller
{
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:rfid,fingerprint'],
            'finger_label' => ['nullable', 'required_if:method,fingerprint', 'string', 'max:50'],
        ]);

        $enrollment = DB::transaction(function () use ($employee, $data) {
            HardwareEnrollment::whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled', 'message' => 'Replaced by a new enrollment request.']);

            $currentIdentifier = $data['method'] === 'rfid'
                ? $employee->rfidCards()->value('rfid_uid')
                : $employee->fingerprintTemplates()->value('fingerprint_code');

            return HardwareEnrollment::create([
                'employee_id' => $employee->id,
                'requested_by' => $request->user()->id,
                'method' => $data['method'],
                'operation' => 'replace',
                'finger_label' => $data['finger_label'] ?? null,
                'current_identifier' => $currentIdentifier,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(5),
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

            HardwareEnrollment::whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'cancelled', 'message' => 'Replaced by an unlink request.']);

            return ['immediate' => false, 'enrollment' => HardwareEnrollment::create([
                'employee_id' => $employee->id,
                'requested_by' => $request->user()->id,
                'method' => 'fingerprint',
                'operation' => 'unlink',
                'current_identifier' => $identifier,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(5),
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
        if (in_array($enrollment->status, ['pending', 'processing'], true) && $enrollment->expires_at->isPast()) {
            $enrollment->update(['status' => 'expired', 'message' => 'The registration request expired.']);
        }
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

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
                'method' => $data['method'],
                'finger_label' => $data['finger_label'] ?? null,
                'current_identifier' => $currentIdentifier,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(5),
            ]);
        });

        return response()->json($this->payload($enrollment), 201);
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
            'status' => $enrollment->status,
            'identifier' => $enrollment->identifier,
            'message' => $enrollment->message,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
        ];
    }
}

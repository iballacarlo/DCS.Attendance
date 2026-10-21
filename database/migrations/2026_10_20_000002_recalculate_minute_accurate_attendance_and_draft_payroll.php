<?php

use App\Models\AttendanceLog;
use App\Models\PayrollRecord;
use App\Services\AttendanceCalculator;
use App\Services\PayrollComputationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy daily records are audit sources for the explicit class conversion.
        // Fresh installations run this migration before the class-session schema exists.
        if (! Schema::hasColumn('attendance_logs', 'faculty_schedule_id')) {
            return;
        }
        AttendanceLog::with('employee')->orderBy('id')->chunkById(100, function ($logs) {
            foreach ($logs as $log) {
                if ($log->employee && ! $log->is_legacy) {
                    AttendanceCalculator::recalculate($log);
                }
            }
        });

        PayrollRecord::with(['employee', 'payrollPeriod'])
            ->where('status', 'draft')
            ->orderBy('id')
            ->chunkById(50, function ($records) {
                foreach ($records as $record) {
                    if (! $record->employee || ! $record->payrollPeriod || $record->payrollPeriod->status === 'finalized') {
                        continue;
                    }

                    $computed = app(PayrollComputationService::class)->calculate(
                        $record->employee,
                        $record->payrollPeriod->start_date,
                        $record->payrollPeriod->end_date
                    );
                    $totalEarnings = round($computed['gross_pay'] + (float) $record->overtime_pay
                        + (float) $record->other_earnings + (float) $record->increase_amount, 2);
                    $record->update([
                        ...$computed,
                        'total_earnings' => $totalEarnings,
                        'net_pay' => max(0, round($totalEarnings - (float) $record->total_deductions, 2)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Historical values cannot be safely restored after recalculation.
    }
};

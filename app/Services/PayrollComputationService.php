<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRecord;

class PayrollComputationService
{
    public function calculate(Employee $employee, string $startDate, string $endDate): array
    {
        $dtr = app(DtrService::class)->build($employee, $startDate, $endDate);
        $hoursWorked = (float) $dtr['summary']['total_hours'];
        $rateAmount = (float) $employee->rate_amount;

        $grossPay = round(($dtr['summary']['worked_seconds'] / 3600) * $rateAmount, 2);

        return [
            'total_days_worked' => $dtr['summary']['paid_days'],
            'total_hours_worked' => $hoursWorked,
            'late_undertime_minutes' => $dtr['summary']['late_minutes'] + $dtr['summary']['undertime_minutes'],
            'absent_days' => $dtr['summary']['absent_days'],
            'gross_pay' => $grossPay,
        ];
    }

    public function updateDraft(PayrollRecord $record, array $computed): void
    {
        $earnings = $computed['gross_pay'] + (float) $record->overtime_pay + (float) $record->other_earnings + (float) $record->increase_amount;
        $record->fill($computed);
        $record->total_earnings = round($earnings, 2);
        $record->net_pay = max(0, round($earnings - (float) $record->total_deductions, 2));
        $record->status = 'draft';
        $record->save();
    }
}

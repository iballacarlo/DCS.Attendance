<?php

namespace App\Services;

use App\Models\Employee;

class PayrollComputationService
{
    public function calculate(Employee $employee, string $startDate, string $endDate): array
    {
        $dtr = app(DtrService::class)->build($employee, $startDate, $endDate);
        $hoursWorked = (float) $dtr['summary']['total_hours'];
        $rateAmount = (float) $employee->rate_amount;

        if ($employee->rate_type === 'hourly') {
            $grossPay = round($hoursWorked * $rateAmount, 2);
        } else {
            $grossPay = round($dtr['rows']->sum(function ($row) use ($rateAmount) {
                if (! $row['has_schedule'] || $row['payable_hours'] <= 0) {
                    return 0;
                }

                $scheduledHours = $row['scheduled_hours'];

                return $scheduledHours > 0 ? ($row['payable_hours'] / $scheduledHours) * $rateAmount : 0;
            }), 2);
        }

        return [
            'total_days_worked' => $dtr['summary']['paid_days'],
            'total_hours_worked' => $hoursWorked,
            'late_undertime_minutes' => $dtr['summary']['late_minutes'] + $dtr['summary']['undertime_minutes'],
            'absent_days' => $dtr['summary']['absent_days'],
            'gross_pay' => $grossPay,
        ];
    }
}

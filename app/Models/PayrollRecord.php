<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class PayrollRecord extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = [
        'payroll_period_id',
        'employee_id',
        'total_days_worked',
        'total_hours_worked',
        'gross_pay',
        'overtime_pay',
        'late_undertime_minutes',
        'absent_days',
        'other_earnings',
        'increase_amount',
        'total_earnings',
        'withholding_tax',
        'gsis_deduction',
        'philhealth_deduction',
        'pag_ibig_deduction',
        'multi_purpose_loan',
        'gsis_loan',
        'gsis_eplus_loan',
        'fea_dues',
        'oba_deduction',
        'cra_deduction',
        'total_deductions',
        'total_adjustments',
        'net_pay',
        'status',
        'generated_at',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod()
    {
        return $this->belongsTo(PayrollPeriod::class);
    }
}

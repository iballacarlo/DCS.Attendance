<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Services\PayrollComputationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'status' => ['nullable', 'in:draft,approved,released'],
        ]);
        $records = PayrollRecord::with(['employee', 'payrollPeriod'])->latest();

        if (Auth::user()->role === 'faculty') {
            $records->where('employee_id', Auth::user()->employee_id)
                ->where('status', 'released');
        } else {
            $terms = preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $records->where(function ($recordQuery) use ($term) {
                    $recordQuery->whereHas('employee', function ($employeeQuery) use ($term) {
                        $employeeQuery->where(function ($nameQuery) use ($term) {
                            $nameQuery->where('employee_no', 'like', "%{$term}%")
                                ->orWhere('first_name', 'like', "%{$term}%")
                                ->orWhere('middle_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%");
                        });
                    })->orWhereHas('payrollPeriod', fn ($periodQuery) => $periodQuery->where('period_name', 'like', "%{$term}%"));
                });
            }
            $records->when($filters['period'] ?? null, fn ($query, $period) => $query->where('payroll_period_id', $period))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        }

        $periods = PayrollPeriod::withCount('records')->latest()->get();

        return Inertia::render('Payroll/Index', [
            'periods' => $periods,
            'records' => $records->paginate(15)->withQueryString(),
            'filters' => Auth::user()->role === 'faculty' ? [] : [
                'search' => $filters['search'] ?? '',
                'period' => $filters['period'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function storePeriod(Request $request)
    {
        $data = $request->validate([
            'period_name' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['nullable', 'date'],
        ]);

        $expectedPayDate = $this->expectedPayDate($data['start_date'], $data['end_date']);

        if (! $expectedPayDate) {
            throw ValidationException::withMessages([
                'start_date' => 'Payroll cutoff must be either day 1 to 15, or day 16 to the last day of the same month.',
                'end_date' => 'Payroll cutoff must be either day 1 to 15, or day 16 to the last day of the same month.',
            ]);
        }

        $start = Carbon::parse($data['start_date'], 'Asia/Manila');
        $end = Carbon::parse($data['end_date'], 'Asia/Manila');
        if (PayrollPeriod::whereDate('start_date', $start)->whereDate('end_date', $end)->exists()) {
            throw ValidationException::withMessages([
                'start_date' => 'A payroll period already exists for this cutoff.',
            ]);
        }

        $data['period_name'] = $start->format('F').' '.$start->day.'-'.$end->day.', '.$start->year;
        $data['pay_date'] = $expectedPayDate;

        PayrollPeriod::create($data);

        return redirect()->route('payroll.index')->with('success', 'Payroll period created.');
    }

    public function generate(PayrollPeriod $period, PayrollComputationService $payrollCalculator)
    {
        if ($period->status === 'finalized') {
            return back()->with('error', 'This payroll period is finalized and can no longer be regenerated.');
        }

        $employees = Employee::with(['facultyRank', 'schedules'])->where('status', 'active')->get();

        foreach ($employees as $employee) {
            $computed = $payrollCalculator->calculate($employee, $period->start_date, $period->end_date);

            // Late, undertime, early arrival, and overtime are already reflected in payable scheduled hours.
            $deductions = 0;
            $netPay = max(0, $computed['gross_pay']);

            $record = PayrollRecord::firstOrNew([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
            ]);
            if ($record->exists && $record->status !== 'draft') {
                continue;
            }
            $record->fill([
                    'total_days_worked' => $computed['total_days_worked'],
                    'total_hours_worked' => $computed['total_hours_worked'],
                    'gross_pay' => $computed['gross_pay'],
                    'overtime_pay' => 0,
                    'late_undertime_minutes' => $computed['late_undertime_minutes'],
                    'absent_days' => $computed['absent_days'],
                    'other_earnings' => 0,
                    'increase_amount' => 0,
                    'total_earnings' => $computed['gross_pay'],
                    'withholding_tax' => 0,
                    'gsis_deduction' => 0,
                    'philhealth_deduction' => 0,
                    'pag_ibig_deduction' => 0,
                    'multi_purpose_loan' => 0,
                    'gsis_loan' => 0,
                    'gsis_eplus_loan' => 0,
                    'fea_dues' => 0,
                    'oba_deduction' => 0,
                    'cra_deduction' => 0,
                    'total_deductions' => $deductions,
                    'total_adjustments' => 0,
                    'net_pay' => $netPay,
                    'status' => 'draft',
                    'generated_at' => Carbon::now(),
                ])->save();
        }

        $period->update(['status' => 'processing']);

        return redirect()->route('payroll.index')->with('success', 'Payroll generated from attendance logs.');
    }

    public function show(PayrollRecord $record)
    {
        $record->load(['employee', 'payrollPeriod']);

        if (Auth::user()->role === 'faculty'
            && (Auth::user()->employee_id !== $record->employee_id || $record->status !== 'released')) {
            abort(403, 'This payslip has not been released to your account.');
        }

        return Inertia::render('Payroll/Show', ['record' => $record]);
    }

    public function update(Request $request, PayrollRecord $record)
    {
        if ($record->status !== 'draft') {
            return back()->with('error', 'Only draft payroll records can be edited.');
        }

        $fields = [
            'overtime_pay', 'other_earnings', 'increase_amount', 'withholding_tax',
            'gsis_deduction', 'philhealth_deduction', 'pag_ibig_deduction',
            'multi_purpose_loan', 'gsis_loan', 'gsis_eplus_loan', 'fea_dues',
            'oba_deduction', 'cra_deduction',
        ];

        $rules = array_fill_keys($fields, ['required', 'numeric', 'min:0', 'max:99999999.99']);
        $data = $request->validate($rules);
        $totalEarnings = round((float) $record->gross_pay
            + (float) $data['overtime_pay']
            + (float) $data['other_earnings']
            + (float) $data['increase_amount'], 2);
        $deductionFields = array_slice($fields, 3);
        $totalDeductions = round(array_sum(array_map(fn ($field) => (float) $data[$field], $deductionFields)), 2);

        $record->update([
            ...$data,
            'total_earnings' => $totalEarnings,
            'total_deductions' => $totalDeductions,
            'total_adjustments' => round((float) $data['overtime_pay'] + (float) $data['other_earnings'] + (float) $data['increase_amount'], 2),
            'net_pay' => max(0, round($totalEarnings - $totalDeductions, 2)),
        ]);

        return redirect()->route('payroll.records.show', $record)->with('success', 'Payslip details updated.');
    }

    public function approve(PayrollRecord $record)
    {
        if ($record->status !== 'draft') {
            return back()->with('error', 'Only draft payroll records can be approved.');
        }

        $record->update(['status' => 'approved']);
        $this->syncPeriodStatus($record->payrollPeriod);

        return back()->with('success', 'Payroll record approved. It is now ready for release.');
    }

    public function release(PayrollRecord $record)
    {
        if ($record->status !== 'approved') {
            return back()->with('error', 'Approve the payroll record before releasing it.');
        }

        $record->update(['status' => 'released']);
        $this->syncPeriodStatus($record->payrollPeriod);

        return back()->with('success', 'Payslip released to the faculty member.');
    }

    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'record_ids' => ['required', 'array', 'min:1', 'max:100'],
            'record_ids.*' => ['integer', 'distinct', 'exists:payroll_records,id'],
            'action' => ['required', Rule::in(['approve', 'release'])],
        ]);
        $requiredStatus = $data['action'] === 'approve' ? 'draft' : 'approved';
        $newStatus = $data['action'] === 'approve' ? 'approved' : 'released';
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $requiredStatus, $newStatus, &$updated, &$skipped) {
            $records = PayrollRecord::query()
                ->whereIn('id', $data['record_ids'])
                ->lockForUpdate()
                ->get();

            if ($records->pluck('payroll_period_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages([
                    'record_ids' => 'Bulk actions can only process payslips from one payroll period at a time.',
                ]);
            }

            foreach ($records as $record) {
                if ($record->status !== $requiredStatus) {
                    $skipped++;
                    continue;
                }

                $record->update(['status' => $newStatus]);
                $updated++;
            }

            $period = PayrollPeriod::findOrFail($records->first()->payroll_period_id);
            $this->syncPeriodStatus($period);
        });

        $verb = $data['action'] === 'approve' ? 'approved' : 'released';
        $message = "{$updated} payslip(s) {$verb}.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped because their status was not eligible.";
        }

        return back()->with('success', $message);
    }

    private function syncPeriodStatus(PayrollPeriod $period): void
    {
        $hasRecords = $period->records()->exists();
        $hasUnreleasedRecords = $period->records()->where('status', '!=', 'released')->exists();
        $period->update(['status' => $hasRecords && ! $hasUnreleasedRecords ? 'finalized' : 'processing']);
    }

    private function expectedPayDate(string $startDate, string $endDate): ?string
    {
        $start = Carbon::parse($startDate, 'Asia/Manila');
        $end = Carbon::parse($endDate, 'Asia/Manila');

        if (! $start->isSameMonth($end) || ! $start->isSameYear($end)) {
            return null;
        }

        if ($start->day === 1 && $end->day === 15) {
            return $start->copy()->day(25)->toDateString();
        }

        if ($start->day === 16 && $end->day === $start->daysInMonth) {
            return $start->copy()->addMonthNoOverflow()->day(10)->toDateString();
        }

        return null;
    }
}

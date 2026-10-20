<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRecord;
use App\Services\PayrollComputationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $canReview = in_array($request->user()->role, ['admin', 'payroll_staff'], true);
        $query = LeaveRequest::with(['employee', 'leaveType', 'reviewer'])->latest('start_date')->latest('id');
        if (! $canReview) {
            abort_unless($request->user()->employee_id, 403, 'Your account is not linked to a faculty record.');
            $query->where('employee_id', $request->user()->employee_id);
        }

        return Inertia::render('Leave/Index', [
            'requests' => $query->paginate(15)->withQueryString(),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('name')->get(),
            'canReview' => $canReview,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'faculty' && $request->user()->employee_id, 403);
        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $employeeId = $request->user()->employee_id;
        $overlap = LeaveRequest::where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'These dates overlap an existing pending or approved leave request.']);
        }

        $leave = LeaveRequest::create([...$data, 'employee_id' => $employeeId]);
        $this->audit($request, 'leave.requested', $leave, 'Submitted a leave request.');

        return back()->with('success', 'Leave request submitted for review.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        abort_unless($request->user()->role === 'faculty' && $request->user()->employee_id === $leaveRequest->employee_id, 403);
        if ($leaveRequest->status !== 'pending') {
            return back()->with('error', 'Only pending leave requests can be cancelled.');
        }
        $leaveRequest->update(['status' => 'cancelled']);
        $this->audit($request, 'leave.cancelled', $leaveRequest, 'Cancelled a leave request.');

        return back()->with('success', 'Leave request cancelled.');
    }

    public function review(Request $request, LeaveRequest $leaveRequest)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reviewer_remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($leaveRequest->status !== 'pending') {
            return back()->with('error', 'This leave request has already been reviewed.');
        }

        $leaveRequest->update([
            ...$data,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        if ($data['status'] === 'approved') {
            $this->recalculateDraftPayroll($leaveRequest);
        }
        $this->audit($request, 'leave.'.$data['status'], $leaveRequest, ucfirst($data['status']).' a leave request.');

        return back()->with('success', 'Leave request '.$data['status'].'. Affected draft payroll records were updated.');
    }

    private function recalculateDraftPayroll(LeaveRequest $leave): void
    {
        $records = PayrollRecord::with(['employee', 'payrollPeriod'])
            ->where('employee_id', $leave->employee_id)
            ->where('status', 'draft')
            ->whereHas('payrollPeriod', fn ($period) => $period
                ->whereDate('start_date', '<=', $leave->end_date->toDateString())
                ->whereDate('end_date', '>=', $leave->start_date->toDateString())
                ->where('status', '!=', 'finalized'))
            ->get();

        foreach ($records as $record) {
            $computed = app(PayrollComputationService::class)->calculate(
                $record->employee,
                $record->payrollPeriod->start_date,
                $record->payrollPeriod->end_date,
            );
            $additionalEarnings = (float) $record->overtime_pay + (float) $record->other_earnings + (float) $record->increase_amount;
            $totalEarnings = round($computed['gross_pay'] + $additionalEarnings, 2);
            $record->update([
                ...$computed,
                'total_earnings' => $totalEarnings,
                'net_pay' => max(0, round($totalEarnings - (float) $record->total_deductions, 2)),
            ]);
        }
    }

    private function audit(Request $request, string $action, LeaveRequest $leave, string $description): void
    {
        DB::table('audit_trails')->insert([
            'action' => $action, 'table_name' => 'leave_requests', 'record_id' => $leave->id,
            'description' => $description." By user #{$request->user()->id}.", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

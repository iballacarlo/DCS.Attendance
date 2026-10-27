<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $canReview = in_array($request->user()->role, ['admin'], true);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $query = LeaveRequest::with(['employee', 'leaveType', 'reviewer']);
        if ($canReview) {
            $query->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id));
        }
        if (! $canReview) {
            abort_unless($request->user()->employee_id, 403, 'Your account is not linked to a faculty record.');
            $query->where('employee_id', $request->user()->employee_id);
        }

        $query
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('reason', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($employee) => $employee
                            ->where('employee_no', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['leave_type_id'] ?? null, fn ($query, $type) => $query->where('leave_type_id', $type))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('end_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('start_date', '<=', $date));

        if ($canReview) {
            $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END");
        }
        $query->latest('start_date')->latest('id');

        return Inertia::render('Leave/Index', [
            'requests' => $query->paginate(15)->withQueryString(),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('name')->get(),
            'canReview' => $canReview,
            'filters' => $filters,
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
            'reviewer_remarks' => [Rule::requiredIf($request->input('status') === 'rejected'), 'nullable', 'string', 'max:1000'],
        ]);
        if ($leaveRequest->status !== 'pending') {
            return back()->with('error', 'This leave request has already been reviewed.');
        }

        $leaveRequest->update([
            ...$data,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->audit($request, 'leave.'.$data['status'], $leaveRequest, ucfirst($data['status']).' a leave request.');

        return back()->with('success', 'Leave request '.$data['status'].'.');
    }

    private function audit(Request $request, string $action, LeaveRequest $leave, string $description): void
    {
        DB::table('audit_trails')->insert([
            'action' => $action, 'table_name' => 'leave_requests', 'record_id' => $leave->id,
            'description' => $description." By user #{$request->user()->id}.", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

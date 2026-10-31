<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HardwareEnrollment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FacultyArchiveController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'employee_id' => ['nullable', 'integer']]);
        $query = Employee::withoutGlobalScope('not_archived')->whereNotNull('archived_at');
        $query->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id));
        foreach (preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $query->where(function ($names) use ($term) {
                foreach (['employee_no', 'first_name', 'middle_name', 'last_name', 'email'] as $field) {
                    $names->orWhere($field, 'like', "%{$term}%");
                }
            });
        }

        return Inertia::render('Employees/Archived', [
            'employees' => $query->select(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix', 'email', 'status', 'archived_at'])
                ->withCount(['schedules' => fn ($schedules) => $schedules->withoutGlobalScope('visible_faculty'),
                    'scheduleBreaks' => fn ($breaks) => $breaks->withoutGlobalScope('visible_faculty')])
                ->orderByDesc('archived_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => ['search' => $filters['search'] ?? '', 'employee_id' => $filters['employee_id'] ?? ''],
            'archivedFacultyChoices' => Employee::withoutGlobalScope('not_archived')->whereNotNull('archived_at')->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'employee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->whereNull('archived_at')],
        ]);
        DB::transaction(function () use ($request, $data) {
            $employees = Employee::whereIn('id', $data['employee_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($employees->count() !== count($data['employee_ids'])) {
                throw ValidationException::withMessages(['employee_ids' => 'Some selected faculty are no longer available. Refresh the list.']);
            }
            foreach ($employees as $employee) {
                if ($request->user()->employee_id == $employee->id) {
                    throw ValidationException::withMessages(['employee_ids' => 'You cannot archive the faculty linked to your own account.']);
                }
            }
            foreach ($employees as $employee) {
                HardwareEnrollment::where('employee_id', $employee->id)->whereIn('status', ['pending', 'processing'])
                    ->update(['status' => 'cancelled', 'message' => 'Cancelled because this faculty was archived.']);
                $accountIds = User::where('employee_id', $employee->id)->pluck('id');
                User::whereIn('id', $accountIds)->update(['remember_token' => null]);
                DB::table('sessions')->whereIn('user_id', $accountIds)->delete();
                $employee->forceFill(['archived_at' => now(), 'archived_by' => $request->user()->id])->save();
                $this->audit($employee->id, 'faculty.archived', $request->user()->id);
            }
        });
        Inertia::clearHistory();

        return back()->with('success', count($data['employee_ids']).' faculty archived. Their schedules and history are preserved.');
    }

    public function restore(Request $request, int $archivedId)
    {
        DB::transaction(function () use ($request, $archivedId) {
            $employee = Employee::withoutGlobalScope('not_archived')->whereNotNull('archived_at')->lockForUpdate()->findOrFail($archivedId);
            $employee->forceFill(['archived_at' => null, 'archived_by' => null])->save();
            $this->audit($employee->id, 'faculty.restored', $request->user()->id);
        });
        Inertia::clearHistory();

        return back()->with('success', 'Faculty restored with their original status, schedule, and history.');
    }

    public function destroy(Request $request, int $archivedId)
    {
        DB::transaction(function () use ($request, $archivedId) {
            $employee = Employee::withoutGlobalScope('not_archived')->whereNotNull('archived_at')->lockForUpdate()->findOrFail($archivedId);
            $accountIds = User::withoutGlobalScopes()->where('employee_id', $employee->id)->pluck('id');
            DB::table('sessions')->whereIn('user_id', $accountIds)->delete();
            User::withoutGlobalScopes()->whereIn('id', $accountIds)->delete();
            foreach (['faculty_term_assignments', 'faculty_schedule_versions', 'semester_day_snapshots', 'semester_events'] as $table) {
                DB::table($table)->where('employee_id', $employee->id)->delete();
            }
            $this->audit($employee->id, 'faculty.deleted', $request->user()->id);
            $employee->delete();
        });
        Inertia::clearHistory();

        return back()->with('success', 'Archived faculty and all linked records were permanently deleted.');
    }

    private function audit(int $employeeId, string $action, int $actorId): void
    {
        DB::table('audit_trails')->insert(['action' => $action, 'table_name' => 'employees', 'record_id' => $employeeId,
            'description' => match ($action) {
                'faculty.archived' => "Archived faculty #{$employeeId} by administrator #{$actorId}.",
                'faculty.restored' => "Restored faculty #{$employeeId} by administrator #{$actorId}.",
                default => "Permanently deleted archived faculty #{$employeeId} by administrator #{$actorId}.",
            },
            'created_at' => now(), 'updated_at' => now()]);
    }
}

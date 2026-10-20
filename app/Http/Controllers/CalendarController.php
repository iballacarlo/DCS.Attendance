<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\DtrSignatory;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $year = (int) $request->integer('year', now('Asia/Manila')->year);
        $year = max(2000, min(2100, $year));
        $canManage = in_array($request->user()->role, ['admin', 'payroll_staff'], true);
        $employee = $request->user()->employee;
        $events = CalendarEvent::with('employees:id,employee_no,first_name,middle_name,last_name,suffix')
            ->whereYear('start_date', '<=', $year)
            ->whereYear('end_date', '>=', $year);
        if (! $canManage) {
            $events->where(function ($query) use ($employee) {
                $query->where('scope_type', 'campus');
                if ($employee) {
                    $query->orWhere(fn ($department) => $department->where('scope_type', 'department')->where('department', $employee->department))
                        ->orWhere(fn ($faculty) => $faculty->where('scope_type', 'faculty')->whereHas('employees', fn ($employees) => $employees->whereKey($employee->id)));
                }
            });
        }

        return Inertia::render('Calendar/Index', [
            'year' => $year,
            'events' => $events->orderBy('start_date')->orderBy('id')->get(),
            'employees' => $canManage ? Employee::where('status', 'active')->orderBy('last_name')->get() : [],
            'departments' => $canManage ? Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department') : [],
            'signatories' => DtrSignatory::orderByDesc('effective_from')->get(),
            'canManage' => $canManage,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateEvent($request);
        $data['is_paid'] = $data['type'] === 'regular_holiday' ? true : $data['is_paid'];
        $event = DB::transaction(function () use ($request, $data) {
            $event = CalendarEvent::create([
                ...collect($data)->except('employee_ids')->all(),
                'department' => $data['scope_type'] === 'department' ? $data['department'] : null,
                'created_by' => $request->user()->id,
            ]);
            $event->employees()->sync($data['scope_type'] === 'faculty' ? $data['employee_ids'] : []);
            $this->audit($request, 'calendar.created', $event, "Created {$event->title} ({$event->start_date->toDateString()} to {$event->end_date->toDateString()}).");

            return $event;
        });

        return back()->with('success', "{$event->title} added to the school calendar.");
    }

    public function update(Request $request, CalendarEvent $calendarEvent)
    {
        $data = $this->validateEvent($request);
        $data['is_paid'] = $data['type'] === 'regular_holiday' ? true : $data['is_paid'];
        DB::transaction(function () use ($request, $calendarEvent, $data) {
            $calendarEvent->update([
                ...collect($data)->except('employee_ids')->all(),
                'department' => $data['scope_type'] === 'department' ? $data['department'] : null,
            ]);
            $calendarEvent->employees()->sync($data['scope_type'] === 'faculty' ? $data['employee_ids'] : []);
            $this->audit($request, 'calendar.updated', $calendarEvent, "Updated {$calendarEvent->title}.");
        });

        return back()->with('success', 'Calendar event updated. Regenerate draft payroll to apply the change.');
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent)
    {
        $title = $calendarEvent->title;
        $this->audit($request, 'calendar.deleted', $calendarEvent, "Deleted {$title}.");
        $calendarEvent->delete();

        return back()->with('success', "{$title} removed from the school calendar.");
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['regular_holiday', 'special_holiday', 'asynchronous', 'suspension'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_paid' => ['required', 'boolean'],
            'scope_type' => ['required', Rule::in(['campus', 'department', 'faculty'])],
            'department' => [Rule::requiredIf($request->scope_type === 'department'), 'nullable', 'string', 'max:150'],
            'employee_ids' => [Rule::requiredIf($request->scope_type === 'faculty'), 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function audit(Request $request, string $action, CalendarEvent $event, string $description): void
    {
        DB::table('audit_trails')->insert([
            'action' => $action, 'table_name' => 'calendar_events', 'record_id' => $event->id,
            'description' => $description." By user #{$request->user()->id}.", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

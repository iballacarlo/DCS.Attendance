<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\User;
use App\Services\GoogleAppsScriptMailer;
use App\Support\ScheduleOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'rank' => ['nullable', 'integer', 'exists:faculty_ranks,id'],
            'status' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', 'in:employee_no,name,rank,rate,weekly_hours,status'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $sort = $filters['sort'] ?? 'employee_no';
        $direction = $filters['direction'] ?? 'desc';
        $employees = Employee::with(['rfidCards', 'fingerprintTemplates', 'facultyRank', 'schedules', 'user'])
            ->when($filters['rank'] ?? null, fn ($query, $rank) => $query->where('faculty_rank_id', $rank))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $terms = preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terms as $term) {
            $employees->where(function ($searchQuery) use ($term) {
                $searchQuery->where('employee_no', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('suffix', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($sort === 'name') {
            $employees->orderBy('last_name', $direction)->orderBy('first_name', $direction);
        } elseif ($sort === 'rank') {
            $employees->leftJoin('faculty_ranks as sorted_ranks', 'employees.faculty_rank_id', '=', 'sorted_ranks.id')
                ->select('employees.*')
                ->orderBy('sorted_ranks.name', $direction);
        } elseif ($sort === 'rate') {
            $employees->orderBy('rate_amount', $direction);
        } elseif ($sort === 'weekly_hours') {
            $scheduleHours = DB::table('faculty_schedules')
                ->selectRaw('employee_id, SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)) as weekly_minutes')
                ->groupBy('employee_id');

            $employees->leftJoinSub($scheduleHours, 'schedule_totals', function ($join) {
                $join->on('employees.id', '=', 'schedule_totals.employee_id');
            })
                ->select('employees.*')
                ->orderByRaw("COALESCE(schedule_totals.weekly_minutes, 0) {$direction}");
        } elseif ($sort === 'status') {
            $employees->orderBy('status', $direction)->orderBy('last_name');
        } else {
            $employees->orderByRaw("CAST(SUBSTRING_INDEX(employee_no, '-', -1) AS UNSIGNED) {$direction}");
        }

        $employees = $employees
            ->paginate(10)
            ->withQueryString();
        $today = Carbon::today();
        $employees->getCollection()->each(function (Employee $employee) use ($today) {
            $daysRemaining = $employee->contract_end
                ? $today->diffInDays(Carbon::parse($employee->contract_end), false)
                : null;
            $employee->setAttribute('contract_days_remaining', $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 7 ? $daysRemaining : null);
        });

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'rank' => $filters['rank'] ?? '',
                'status' => $filters['status'] ?? '',
                'sort' => $sort,
                'direction' => $direction,
            ],
            'ranks' => FacultyRank::get(['id', 'name', 'salary_grade'])
                ->sortBy(fn (FacultyRank $rank) => (int) preg_replace('/\D/', '', $rank->salary_grade ?? '999'))
                ->values(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Employees/Form', [
            'employee' => new Employee,
            'ranks' => $this->standardRanks(),
            'nextEmployeeNumber' => $this->nextEmployeeNumber(),
        ]);
    }

    public function store(Request $request, GoogleAppsScriptMailer $mailer)
    {
        $temporaryPassword = Str::random(14);
        $credentials = DB::transaction(function () use ($request, $temporaryPassword) {
            $data = $this->validated($request, null, true);
            $data['employee_no'] = $this->nextEmployeeNumber();

            $employee = Employee::create($data);
            User::create([
                'employee_id' => $employee->id,
                'name' => implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name, $employee->suffix])),
                'email' => $employee->email,
                'password' => $temporaryPassword,
                'role' => 'faculty',
                'must_change_password' => true,
            ]);

            return ['employee' => $employee, 'email' => $employee->email, 'password' => $temporaryPassword];
        });

        $verificationSent = $mailer->sendVerification(
            $credentials['employee']->user,
            $credentials['password']
        );

        return redirect(route('employees.edit', $credentials['employee']).'#attendance-identifiers')
            ->with('success', $verificationSent
                ? 'Faculty account created. A verification link was sent to the email address.'
                : 'Faculty account created, but the verification email was not sent. Check the Google mail configuration.')
            ->with('temporary_credentials', [
                'email' => $credentials['email'],
                'password' => $credentials['password'],
            ]);
    }

    public function edit(Employee $employee)
    {
        $employee->load(['rfidCards', 'fingerprintTemplates', 'employmentHistories']);

        return Inertia::render('Employees/Form', [
            'employee' => $employee,
            'ranks' => $this->standardRanks(),
            'nextEmployeeNumber' => null,
        ]);
    }

    public function update(Request $request, Employee $employee)
    {
        DB::transaction(function () use ($request, $employee) {
            $employee->update($this->validated($request, $employee->id));
            if ($employee->user) {
                $employee->user->update([
                    'name' => implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name, $employee->suffix])),
                    'email' => $employee->email,
                ]);
            }
            $this->saveDevices($request, $employee);
            $this->saveSchedules($request, $employee);
            $this->saveEmploymentHistory($request, $employee);
        });

        return redirect()->route('employees.index')->with('success', 'Faculty record updated.');
    }

    public function editSchedule(Employee $employee)
    {
        $employee->load(['schedules', 'scheduleBreaks']);

        return Inertia::render('Employees/Schedule', ['employee' => $employee, 'scheduleOptions' => ScheduleOptions::all()]);
    }

    public function mySchedule(Request $request)
    {
        $employee = Employee::with(['schedules', 'scheduleBreaks'])->findOrFail($request->user()->employee_id);

        return Inertia::render('Employees/Schedule', ['employee' => $employee, 'readOnly' => true, 'scheduleOptions' => ScheduleOptions::all()]);
    }

    public function updateSchedule(Request $request, Employee $employee)
    {
        $this->saveSchedules($request, $employee);

        return redirect()->route('employees.schedule.edit', $employee)->with('success', 'Faculty schedule updated.');
    }

    public function destroy(Employee $employee)
    {
        DB::transaction(function () use ($employee) {
            $employee->user?->delete();
            $employee->delete();
        });

        return redirect()->route('employees.index')->with('success', 'Faculty record deleted.');
    }

    private function validated(Request $request, ?int $employeeId = null, bool $isCreating = false): array
    {
        $linkedUserId = $employeeId ? User::where('employee_id', $employeeId)->value('id') : null;
        $data = $request->validate([
            'employee_no' => $isCreating
                ? ['nullable']
                : ['required', 'max:30', 'unique:employees,employee_no,'.$employeeId.',id'],
            'first_name' => ['required', 'max:100'],
            'middle_name' => ['nullable', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'email' => [
                'required',
                'email:rfc',
                'ends_with:@cvsu.edu.ph',
                'max:100',
                Rule::unique('employees', 'email')->ignore($employeeId),
                Rule::unique('users', 'email')->ignore($linkedUserId),
            ],
            'contact_no' => ['nullable', 'regex:/^\+639\d{9}$/'],
            'highest_educational_attainment' => ['required', Rule::in([
                "Bachelor's Degree",
                'Post-Baccalaureate Certificate or Diploma',
                "Master's Degree Units",
                "Master's Degree",
                'Doctorate Degree Units',
                'Doctorate Degree',
                'Postdoctoral Studies',
            ])],
            'service_start_date' => ['required', 'date', 'before_or_equal:today'],
            'faculty_rank_id' => [
                'required',
                Rule::exists('faculty_ranks', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'rate_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'contract_start' => ['nullable', 'required_with:contract_end', 'date'],
            'contract_end' => ['nullable', 'required_with:contract_start', 'date', 'after_or_equal:contract_start'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $rank = FacultyRank::where('is_active', true)->findOrFail($data['faculty_rank_id']);
        $data['rate_type'] = 'hourly';
        $data['rate_amount'] = $rank->rate_amount;
        $data['position'] = 'COS Faculty Member';
        $data['department'] = 'Department of Computer Studies';
        $data['employment_type'] = 'Contract of Service';

        return $data;
    }

    private function saveEmploymentHistory(Request $request, Employee $employee): void
    {
        $data = $request->validate([
            'employment_history' => ['nullable', 'array', 'max:20'],
            'employment_history.*.employer' => ['required', 'string', 'max:150'],
            'employment_history.*.position' => ['required', 'string', 'max:150'],
            'employment_history.*.started_on' => ['required', 'date'],
            'employment_history.*.ended_on' => ['nullable', 'date'],
        ]);

        $employee->employmentHistories()->delete();
        foreach ($data['employment_history'] ?? [] as $history) {
            if ($history['ended_on'] && $history['ended_on'] < $history['started_on']) {
                throw ValidationException::withMessages([
                    'employment_history' => 'Employment end month must be after its start month.',
                ]);
            }
            $employee->employmentHistories()->create($history);
        }
    }

    private function saveDevices(Request $request, Employee $employee): void
    {
        $deviceData = $request->validate([
            'rfid_uid' => [
                'nullable',
                'max:100',
                Rule::unique('rfid_cards', 'rfid_uid')->ignore(optional($employee->rfidCards()->first())->id),
            ],
            'fingerprint_code' => [
                'nullable',
                'max:100',
                Rule::unique('fingerprint_templates', 'fingerprint_code')->ignore(optional($employee->fingerprintTemplates()->first())->id),
            ],
            'finger_label' => ['nullable', 'string', 'max:50'],
            'rfid_reregister' => ['sometimes', 'boolean'],
            'fingerprint_reregister' => ['sometimes', 'boolean'],
        ]);

        if ($request->filled('rfid_uid')) {
            $rfid = $employee->rfidCards()->firstOrNew(['employee_id' => $employee->id]);
            $changed = $rfid->rfid_uid !== $deviceData['rfid_uid'];
            $rfid->fill(['rfid_uid' => $deviceData['rfid_uid'], 'status' => 'active']);
            if (! $rfid->exists || $changed || ($deviceData['rfid_reregister'] ?? false)) {
                $rfid->registered_at = Carbon::now();
            }
            $rfid->save();
        } else {
            $employee->rfidCards()->delete();
        }

        if ($request->filled('fingerprint_code')) {
            $fingerprint = $employee->fingerprintTemplates()->firstOrNew(['employee_id' => $employee->id]);
            $changed = $fingerprint->fingerprint_code !== $deviceData['fingerprint_code'];
            $fingerprint->fill([
                'fingerprint_code' => $deviceData['fingerprint_code'],
                'finger_label' => ($deviceData['finger_label'] ?? null) ?: 'Primary finger',
                'status' => 'active',
            ]);
            if (! $fingerprint->exists || $changed || ($deviceData['fingerprint_reregister'] ?? false)) {
                $fingerprint->registered_at = Carbon::now();
            }
            $fingerprint->save();
        } else {
            $employee->fingerprintTemplates()->delete();
        }
    }

    private function saveSchedules(Request $request, Employee $employee): void
    {
        if (! $request->has('blocks')) {
            return;
        }
        $data = $request->validate([
            'blocks' => ['present', 'array'], 'blocks.*.id' => ['nullable', 'integer'],
            'blocks.*.day_of_week' => ['required', 'integer', 'between:1,6'],
            'blocks.*.schedule_type' => ['required', 'in:class,consultation,lunch_break'],
            'blocks.*.start_time' => ['required', 'date_format:H:i'], 'blocks.*.end_time' => ['required', 'date_format:H:i'],
            'blocks.*.subject' => ['nullable', 'string', 'max:255'], 'blocks.*.section' => ['nullable', 'string', 'max:100'],
            'blocks.*.class_mode' => ['nullable', Rule::in(['lecture', 'laboratory'])],
        ]);
        $blocks = $data['blocks'];
        $ids = [];
        foreach ($blocks as $i => $block) {
            if ($block['end_time'] <= $block['start_time']) {
                throw ValidationException::withMessages(["blocks.$i.end_time" => 'End time must be after start time.']);
            }
            $relation = $block['schedule_type'] === 'lunch_break' ? $employee->scheduleBreaks() : $employee->schedules();
            $existing = ! empty($block['id']) ? $relation->find($block['id']) : null;
            if (! empty($block['id']) && ! $existing) {
                throw ValidationException::withMessages(["blocks.$i.id" => 'Invalid schedule block.']);
            }
            $key = ($block['schedule_type'] === 'lunch_break' ? 'break:' : 'schedule:').($block['id'] ?? "new:$i");
            if (in_array($key, $ids, true)) {
                throw ValidationException::withMessages(['blocks' => 'Duplicate schedule block.']);
            }
            $ids[] = $key;
            if ($block['schedule_type'] === 'class') {
                foreach (['subject' => ScheduleOptions::SUBJECTS, 'section' => ScheduleOptions::sections(), 'class_mode' => array_keys(ScheduleOptions::CLASS_MODES)] as $field => $allowed) {
                    $value = $block[$field] ?? null;
                    // Preserve existing legacy values; new/changed selections must use the catalog.
                    if ((! $existing || $value !== $existing->$field) && ! in_array($value, $allowed, true)) {
                        throw ValidationException::withMessages(["blocks.$i.$field" => 'Select a valid '.str_replace('_', ' ', $field).'.']);
                    }
                }
                foreach (['subject', 'section'] as $field) {
                    // Existing unnamed blocks can be retained until the administrator completes them.
                    if (empty(trim($block[$field] ?? '')) && (! $existing || $existing->$field)) {
                        throw ValidationException::withMessages(["blocks.$i.$field" => ucfirst($field).' is required for a class.']);
                    }
                }
            }
            foreach (array_slice($blocks, 0, $i) as $other) {
                if ($block['day_of_week'] == $other['day_of_week'] && $block['start_time'] < $other['end_time'] && $block['end_time'] > $other['start_time']) {
                    throw ValidationException::withMessages(["blocks.$i.start_time" => 'Class, consultation, and break blocks cannot overlap.']);
                }
            }
        }
        DB::transaction(function () use ($employee, $blocks) {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $kept = [];
            $keptBreaks = [];
            foreach ($blocks as $block) {
                $isBreak = $block['schedule_type'] === 'lunch_break';
                $relation = $isBreak ? $employee->scheduleBreaks() : $employee->schedules();
                $values = collect($block)->except('id')->all();
                if ($isBreak) {
                    unset($values['subject'], $values['section'], $values['class_mode']);
                } else {
                    $values['break_minutes'] = 0;
                    $values['class_mode'] = $block['schedule_type'] === 'class' ? ($block['class_mode'] ?? null) : null;
                }
                $record = $relation->updateOrCreate(['id' => $block['id'] ?? null], $values);
                if ($isBreak) {
                    $keptBreaks[] = $record->id;
                } else {
                    $kept[] = $record->id;
                }
            }
            $employee->schedules()->whereNotIn('id', $kept)->delete();
            $employee->scheduleBreaks()->whereNotIn('id', $keptBreaks)->delete();
        });
    }

    private function standardRanks()
    {
        return FacultyRank::where('is_active', true)
            ->whereNotNull('salary_grade')
            ->orderBy('monthly_salary')
            ->get();
    }

    private function nextEmployeeNumber(): string
    {
        $usedNumbers = Employee::withoutGlobalScope('not_archived')
            ->pluck('employee_no')
            ->map(fn (string $employeeNo) => (int) preg_replace('/\D/', '', $employeeNo))
            ->filter()
            ->all();

        $nextNumber = 1;

        while (in_array($nextNumber, $usedNumbers, true)) {
            $nextNumber++;
        }

        return 'COS-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}

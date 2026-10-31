<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\FacultySchedule;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\User;
use App\Services\GoogleAppsScriptMailer;
use App\Services\SemesterManagementService;
use App\Services\SemesterScheduleService;
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
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'rank' => ['nullable', 'integer', 'exists:faculty_ranks,id'],
            'status' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', 'in:employee_no,name,rank,weekly_hours,status'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $sort = $filters['sort'] ?? 'employee_no';
        $direction = $filters['direction'] ?? 'desc';
        $employees = Employee::with(['rfidCards', 'fingerprintTemplates', 'facultyRank', 'schedules', 'user'])
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employees.id', $id))
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

        } elseif ($sort === 'weekly_hours') {
            $scheduleHours = DB::table('faculty_schedules')
                ->selectRaw('employee_id, SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time)) as weekly_minutes')
                ->when(SemesterScheduleService::managed(), function ($query) {
                    $term = SemesterScheduleService::termFor(now('Asia/Manila'));
                    $ids = FacultyScheduleVersion::where('academic_term_id', $term?->id ?? 0)
                        ->whereDate('effective_from', '<=', now('Asia/Manila')->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->get()->unique('employee_id')->pluck('id');
                    $query->whereIn('schedule_version_id', $ids);
                }, fn ($query) => $query->whereNull('academic_term_id'))
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
            $employee->setRelation('schedules', SemesterScheduleService::schedules($employee, $today));
            $daysRemaining = $employee->contract_end
                ? $today->diffInDays(Carbon::parse($employee->contract_end), false)
                : null;
            $employee->setAttribute('contract_days_remaining', $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 7 ? $daysRemaining : null);
        });

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'employee_id' => $filters['employee_id'] ?? '',
                'rank' => $filters['rank'] ?? '',
                'status' => $filters['status'] ?? '',
                'sort' => $sort,
                'direction' => $direction,
            ],
            'ranks' => FacultyRank::get(['id', 'name'])->sortBy('id')
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
        $temporaryPassword = Str::random(16);
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

        $passwordSent = $mailer->sendTemporaryPassword($credentials['employee']->user, $credentials['password']);

        return redirect(route('employees.edit', $credentials['employee']).'#attendance-identifiers')
            ->with($passwordSent ? 'success' : 'error', $passwordSent
                ? 'Faculty account created. The temporary password was sent directly to the faculty email.'
                : 'Faculty account created, but the temporary password email was not sent. Check the Google mail configuration.');
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
            $this->saveDevices($request, $employee);
            $this->saveSchedules($request, $employee);
        });

        return redirect()->route('employees.index')->with('success', 'Faculty record updated.');
    }

    public function createAccount(Employee $employee, GoogleAppsScriptMailer $mailer)
    {
        if ($employee->user) {
            return back()->with('error', 'This faculty already has a linked account.');
        }
        if (User::withoutGlobalScopes()->where('email', $employee->email)->exists()) {
            return back()->with('error', 'The faculty email is already used by another account.');
        }

        $temporaryPassword = Str::random(20);
        $user = User::create([
            'employee_id' => $employee->id,
            'name' => implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name, $employee->suffix])),
            'email' => strtolower($employee->email),
            'password' => $temporaryPassword,
            'role' => 'faculty',
            'must_change_password' => true,
        ]);
        $sent = $mailer->sendTemporaryPassword($user, $temporaryPassword);

        return back()->with($sent ? 'success' : 'error', $sent
            ? 'Faculty account created and the temporary password was sent to the faculty email.'
            : 'Faculty account created, but the temporary password email could not be sent. The faculty can use Forgot Password.');
    }

    public function editSchedule(Request $request, $employee)
    {
        return $this->schedulePage($request, Employee::withoutGlobalScopes()->findOrFail($employee), false);
    }

    public function mySchedule(Request $request)
    {
        return $this->schedulePage($request, Employee::findOrFail($request->user()->employee_id), true);
    }

    private function schedulePage(Request $request, Employee $employee, bool $faculty)
    {
        $data = $request->validate(['term_id' => ['nullable', 'integer', 'exists:academic_terms,id'], 'version_id' => ['nullable', 'integer', 'exists:faculty_schedule_versions,id']]);
        $term = ! empty($data['term_id']) ? AcademicTerm::findOrFail($data['term_id']) : null;
        if (! $request->has('term_id')) {
            $available = AcademicTerm::where('status', '!=', 'draft');
            if ($faculty) {
                $available->whereIn('id', FacultyScheduleVersion::where('employee_id', $employee->id)->select('academic_term_id'));
            }
            $term = $available->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->inAcademicOrder()->first();
        }
        abort_if($faculty && $term?->status === 'draft', 404);
        abort_if($employee->archived_at && ! $term, 404);
        $employee->load(['schedules', 'scheduleBreaks', 'facultyRank']);
        $versions = collect();
        $assignment = null;
        $version = null;
        if ($term) {
            $assignment = FacultyTermAssignment::where('academic_term_id', $term->id)->where('employee_id', $employee->id)->first();
            $versions = FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
            $version = ! empty($data['version_id']) ? $versions->firstWhere('id', (int) $data['version_id']) : $versions->first();
            abort_if(! empty($data['version_id']) && ! $version, 404);
            if ($faculty || ($term->status === 'closed' && ! $term->reopened) || ! empty($data['version_id'])) {
                $blocks = $version ? $employee->schedules->where('schedule_version_id', $version->id)->concat($employee->scheduleBreaks->where('schedule_version_id', $version->id)) : collect();
                if ($version) {
                    $employee->forceFill($version->profile);
                }
            } else {
                $blocks = collect($assignment?->draft_blocks ?? [])->map(fn ($block) => new FacultySchedule($block));
            }
            $employee->setRelation('schedules', $blocks->where('schedule_type', '!=', 'lunch_break')->values());
            $employee->setRelation('scheduleBreaks', $blocks->where('schedule_type', 'lunch_break')->values());
        } else {
            $employee->setRelation('schedules', SemesterScheduleService::managed() ? collect() : $employee->schedules->whereNull('academic_term_id')->values());
            $employee->setRelation('scheduleBreaks', SemesterScheduleService::managed() ? collect() : $employee->scheduleBreaks->whereNull('academic_term_id')->values());
        }

        return Inertia::render('Employees/Schedule', [
            'employee' => $employee, 'readOnly' => $faculty || ($term ? ($term->status === 'closed' && ! $term->reopened) || ! empty($data['version_id']) : SemesterScheduleService::managed()),
            'scheduleOptions' => ScheduleOptions::all($term?->semester),
            'facultyView' => $faculty,
            ...($faculty ? [] : ['facultyOptions' => Employee::orderBy('last_name')->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix'])]),
            'selectedSemester' => $term, 'scheduleVersions' => $versions, 'selectedVersion' => $data['version_id'] ?? '',
            'hasUnpublishedSchedule' => ! $faculty && $term && $assignment && app(SemesterManagementService::class)->publicationPending($term, $employee, $assignment->draft_blocks ?? [], $assignment->profile),
            'assignmentProfile' => ($faculty ? $version?->profile : $assignment?->profile) ?? app(SemesterManagementService::class)->profile($employee),
        ]);
    }

    public function updateSchedule(Request $request, $employee)
    {
        $employee = Employee::withoutGlobalScopes()->findOrFail($employee);
        $this->saveSchedules($request, $employee);

        return redirect()->route('employees.schedule.edit', ['employee' => $employee->id, 'term_id' => $request->input('term_id')])->with('success', 'Faculty schedule updated.');
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
        $rules = [
            'employee_no' => $isCreating
                ? ['nullable']
                : ['required', 'max:30', 'unique:employees,employee_no,'.$employeeId.',id'],
            'faculty_rank_id' => [
                'required',
                Rule::exists('faculty_ranks', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'contract_start' => ['nullable', 'required_with:contract_end', 'date'],
            'contract_end' => ['nullable', 'required_with:contract_start', 'date', 'after_or_equal:contract_start'],
            'status' => ['required', 'in:active,inactive'],
        ];

        if ($isCreating) {
            $rules += [
                'first_name' => ['required', 'max:100'],
                'middle_name' => ['nullable', 'max:100'],
                'last_name' => ['required', 'max:100'],
                'suffix' => ['nullable', 'string', 'max:20'],
                'email' => ['required', 'email:rfc', 'ends_with:@cvsu.edu.ph', 'max:100', Rule::unique('employees', 'email'), Rule::unique('users', 'email')],
            ];
        }

        $data = $request->validate($rules);

        // Retained legacy columns are initialized only for new profiles.
        if ($isCreating) {
            $data['rate_type'] = 'hourly';
            $data['rate_amount'] = 0;
        }
        $data['position'] = 'COS Faculty Member';
        $data['department'] = 'Department of Computer Studies';
        $data['employment_type'] = 'Contract of Service';

        return $data;
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
        if ($request->filled('term_id')) {
            $data = $request->validate(['term_id' => ['required', 'integer', 'exists:academic_terms,id'],
                'blocks' => ['present', 'array'], 'intent' => ['required', 'in:draft,publish'],
                'effective_from' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:1000'], 'assignment_profile' => ['nullable', 'array']]);
            app(SemesterManagementService::class)->save(AcademicTerm::findOrFail($data['term_id']), $employee,
                $data['blocks'], $data['assignment_profile'] ?? [], $data['intent'], $data['effective_from'] ?? null, $data['reason'] ?? '', $request->user());

            return;
        }
        if (SemesterScheduleService::managed() && $request->has('blocks')) {
            throw ValidationException::withMessages(['semester' => 'Select a semester before changing schedules.']);
        }
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
            foreach (['start_time', 'end_time'] as $field) {
                if ($block[$field] < '07:00' || $block[$field] > '19:00') {
                    throw ValidationException::withMessages(["blocks.$i.$field" => 'Schedule blocks must be within 7:00 AM and 7:00 PM.']);
                }
            }
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
                if (($block['class_mode'] ?? '') === 'laboratory' && in_array($block['subject'] ?? '', ScheduleOptions::LECTURE_ONLY_SUBJECTS, true)) {
                    throw ValidationException::withMessages(["blocks.$i.subject" => 'This subject is available for Lecture only.']);
                }
                foreach (['section' => ScheduleOptions::sections(), 'subject' => ScheduleOptions::subjectsFor($block['section'] ?? ''), 'class_mode' => array_keys(ScheduleOptions::CLASS_MODES)] as $field => $allowed) {
                    $value = $block[$field] ?? null;
                    // Preserve existing legacy values; new/changed selections must use the catalog.
                    if ((! $existing || (string) $value !== (string) $existing->$field || ($field === 'subject' && (string) ($block['section'] ?? '') !== (string) $existing->section)) && ! in_array($value, $allowed, true)) {
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

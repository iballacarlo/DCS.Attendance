<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\AttendanceLog;
use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\LeaveRequest;
use App\Models\SemesterDaySnapshot;
use App\Models\SemesterEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ScheduleOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SemesterManagementService
{
    public function lock(): void
    {
        SystemSetting::firstOrCreate(['key' => 'semester_write_lock'], ['value' => '1']);
        SystemSetting::where('key', 'semester_write_lock')->lockForUpdate()->first();
    }

    public function create(array $data, User $actor): AcademicTerm
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->lock();
            $data = Validator::make($data, ['school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
                'semester' => ['required', 'in:First Semester,Second Semester,Midyear Term'],
                'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']])->validate();
            [$first, $last] = array_map('intval', explode('-', $data['school_year']));
            $this->validateAcademicYearDates($data, $first, $last);
            if ($last !== $first + 1 || Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 366 || AcademicTerm::where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists()
                || AcademicTerm::where('school_year', $data['school_year'])->where('semester', $data['semester'])->exists()) {
                $this->invalid('Choose consecutive academic years, a unique semester, and dates that do not overlap another semester.');
            }
            $term = AcademicTerm::create([...$data, 'status' => 'scheduled']);
            SystemSetting::updateOrCreate(['key' => 'semester_management_enabled'], ['value' => '1']);
            $this->event($term, $actor, 'created', 'Created semester', null, $term->toArray());

            app(SemesterLifecycleService::class)->synchronize();

            return $term->fresh();
        });
    }

    public function update(AcademicTerm $term, array $data, User $actor): void
    {
        DB::transaction(function () use ($term, $data, $actor) {
            $this->lock();
            $term = AcademicTerm::lockForUpdate()->findOrFail($term->id);
            if (! in_array($term->status, ['draft', 'scheduled', 'active'], true)) {
                $this->invalid('Only upcoming or active semester details can be edited.');
            }
            $data = Validator::make($data, ['school_year' => ['required', 'regex:/^\\d{4}-\\d{4}$/'],
                'semester' => ['required', 'in:First Semester,Second Semester,Midyear Term'],
                'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']])->validate();
            [$first, $last] = array_map('intval', explode('-', $data['school_year']));
            $this->validateAcademicYearDates($data, $first, $last);
            $versions = FacultyScheduleVersion::where('academic_term_id', $term->id)->get();
            if ($last !== $first + 1 || Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 366
                || AcademicTerm::where('id', '!=', $term->id)->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists()
                || AcademicTerm::where('id', '!=', $term->id)->where('school_year', $data['school_year'])->where('semester', $data['semester'])->exists()
                || ($versions->isNotEmpty() && $term->semester !== $data['semester'])
                || $versions->contains(fn ($version) => $version->effective_from->toDateString() < $data['start_date'] || $version->effective_from->toDateString() > $data['end_date'])) {
                $this->invalid('Semester details conflict with another semester or already published versions.');
            }
            if ($term->status === 'active') {
                $today = Carbon::today('Asia/Manila')->toDateString();
                if ($data['start_date'] > $today || $data['end_date'] < $today) {
                    $this->invalid('Keep today within the active semester dates.');
                }
                if ($data['start_date'] !== $term->start_date->toDateString() && $this->hasHistoricalBasis($term)) {
                    $this->invalid('The start date is fixed once schedules or attendance have been recorded. Existing historical records are preserved.');
                }
            }
            foreach ([AttendanceLog::class, EntranceAttendance::class] as $model) {
                if ($this->attendanceForTerm($model, $term)->where(fn ($query) => $query
                    ->whereDate('attendance_date', '<', $data['start_date'])->orWhereDate('attendance_date', '>', $data['end_date']))->exists()) {
                    $this->invalid('These dates would exclude existing attendance records. Keep those dates inside the semester.');
                }
            }
            $before = $term->toArray();
            $term->update($data);
            $this->event($term, $actor, 'details_updated', 'Updated semester details', $before, $term->fresh()->toArray());
            app(SemesterLifecycleService::class)->synchronize();
        });
    }

    public function deleteUpcoming(AcademicTerm $term, User $actor): void
    {
        abort_unless($actor->role === 'admin', 403);
        DB::transaction(function () use ($term) {
            $this->lock();
            $term = AcademicTerm::lockForUpdate()->findOrFail($term->id);
            if (! in_array($term->status, ['draft', 'scheduled'], true) || $term->start_date->lte(Carbon::today('Asia/Manila'))) {
                $this->invalid('Only a semester that has not started can be deleted.');
            }
            $scheduleIds = DB::table('faculty_schedules')->where('academic_term_id', $term->id)->pluck('id');
            if ($this->attendanceForTerm(AttendanceLog::class, $term)->exists()
                || $this->attendanceForTerm(EntranceAttendance::class, $term)->exists()
                || AttendanceLog::withoutGlobalScopes()->whereIn('faculty_schedule_id', $scheduleIds)->exists()
                || SemesterDaySnapshot::where('academic_term_id', $term->id)->exists()) {
                $this->invalid('This semester has attendance or historical records and cannot be deleted.');
            }
            // Remove only preparation data belonging to this future semester.
            // Published schedule rows are normally immutable; this scoped cleanup
            // is permitted only before the semester starts and with no attendance.
            foreach (['faculty_schedules', 'faculty_schedule_breaks', 'faculty_schedule_versions', 'faculty_term_assignments', 'semester_events'] as $table) {
                DB::table($table)->where('academic_term_id', $term->id)->delete();
            }
            $term->delete();
            SemesterScheduleService::reset();
        });
    }

    public function hasHistoricalBasis(AcademicTerm $term): bool
    {
        return FacultyScheduleVersion::where('academic_term_id', $term->id)->exists()
            || $this->attendanceForTerm(AttendanceLog::class, $term)->exists()
            || $this->attendanceForTerm(EntranceAttendance::class, $term)->exists();
    }

    private function attendanceForTerm(string $model, AcademicTerm $term)
    {
        return $model::withoutGlobalScopes()->where(fn ($query) => $query->where('academic_term_id', $term->id)
            ->orWhere(fn ($query) => $query->whereNull('academic_term_id')
                ->whereBetween('attendance_date', [$term->start_date->toDateString(), $term->end_date->toDateString()])));
    }

    private function validateAcademicYearDates(array $data, int $first, int $last): void
    {
        $errors = [];
        foreach (['start_date', 'end_date'] as $field) {
            $year = (int) substr($data[$field], 0, 4);
            if ($year < $first || $year > $last) {
                $errors[$field] = 'Choose a date within Academic Year '.$data['school_year'].'.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function transition(AcademicTerm $term, string $action, string $reason, User $actor): void
    {
        app(SemesterLifecycleService::class)->synchronize();
        DB::transaction(function () use ($term, $action, $reason, $actor) {
            $this->lock();
            $term = AcademicTerm::lockForUpdate()->findOrFail($term->id);
            $before = $term->toArray();
            $today = Carbon::today('Asia/Manila');
            if ($action === 'activate') {
                // Compatibility with old clients. Date-based activation already ran.
                if (in_array($term->status, ['active', 'scheduled'], true)) {
                    return;
                }
                if ($term->status !== 'draft' || $term->end_date->lt($today)) {
                    $this->invalid('Choose a draft whose semester has not ended.');
                }
                $future = $term->start_date->gt($today);
                if (! $future && AcademicTerm::where('status', 'active')->exists()) {
                    $this->invalid('Another semester is active for these dates.');
                }
                $term->update(['status' => $future ? 'scheduled' : 'active']);
                if ($future) {
                    $action = 'schedule';
                    $reason = 'Automatic semester start scheduled by administrator.';
                }
                SystemSetting::updateOrCreate(['key' => 'semester_management_enabled'], ['value' => '1']);
            } elseif ($action === 'close' || $action === 'archive_legacy') {
                if (($action === 'archive_legacy' ? ! in_array($term->status, ['draft', 'ended'], true) : (! in_array($term->status, ['active', 'ended'], true) && ! $term->reopened)) || $term->end_date->gte($today)) {
                    $this->invalid('Close the semester after its last day.');
                }
                if (LeaveRequest::where('status', 'pending')->whereDate('start_date', '<=', $term->end_date)->whereDate('end_date', '>=', $term->start_date)->exists()) {
                    $this->invalid('Resolve pending leave requests in this semester before closing it.');
                }
                if ($action === 'archive_legacy') {
                    $term->update(['status' => 'closed', 'reopened' => true]);
                }
                $this->freeze($term);
                $term->update(['status' => 'closed', 'reopened' => false]);
            } elseif ($action === 'reopen') {
                if ($term->status !== 'closed' || $term->reopened || ! trim($reason)) {
                    $this->invalid('Only a closed semester can be reopened, with a correction reason.');
                }
                $term->update(['reopened' => true]);
            } elseif ($action === 'map_legacy') {
                if ($term->status === 'closed' && ! $term->reopened) {
                    $this->invalid('Reopen this semester before mapping legacy records.');
                }
                // Explicit administrator-confirmed dates; never infer schedules or taps.
                foreach ([AttendanceLog::class, EntranceAttendance::class] as $model) {
                    $model::withoutGlobalScopes()->whereNull('academic_term_id')->whereBetween('attendance_date', [$term->start_date->toDateString(), $term->end_date->toDateString()])
                        ->update(['academic_term_id' => $term->id]);
                }
            } else {
                $this->invalid('Unknown semester action.');
            }
            $this->event($term, $actor, $action, $reason ?: 'Semester '.$action, $before, $term->fresh()->toArray());
        });
    }

    public function save(AcademicTerm $term, Employee $employee, array $blocks, array $profile, string $intent, ?string $effective, string $reason, User $actor): void
    {
        DB::transaction(function () use ($term, $employee, $blocks, $profile, $intent, $effective, $reason, $actor) {
            $this->lock();
            $term = AcademicTerm::lockForUpdate()->findOrFail($term->id);
            if ($term->status === 'closed' && ! $term->reopened) {
                $this->invalid('Reopen this semester before changing its schedules.');
            }
            $blocks = $this->validateBlocks($blocks, $term, $intent === 'publish');
            $storedProfile = FacultyTermAssignment::where('academic_term_id', $term->id)->where('employee_id', $employee->id)->value('profile');
            $profile = array_merge($storedProfile ?? $this->profile($employee), Validator::make($profile, [
                'position' => ['nullable', 'string', 'max:255'],
                'contract_start' => ['nullable', 'date_format:Y-m-d'], 'contract_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:contract_start'],
            ])->validate());
            if (! $term->reopened) {
                $profile['faculty_rank_id'] = $employee->faculty_rank_id;
            }
            $assignment = FacultyTermAssignment::firstOrCreate(['academic_term_id' => $term->id, 'employee_id' => $employee->id], ['profile' => $profile, 'draft_blocks' => []]);
            $before = $assignment->toArray();
            $assignment->update(['draft_blocks' => $blocks, 'profile' => $profile]);
            if ($intent === 'publish') {
                if ($term->reopened && ! trim($reason)) {
                    $this->invalid('A reason is required for a historical correction.');
                }
                $latest = FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $employee->id)
                    ->orderByDesc('effective_from')->orderByDesc('id')->first();
                $date = $effective ? Carbon::parse($effective, 'Asia/Manila')->startOfDay() : $this->automaticEffectiveDate($term, $latest);
                if (! $date->betweenIncluded($term->start_date, $term->end_date)) {
                    $this->invalid('The effective date must fall within this semester.');
                }
                if ($latest && ! $term->reopened && $date->lte(Carbon::today('Asia/Manila'))) {
                    $this->invalid('Publish changes from tomorrow or later. Reopen a closed semester for historical corrections.');
                }
                if ($term->reopened && ! trim($reason)) {
                    $this->invalid('A reason is required for a historical correction.');
                }
                if (! $this->publicationPending($term, $employee, $blocks, $profile)) {
                    return;
                }
                $version = FacultyScheduleVersion::create(['academic_term_id' => $term->id, 'employee_id' => $employee->id,
                    'effective_from' => $date->toDateString(), 'profile' => $profile, 'reason' => $reason ?: 'Published schedule', 'published_by' => $actor->id]);
                foreach ($blocks as $block) {
                    $values = [...$block, 'academic_term_id' => $term->id, 'schedule_version_id' => $version->id];
                    if ($block['schedule_type'] === 'lunch_break') {
                        unset($values['subject'], $values['section'], $values['class_mode']);
                        $employee->scheduleBreaks()->create($values);
                    } else {
                        $employee->schedules()->create($values);
                    }
                }
            }
            $this->event($term, $actor, $intent === 'publish' ? 'schedule_published' : 'draft_saved', $reason ?: 'Saved faculty schedule', $before, $assignment->fresh()->toArray(), $employee->id);
        });
    }

    public function publishAll(AcademicTerm $term, User $actor): array
    {
        abort_unless($actor->role === 'admin', 403);

        return DB::transaction(function () use ($term, $actor) {
            $this->lock();
            $term = AcademicTerm::lockForUpdate()->findOrFail($term->id);
            if ($term->status === 'closed') {
                $this->invalid('Closed semesters require individual schedule corrections with a reason.');
            }
            $employees = Employee::where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            $assignments = FacultyTermAssignment::where('academic_term_id', $term->id)->get()->keyBy('employee_id');
            $result = ['term_id' => $term->id, 'published' => 0, 'unchanged' => 0, 'no_draft' => 0,
                'ineligible' => $assignments->keys()->diff($employees->modelKeys())->count(), 'failed' => []];
            foreach ($employees as $employee) {
                $assignment = $assignments->get($employee->id);
                if (! $assignment || (empty($assignment->draft_blocks) && ! FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $employee->id)->exists())) {
                    $result['no_draft']++;

                    continue;
                }
                try {
                    $blocks = $this->validateBlocks($assignment->draft_blocks, $term, true);
                    $latest = FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $employee->id)
                        ->orderByDesc('effective_from')->orderByDesc('id')->first();
                    if (! $this->publicationPending($term, $employee, $blocks, $assignment->profile)) {
                        $result['unchanged']++;

                        continue;
                    }
                    $effective = $this->automaticEffectiveDate($term, $latest);
                    $this->save($term, $employee, $blocks, [], 'publish', $effective->toDateString(), 'Published with Publish all schedules', $actor);
                    $result['published']++;
                } catch (ValidationException $exception) {
                    $result['failed'][] = ['employee_id' => $employee->id, 'faculty' => $employee->full_name,
                        'messages' => collect($exception->errors())->flatten()->unique()->values()->all()];
                }
            }

            return $result;
        });
    }

    public function publicationPending(AcademicTerm $term, Employee $employee, array $blocks, ?array $profile = null): bool
    {
        $latest = FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if (! $latest) {
            return ! empty($blocks);
        }
        $profile ??= FacultyTermAssignment::where('academic_term_id', $term->id)->where('employee_id', $employee->id)->value('profile') ?? $this->profile($employee);
        if (! $term->reopened) {
            $profile['faculty_rank_id'] = $employee->faculty_rank_id;
        }
        $published = $employee->schedules()->where('schedule_version_id', $latest->id)->get()
            ->concat($employee->scheduleBreaks()->where('schedule_version_id', $latest->id)->get())->toArray();

        return $this->comparableBlocks($blocks) !== $this->comparableBlocks($published) || $profile != $latest->profile;
    }

    private function automaticEffectiveDate(AcademicTerm $term, ?FacultyScheduleVersion $latest): Carbon
    {
        $date = $term->start_date->copy();
        if ($latest && ! $term->reopened) {
            $tomorrow = Carbon::today('Asia/Manila')->addDay();
            if ($date->lt($tomorrow)) {
                $date = $tomorrow;
            }
            if ($date->lt($latest->effective_from)) {
                $date = $latest->effective_from->copy();
            }
        }

        return $date;
    }

    private function comparableBlocks(array $blocks): array
    {
        $values = array_map(fn ($block) => [
            'schedule_type' => $block['schedule_type'], 'day_of_week' => (int) $block['day_of_week'],
            'start_time' => substr($block['start_time'], 0, 5), 'end_time' => substr($block['end_time'], 0, 5),
            'subject' => $block['schedule_type'] === 'class' ? ($block['subject'] ?? '') : '',
            'section' => $block['schedule_type'] === 'class' ? ($block['section'] ?? '') : '',
            'class_mode' => $block['schedule_type'] === 'class' ? ($block['class_mode'] ?? '') : '',
        ], $blocks);
        usort($values, fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));

        return $values;
    }

    public function copy(AcademicTerm $term, Employee $employee, string $source, User $actor): void
    {
        if ($source === 'legacy') {
            $blocks = $employee->schedules()->whereNull('academic_term_id')->get()->concat($employee->scheduleBreaks()->whereNull('academic_term_id')->get());
        } else {
            $version = FacultyScheduleVersion::where('academic_term_id', (int) $source)->where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->firstOrFail();
            $blocks = $employee->schedules()->where('schedule_version_id', $version->id)->get()->concat($employee->scheduleBreaks()->where('schedule_version_id', $version->id)->get());
        }
        $this->save($term, $employee, $blocks->map(fn ($b) => $b->only(['schedule_type', 'day_of_week', 'start_time', 'end_time', 'subject', 'section', 'class_mode']))->all(), [], 'draft', null, 'Copied schedule as draft; review required', $actor);
    }

    public function profile(Employee $employee): array
    {
        return $employee->only(['first_name', 'middle_name', 'last_name', 'suffix', 'position', 'faculty_rank_id', 'contract_start', 'contract_end', 'department']);
    }

    private function validateBlocks(array $blocks, AcademicTerm $term, bool $publishing): array
    {
        $blocks = array_map(function ($block) {
            $block = array_intersect_key($block, array_flip(['schedule_type', 'day_of_week', 'start_time', 'end_time', 'subject', 'section', 'class_mode']));
            foreach (['start_time', 'end_time'] as $field) {
                $block[$field] = substr($block[$field] ?? '', 0, 5);
            }

            return $block;
        }, $blocks);
        Validator::make(['blocks' => $blocks], ['blocks' => ['array', 'max:100'], 'blocks.*.schedule_type' => ['required', 'in:class,consultation,lunch_break'],
            'blocks.*.day_of_week' => ['required', 'integer', 'between:1,6'], 'blocks.*.start_time' => ['required', 'date_format:H:i'],
            'blocks.*.end_time' => ['required', 'date_format:H:i'], 'blocks.*.subject' => ['nullable', 'string', 'max:255'],
            'blocks.*.section' => ['nullable', 'string', 'max:100'], 'blocks.*.class_mode' => ['nullable', 'in:lecture,laboratory']])->validate();
        foreach ($blocks as $i => $block) {
            if ($block['start_time'] < '07:00' || $block['end_time'] > '19:00' || $block['end_time'] <= $block['start_time']) {
                $this->invalid('Choose valid schedule times from 7:00 AM to 7:00 PM.');
            }
            if ($publishing && $block['schedule_type'] === 'class' && (! in_array($block['subject'] ?? '', ScheduleOptions::subjectsFor($block['section'] ?? '', $term->semester), true)
                || ! in_array($block['section'] ?? '', ScheduleOptions::sections(), true) || empty($block['class_mode'])
                || (($block['class_mode'] ?? '') === 'laboratory' && in_array($block['subject'], ScheduleOptions::LECTURE_ONLY_SUBJECTS, true)))) {
                $this->invalid('Review every copied class: subject, section, and delivery must be valid for this semester.');
            }
            foreach (array_slice($blocks, 0, $i) as $other) {
                if ($block['day_of_week'] == $other['day_of_week'] && $block['start_time'] < $other['end_time'] && $block['end_time'] > $other['start_time']) {
                    $this->invalid('Class, consultation, and lunch blocks cannot overlap.');
                }
            }
        }

        return $blocks;
    }

    private function freeze(AcademicTerm $term): void
    {
        $ids = FacultyTermAssignment::where('academic_term_id', $term->id)->pluck('employee_id')
            ->merge(AttendanceLog::withoutGlobalScopes()->where('academic_term_id', $term->id)->pluck('employee_id'))
            ->merge(EntranceAttendance::withoutGlobalScopes()->where('academic_term_id', $term->id)->pluck('employee_id'))->unique();
        foreach (Employee::withoutGlobalScopes()->whereIn('id', $ids)->get() as $employee) {
            for ($day = $term->start_date->copy(); $day->lte($term->end_date); $day->addDay()) {
                $leave = LeaveService::approvedLeaveFor($employee, $day);
                $event = SchoolCalendarService::eventFor($employee, $day);
                $signatory = DtrSignatory::whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))->orderByDesc('effective_from')->first();
                SemesterDaySnapshot::updateOrCreate(['academic_term_id' => $term->id, 'employee_id' => $employee->id, 'date' => $day->toDateString()],
                    ['payload' => ['profile' => $this->profile(SemesterScheduleService::profile($employee, $day)),
                        'intervals' => DailyScheduleService::snapshot($employee, $day), 'exclusion' => $leave ? 'Approved leave' : $event?->title,
                        'event' => $event ? ['title' => $event->title, 'type' => $event->type, 'label' => strtoupper(str_replace('_', ' ', $event->type))]
                            : ($leave ? ['title' => $leave->leaveType->name, 'type' => 'leave', 'label' => strtoupper($leave->leaveType->code).' - LEAVE'] : null),
                        'signatory' => $signatory ? ['name' => strtoupper($signatory->name), 'designation' => 'In Charge'] : null]]);
            }
        }
    }

    public function event(AcademicTerm $term, User $actor, string $action, string $reason, ?array $before, ?array $after, ?int $employee = null): void
    {
        SemesterEvent::create(['academic_term_id' => $term->id, 'employee_id' => $employee, 'actor_id' => $actor->id,
            'action' => $action, 'reason' => $reason, 'before' => $before, 'after' => $after]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['semester' => $message]);
    }
}

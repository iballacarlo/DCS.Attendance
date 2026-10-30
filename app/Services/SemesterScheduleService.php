<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\FacultyScheduleBreak;
use App\Models\FacultyScheduleVersion;
use App\Models\SemesterDaySnapshot;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SemesterScheduleService
{
    public static function reset(): void
    {
        app()->forgetInstance(SemesterContext::class);
    }

    private static function terms(): Collection
    {
        $context = app(SemesterContext::class);
        if ($context->terms === null) {
            app(SemesterLifecycleService::class)->synchronize();
            $context = app(SemesterContext::class);
            $context->terms = Schema::hasTable('academic_terms') ? AcademicTerm::all() : collect();
        }

        return $context->terms;
    }

    public static function managed(): bool
    {
        $context = app(SemesterContext::class);

        return $context->managed ??= self::terms()->contains(fn ($term) => $term->status !== 'draft')
            || (Schema::hasTable('system_settings') && SystemSetting::where('key', 'semester_management_enabled')->value('value') === '1');
    }

    public static function termFor(Carbon|string $date): ?AcademicTerm
    {
        $day = Carbon::parse($date, 'Asia/Manila')->toDateString();

        return self::terms()->first(fn ($term) => $term->status !== 'draft'
            && $term->start_date->toDateString() <= $day && $term->end_date->toDateString() >= $day);
    }

    public static function frozen(Employee $employee, Carbon|string $date): ?array
    {
        $term = self::termFor($date);
        if (! $term || $term->status !== 'closed' || $term->reopened) {
            return null;
        }
        $context = app(SemesterContext::class);
        $context->days[$employee->id] ??= SemesterDaySnapshot::where('employee_id', $employee->id)->get()->keyBy(fn ($row) => $row->date->toDateString());

        return $context->days[$employee->id]->get(Carbon::parse($date)->toDateString())?->payload;
    }

    public static function version(Employee $employee, Carbon|string $date): ?FacultyScheduleVersion
    {
        $term = self::termFor($date);
        if (! $term) {
            return null;
        }
        $context = app(SemesterContext::class);
        $context->versions[$employee->id] ??= FacultyScheduleVersion::where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
        $day = Carbon::parse($date)->toDateString();

        return $context->versions[$employee->id]->first(fn ($version) => $version->academic_term_id === $term->id && $version->effective_from->toDateString() <= $day);
    }

    public static function schedules(Employee $employee, Carbon $date): Collection
    {
        if (! self::managed()) {
            $employee->loadMissing('schedules');

            return $employee->schedules->whereNull('academic_term_id')->values();
        }
        $version = self::version($employee, $date);
        if (! $version) {
            return collect();
        }
        $context = app(SemesterContext::class);
        $context->schedules[$employee->id] ??= FacultySchedule::withoutGlobalScopes()->where('employee_id', $employee->id)->get();

        return $context->schedules[$employee->id]->where('schedule_version_id', $version->id)->values();
    }

    public static function breaks(Employee $employee, Carbon $date): Collection
    {
        if (! self::managed()) {
            $employee->loadMissing('scheduleBreaks');

            return $employee->scheduleBreaks->whereNull('academic_term_id')->values();
        }
        $version = self::version($employee, $date);
        if (! $version) {
            return collect();
        }
        $context = app(SemesterContext::class);
        $context->breaks[$employee->id] ??= FacultyScheduleBreak::withoutGlobalScopes()->where('employee_id', $employee->id)->get();

        return $context->breaks[$employee->id]->where('schedule_version_id', $version->id)->values();
    }

    public static function profile(Employee $employee, Carbon|string $date): Employee
    {
        $profile = self::frozen($employee, $date)['profile'] ?? (self::managed() ? self::version($employee, $date)?->profile : null);
        if (! $profile) {
            return $employee;
        }
        $copy = clone $employee;
        $copy->forceFill($profile);

        return $copy;
    }

    public static function assertRecordable(Carbon|string $date): void
    {
        $term = self::termFor($date);
        if ($term && $term->status === 'closed') {
            throw ValidationException::withMessages(['semester' => 'This semester is closed. Attendance recording and generation are unavailable.']);
        }
    }

    public static function assertRangeWritable(Carbon|string $start, Carbon|string|null $end = null): void
    {
        if (! Schema::hasTable('academic_terms')) {
            return;
        }
        $start = Carbon::parse($start)->toDateString();
        $end = $end ? Carbon::parse($end)->toDateString() : '9999-12-31';
        if (AcademicTerm::where('status', 'closed')->where('reopened', false)->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists()) {
            throw ValidationException::withMessages(['semester' => 'Reopen the closed semester with a correction reason before changing historical records.']);
        }
    }

    public static function selection(Request $request): string
    {
        $data = $request->validate(['term_id' => ['nullable', Rule::in(array_merge(['legacy'], AcademicTerm::where('status', '!=', 'draft')->pluck('id')->map(fn ($id) => (string) $id)->all()))]]);

        return (string) ($data['term_id'] ?? '');
    }

    public static function matches(?int $termId, string $selection, Carbon|string|null $date = null): bool
    {
        if ($selection === '' || $selection === 'legacy') {
            return $selection === '' || $termId === null;
        }

        // Older attendance predates semester references. Resolve its recorded day
        // against verified semester dates without rewriting logs or snapshots.
        $basis = $termId ?? ($date !== null ? self::termFor($date)?->id : null);

        return $basis === (int) $selection;
    }
}

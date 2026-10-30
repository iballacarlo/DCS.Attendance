<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\FacultySchedule;
use App\Models\FacultyScheduleBreak;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\LeaveRequest;
use App\Models\SemesterEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SemesterHistoryGuard
{
    public static function register(): void
    {
        foreach ([AcademicTerm::class, FacultyScheduleVersion::class] as $contextModel) {
            $contextModel::saved(fn () => SemesterScheduleService::reset());
            $contextModel::deleted(fn () => SemesterScheduleService::reset());
        }

        Employee::deleting(function ($employee) {
            if (Schema::hasTable('faculty_term_assignments') && FacultyTermAssignment::where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['semester' => 'Archive this faculty member to retain their semester history.']);
            }
        });
        foreach ([AttendanceLog::class, EntranceAttendance::class, CalendarEvent::class, DtrSignatory::class, LeaveRequest::class, FacultySchedule::class, FacultyScheduleBreak::class] as $model) {
            $model::saving(fn ($record) => self::check($record));
            $model::deleting(fn ($record) => self::check($record, true));
            $model::created(fn ($record) => self::audit($record, 'record_added'));
            $model::updated(fn ($record) => self::audit($record, 'record_corrected'));
            $model::deleted(fn ($record) => self::audit($record, 'record_deleted'));
        }
    }

    private static function range(Model $record, bool $original = false): array
    {
        $get = fn ($field) => $original ? $record->getRawOriginal($field) : $record->getAttribute($field);
        if ($record instanceof DtrSignatory) {
            return [$get('effective_from'), $get('effective_to')];
        }
        if ($record instanceof CalendarEvent || $record instanceof LeaveRequest) {
            return [$get('start_date'), $get('end_date')];
        }

        return [$get('attendance_date'), $get('attendance_date')];
    }

    private static function check(Model $record, bool $deleting = false): void
    {
        if (! Schema::hasTable('academic_terms')) {
            return;
        }
        if ($record instanceof FacultySchedule || $record instanceof FacultyScheduleBreak) {
            if ($record->exists && $record->academic_term_id) {
                throw ValidationException::withMessages(['semester' => 'Published schedule versions are immutable. Publish a new version instead.']);
            }

            return;
        }
        foreach ([false, true] as $original) {
            [$start, $end] = self::range($record, $original);
            if ($start) {
                SemesterScheduleService::assertRangeWritable($start, $end);
                if (auth()->check() && auth()->user()->role !== 'admin' && AcademicTerm::where('reopened', true)
                    ->whereDate('start_date', '<=', $end ?: '9999-12-31')->whereDate('end_date', '>=', $start)->exists()) {
                    throw ValidationException::withMessages(['semester' => 'Only an administrator can correct a reopened semester.']);
                }
            }
        }
        if (! $deleting && ! $record->exists && ($record instanceof AttendanceLog || $record instanceof EntranceAttendance) && SemesterScheduleService::managed()) {
            $record->academic_term_id = SemesterScheduleService::termFor($record->attendance_date)?->id;
        }
    }

    private static function audit(Model $record, string $action): void
    {
        if (! Schema::hasTable('academic_terms') || ! auth()->check()) {
            return;
        }
        [$start, $end] = self::range($record);
        if (! $start) {
            return;
        }
        [$oldStart, $oldEnd] = self::range($record, true);
        $terms = AcademicTerm::where('reopened', true)->where(function ($q) use ($start, $end, $oldStart, $oldEnd) {
            $q->where(fn ($range) => $range->whereDate('start_date', '<=', $end ?: '9999-12-31')->whereDate('end_date', '>=', $start));
            if ($oldStart) {
                $q->orWhere(fn ($range) => $range->whereDate('start_date', '<=', $oldEnd ?: '9999-12-31')->whereDate('end_date', '>=', $oldStart));
            }
        })->get();
        foreach ($terms as $term) {
            $reason = SemesterEvent::where('academic_term_id', $term->id)->where('action', 'reopen')->orderByDesc('id')->value('reason') ?: 'Historical correction';
            app(SemesterManagementService::class)->event($term, auth()->user(), $action, $reason,
                ['table' => $record->getTable(), 'record' => $record->getRawOriginal()], ['table' => $record->getTable(), 'record' => $record->getAttributes()], $record->employee_id);
        }
    }
}

<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleRecoveryService
{
    public function recover(array $backup, AcademicTerm $term, User $actor): array
    {
        abort_unless($actor->role === 'admin', 403);

        return DB::transaction(function () use ($backup, $term, $actor) {
            $semesters = app(SemesterManagementService::class);
            $semesters->lock();
            $term = AcademicTerm::lockForUpdate()->findOrFail($term->id);
            if ($term->status !== 'active') {
                throw ValidationException::withMessages(['schedule_term_id' => 'Choose the active semester whose dates you have verified for these schedules.']);
            }
            $allowed = array_column($backup['tables']['employees'] ?? [], 'id');
            $sources = collect(array_merge($backup['tables']['faculty_schedules'] ?? [], $backup['tables']['faculty_schedule_breaks'] ?? []))
                ->filter(fn ($row) => empty($row['academic_term_id']) && empty($row['schedule_version_id']) && in_array($row['employee_id'] ?? null, $allowed, true))
                ->groupBy('employee_id');
            $result = ['published' => 0, 'already_assigned' => 0, 'inactive' => 0, 'failed' => [], 'term' => $term->label];
            foreach ($sources as $employeeId => $rows) {
                $employee = Employee::where('status', 'active')->lockForUpdate()->find($employeeId);
                if (! $employee) {
                    $result['inactive']++;

                    continue;
                }
                $assignment = FacultyTermAssignment::where('academic_term_id', $term->id)->where('employee_id', $employeeId)->first();
                if (! empty($assignment?->draft_blocks) || FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $employeeId)->exists()) {
                    $result['already_assigned']++;

                    continue;
                }
                $blocks = $rows->map(fn ($row) => array_intersect_key($row, array_flip(['schedule_type', 'day_of_week', 'start_time', 'end_time', 'subject', 'section', 'class_mode'])))->values()->all();
                try {
                    $semesters->save($term, $employee, $blocks, [], 'publish', $term->start_date->toDateString(),
                        'Recovered saved schedules for administrator-confirmed semester dates', $actor);
                    $result['published']++;
                } catch (ValidationException $exception) {
                    $result['failed'][] = ['employee_id' => $employee->id, 'faculty' => $employee->full_name,
                        'messages' => collect($exception->errors())->flatten()->unique()->values()->all()];
                }
            }

            return $result;
        });
    }
}

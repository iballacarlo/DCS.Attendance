<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SemesterBackupValidator
{
    public static function check(): void
    {
        $terms = DB::table('academic_terms')->orderBy('start_date')->get()->keyBy('id');
        $previousEnd = null;
        $active = 0;
        foreach ($terms as $term) {
            if (! in_array($term->status, ['draft', 'scheduled', 'active', 'ended', 'closed'], true) || $term->end_date < $term->start_date
                || ($previousEnd !== null && $term->start_date <= $previousEnd) || ($term->reopened && $term->status !== 'closed')) {
                self::invalid();
            }
            $active += $term->status === 'active' ? 1 : 0;
            $previousEnd = $term->end_date;
        }
        if ($active > 1) {
            self::invalid();
        }
        $employees = DB::table('employees')->pluck('id')->flip();
        $versions = DB::table('faculty_schedule_versions')->get()->keyBy('id');
        foreach (['faculty_term_assignments', 'faculty_schedule_versions', 'semester_day_snapshots', 'semester_events', 'faculty_schedules', 'faculty_schedule_breaks', 'attendance_logs', 'entrance_attendances'] as $table) {
            foreach (DB::table($table)->whereNotNull('academic_term_id')->get() as $row) {
                $term = $terms->get($row->academic_term_id);
                if (! $term || ($row->employee_id !== null && ! $employees->has($row->employee_id))) {
                    self::invalid();
                }
                $date = $row->attendance_date ?? $row->date ?? $row->effective_from ?? null;
                if ($date !== null && ($date < $term->start_date || $date > $term->end_date)) {
                    self::invalid();
                }
                if (! empty($row->schedule_version_id)) {
                    $version = $versions->get($row->schedule_version_id);
                    if (! $version || $version->academic_term_id !== $term->id || $version->employee_id !== $row->employee_id) {
                        self::invalid();
                    }
                }
            }
        }
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['backup_file' => 'Semester backup references conflict, overlap, or contain missing faculty/version data. No records were restored.']);
    }
}

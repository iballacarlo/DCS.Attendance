<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleSubjectCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_updates_existing_and_archived_schedules_without_changing_class_identity_or_attendance(): void
    {
        $employee = Employee::create(['employee_no' => 'CORRECT', 'first_name' => 'Saved', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
        $mapping = ['DCIT 24A' => 'DCIT 24', 'DCIT 50A' => 'DCIT50', 'ITEC 55A' => 'ITEC 55', 'DCIT 65A' => 'DCIT 65', 'ITEC 80A' => 'ITEC 80'];
        $originals = [];
        foreach ($mapping as $old => $new) {
            $block = $employee->schedules()->create(['schedule_type' => 'class', 'class_mode' => 'laboratory', 'subject' => $old, 'section' => 'BSIT 2A', 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '09:00']);
            $originals[$block->id] = (array) DB::table('faculty_schedules')->find($block->id);
        }
        $log = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-19', 'faculty_schedule_id' => array_key_first($originals), 'subject' => 'DCIT 24A', 'time_in' => '07:00', 'time_out' => '09:00', 'worked_seconds' => 7200, 'total_hours' => 2]);
        $employee->forceFill(['archived_at' => now()])->save();
        $attendanceBefore = (array) DB::table('attendance_logs')->find($log->id);
        $migration = require database_path('migrations/2026_10_25_000002_normalize_existing_schedule_subject_codes.php');
        $migration->up();
        $migration->up();
        foreach ($originals as $id => $original) {
            $this->assertSame([...$original, 'subject' => $mapping[$original['subject']]], (array) DB::table('faculty_schedules')->find($id));
        }
        $this->assertSame($attendanceBefore, (array) DB::table('attendance_logs')->find($log->id));
        $this->assertSame(0, DB::table('faculty_schedules')->whereIn('subject', array_keys($mapping))->count());
    }
}

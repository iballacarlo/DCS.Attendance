<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = [
        'faculty_schedule_id', 'class_mode', 'subject', 'section', 'scheduled_start', 'scheduled_end',
        'worked_seconds', 'legacy_source_id', 'is_legacy',
        'employee_id',
        'attendance_date',
        'time_in',
        'time_out',
        'method_in',
        'method_out',
        'late_minutes',
        'undertime_minutes',
        'total_hours',
        'status',
        'remarks',
    ];

    protected $casts = ['is_legacy' => 'boolean', 'worked_seconds' => 'integer'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

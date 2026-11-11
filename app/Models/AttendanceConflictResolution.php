<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceConflictResolution extends Model
{
    protected $fillable = [
        'room_id', 'attendance_date', 'kept_attendance_log_id', 'voided_attendance_log_id',
        'resolved_by', 'reason', 'kept_snapshot', 'voided_snapshot',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'kept_snapshot' => 'array',
        'voided_snapshot' => 'array',
    ];
}

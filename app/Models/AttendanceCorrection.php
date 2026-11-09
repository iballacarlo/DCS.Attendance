<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $fillable = ['attendance_log_id', 'corrected_by', 'previous_time_in', 'previous_time_out', 'corrected_time_in', 'corrected_time_out', 'reason'];
}

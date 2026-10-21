<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class FacultySchedule extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = ['class_mode', 'subject', 'section', 'employee_id', 'day_of_week', 'schedule_type', 'start_time', 'end_time', 'break_minutes'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

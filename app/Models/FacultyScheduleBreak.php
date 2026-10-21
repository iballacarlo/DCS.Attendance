<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class FacultyScheduleBreak extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = ['employee_id', 'day_of_week', 'schedule_type', 'start_time', 'end_time'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

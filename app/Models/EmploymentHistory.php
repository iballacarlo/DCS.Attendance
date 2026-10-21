<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class EmploymentHistory extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = ['employee_id', 'employer', 'position', 'started_on', 'ended_on'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

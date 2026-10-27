<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class EntranceAttendance extends Model
{
    use HidesArchivedFaculty;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['schedule_snapshot' => 'array'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class HardwareAttendanceEvent extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = [
        'event_id',
        'employee_id',
        'identifier',
        'method',
        'occurred_at',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'response_payload' => 'array',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

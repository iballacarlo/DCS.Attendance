<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class HardwareEnrollment extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = [
        'employee_id',
        'requested_by',
        'method',
        'operation',
        'finger_label',
        'current_identifier',
        'identifier',
        'status',
        'message',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

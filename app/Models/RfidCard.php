<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Model;

class RfidCard extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = ['employee_id', 'rfid_uid', 'status', 'registered_at'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

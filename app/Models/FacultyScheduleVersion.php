<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacultyScheduleVersion extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['profile' => 'array', 'effective_from' => 'date:Y-m-d'];
}

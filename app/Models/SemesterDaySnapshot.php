<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SemesterDaySnapshot extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'date' => 'date:Y-m-d'];
}

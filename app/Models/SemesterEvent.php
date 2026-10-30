<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SemesterEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}

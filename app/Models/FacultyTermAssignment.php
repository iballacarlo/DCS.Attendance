<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacultyTermAssignment extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['profile' => 'array', 'draft_blocks' => 'array'];
}

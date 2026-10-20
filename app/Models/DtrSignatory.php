<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DtrSignatory extends Model
{
    protected $fillable = ['name', 'designation', 'effective_from', 'effective_to', 'created_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }
}

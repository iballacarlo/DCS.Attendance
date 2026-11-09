<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['building_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }
}

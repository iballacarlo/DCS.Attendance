<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDevice extends Model
{
    protected $fillable = ['code', 'name', 'is_active', 'last_seen_at'];

    protected $casts = ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];

    public function assignments()
    {
        return $this->hasMany(AttendanceDeviceRoomAssignment::class);
    }
}

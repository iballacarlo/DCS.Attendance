<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AttendanceDevice extends Model
{
    protected $fillable = ['code', 'name', 'is_active', 'last_seen_at'];

    protected $casts = ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (AttendanceDevice $device) {
            if ($device->isDirty('code')) {
                throw ValidationException::withMessages([
                    'code' => 'The Terminal ID is permanent and cannot be changed.',
                ]);
            }
        });
    }

    public function assignments()
    {
        return $this->hasMany(AttendanceDeviceRoomAssignment::class);
    }
}

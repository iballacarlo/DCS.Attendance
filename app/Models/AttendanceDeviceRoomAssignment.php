<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDeviceRoomAssignment extends Model
{
    protected $fillable = ['attendance_device_id', 'room_id', 'effective_from', 'effective_to', 'assigned_by'];

    protected $casts = ['effective_from' => 'datetime', 'effective_to' => 'datetime'];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}

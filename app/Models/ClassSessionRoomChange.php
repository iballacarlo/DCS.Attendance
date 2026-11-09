<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassSessionRoomChange extends Model
{
    protected $fillable = ['faculty_schedule_id', 'employee_id', 'session_date', 'original_room_id', 'assigned_room_id', 'swap_schedule_id', 'swap_employee_id', 'status', 'requested_by', 'responded_by', 'responded_at'];

    protected $casts = ['session_date' => 'date', 'responded_at' => 'datetime'];

    public function room()
    {
        return $this->belongsTo(Room::class, 'assigned_room_id');
    }

    public function originalRoom()
    {
        return $this->belongsTo(Room::class, 'original_room_id');
    }

    public function schedule()
    {
        return $this->belongsTo(FacultySchedule::class, 'faculty_schedule_id');
    }
}

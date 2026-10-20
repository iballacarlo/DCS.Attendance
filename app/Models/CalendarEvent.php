<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = [
        'title', 'type', 'start_date', 'end_date', 'is_paid', 'scope_type',
        'department', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_paid' => 'boolean',
        ];
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'calendar_event_employee');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

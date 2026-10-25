<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedFaculty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AttendanceLog extends Model
{
    use HidesArchivedFaculty;

    protected $fillable = [
        'faculty_schedule_id', 'schedule_type', 'class_mode', 'subject', 'section', 'scheduled_start', 'scheduled_end',
        'worked_seconds', 'legacy_source_id', 'is_legacy',
        'employee_id',
        'attendance_date',
        'time_in',
        'time_out',
        'method_in',
        'method_out',
        'late_minutes',
        'undertime_minutes',
        'total_hours',
        'status',
        'remarks',
    ];

    protected $casts = ['is_legacy' => 'boolean', 'worked_seconds' => 'integer'];

    protected $appends = ['display_status'];

    public function getDisplayStatusAttribute(): ?string
    {
        if (! $this->time_in || ! $this->time_out) {
            return null;
        }
        $time = fn ($value) => Carbon::parse('2000-01-01 '.$value)->format('H:i:s');
        if ($time($this->time_out) <= $time($this->time_in)) {
            return null;
        }

        return $this->scheduled_start && $this->scheduled_end && ($time($this->time_in) > $time($this->scheduled_start) || $time($this->time_out) < $time($this->scheduled_end)) ? 'late_undertime' : 'present';
    }

    public function scopeAttendanceStatus(Builder $query, string $status): void
    {
        $query->whereNotNull('time_in')->whereNotNull('time_out')->whereRaw('TIME(time_out) > TIME(time_in)');
        if ($status === 'late_undertime') {
            $query->whereNotNull('scheduled_start')->whereNotNull('scheduled_end')
                ->where(fn ($q) => $q->whereRaw('TIME(time_in) > TIME(scheduled_start)')->orWhereRaw('TIME(time_out) < TIME(scheduled_end)'));
        } else {
            $query->where(fn ($q) => $q->whereNull('scheduled_start')->orWhereNull('scheduled_end')
                ->orWhere(fn ($q) => $q->whereRaw('TIME(time_in) <= TIME(scheduled_start)')->whereRaw('TIME(time_out) >= TIME(scheduled_end)')));
        }
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

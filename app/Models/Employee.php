<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_no',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'contact_no',
        'tin_no',
        'gsis_no',
        'pag_ibig_no',
        'philhealth_no',
        'highest_educational_attainment',
        'years_of_service',
        'service_start_date',
        'position',
        'department',
        'employment_type',
        'faculty_rank_id',
        'rate_type',
        'rate_amount',
        'contract_start',
        'contract_end',
        'status',
    ];

    public function rfidCards()
    {
        return $this->hasMany(RfidCard::class);
    }

    public function fingerprintTemplates()
    {
        return $this->hasMany(FingerprintTemplate::class);
    }

    public function hardwareEnrollments()
    {
        return $this->hasMany(HardwareEnrollment::class);
    }

    public function employmentHistories()
    {
        return $this->hasMany(EmploymentHistory::class)->orderByDesc('started_on');
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function payrollRecords()
    {
        return $this->hasMany(PayrollRecord::class);
    }

    public function facultyRank()
    {
        return $this->belongsTo(FacultyRank::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function schedules()
    {
        return $this->hasMany(FacultySchedule::class);
    }

    public function scheduleBreaks()
    {
        return $this->hasMany(FacultyScheduleBreak::class);
    }

    public function calendarEvents()
    {
        return $this->belongsToMany(CalendarEvent::class, 'calendar_event_employee');
    }

    public function getFullNameAttribute(): string
    {
        $middleInitial = $this->middle_name && preg_match('/^\X/u', trim($this->middle_name), $match) ? $match[0].'.' : '';

        return $this->last_name.', '.implode(' ', array_filter([$this->first_name, $middleInitial, trim((string) $this->suffix)]));
    }
}

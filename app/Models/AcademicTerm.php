<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicTerm extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'reopened' => false];

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'reopened' => 'boolean'];

    protected $appends = ['label'];

    public function scopeInAcademicOrder($query)
    {
        return $query->orderByDesc('school_year')
            ->orderByRaw("CASE semester WHEN 'First Semester' THEN 1 WHEN 'Second Semester' THEN 2 WHEN 'Midyear Term' THEN 3 ELSE 4 END")
            ->orderBy('start_date')->orderBy('id');
    }

    public function getLabelAttribute(): string
    {
        return $this->semester.' · '.$this->school_year;
    }
}

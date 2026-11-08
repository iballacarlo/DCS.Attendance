<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    public const COURSE_SCOPES = ['BSIT', 'BSCS', 'BOTH'];

    public const GENERAL_CODES = ['NSTP 1', 'NSTP 2', 'CVSU 101'];

    protected $guarded = ['id'];

    protected $casts = [
        'year_level' => 'integer',
        'lecture_only' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function legacyCourseScope(string $code): string
    {
        return in_array(strtoupper(trim($code)), self::GENERAL_CODES, true) ? 'BOTH' : 'BSIT';
    }
}

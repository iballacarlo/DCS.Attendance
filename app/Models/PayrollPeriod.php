<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $fillable = ['period_name', 'start_date', 'end_date', 'pay_date', 'status'];

    protected static function booted(): void
    {
        static::addGlobalScope('visible_faculty', fn ($query) => $query->where(fn ($visible) => $visible
            ->whereDoesntHave('records', fn ($all) => $all->withoutGlobalScope('visible_faculty'))
            ->orWhereHas('records')));
    }

    public function records()
    {
        return $this->hasMany(PayrollRecord::class);
    }
}

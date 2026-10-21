<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HidesArchivedFaculty
{
    protected static function bootHidesArchivedFaculty(): void
    {
        static::addGlobalScope('visible_faculty', function (Builder $query) {
            $query->where(function (Builder $visible) use ($query) {
                // Unlinked administrator/HR accounts remain visible.
                $visible->whereNull($query->getModel()->qualifyColumn('employee_id'))->orWhereHas('employee');
            });
        });
    }
}

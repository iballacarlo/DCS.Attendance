<?php

namespace App\Services;

use Illuminate\Support\Collection;

// Request-local data only; changes and restores invalidate the context.
class SemesterContext
{
    public ?Collection $terms = null;

    public ?bool $managed = null;

    public array $versions = [];

    public array $schedules = [];

    public array $breaks = [];

    public array $days = [];
}

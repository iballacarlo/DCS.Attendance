<?php

namespace App\Services;

use App\Models\AcademicTerm;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CutoffService
{
    public static function range(?string $value = null, ?string $termSelection = null): array
    {
        $start = $value ? Carbon::parse($value, 'Asia/Manila')->startOfDay() : Carbon::today('Asia/Manila');
        if ($start->year < 2000 || $start->year > 2100) {
            throw ValidationException::withMessages(['cutoff_start' => 'Enter a year from 2000 to 2100.']);
        }
        if (! $value) {
            $start->day($start->day <= 15 ? 1 : 16);
        }
        if (! in_array($start->day, [1, 16], true)) {
            throw ValidationException::withMessages(['cutoff_start' => 'Cutoff must start on day 1 or 16.']);
        }

        $end = $start->day === 1 ? $start->copy()->day(15) : $start->copy()->endOfMonth()->startOfDay();
        $term = ctype_digit((string) $termSelection) ? AcademicTerm::where('status', '!=', 'draft')->find((int) $termSelection) : null;
        if ($term && ($end->lt($term->start_date) || $start->gt($term->end_date))) {
            $anchor = $end->lt($term->start_date) ? $term->start_date->copy() : $term->end_date->copy();

            return self::range($anchor->day($anchor->day <= 15 ? 1 : 16)->toDateString());
        }

        return [$start, $end];
    }

    public static function options(string $earliest, Carbon $selected): array
    {
        $first = Carbon::parse($earliest, 'Asia/Manila')->startOfMonth()->min($selected->copy()->startOfMonth());
        $last = Carbon::today('Asia/Manila')->startOfMonth()->max($selected->copy()->startOfMonth());
        $options = [];
        for ($month = $last->copy(); $month->gte($first); $month->subMonth()) {
            foreach ([16, 1] as $day) {
                [$start, $end] = self::range($month->copy()->day($day)->toDateString());
                $options[] = ['value' => $start->toDateString(), 'label' => $start->format('m/d/Y').' - '.$end->format('m/d/Y')];
            }
        }

        return $options;
    }
}

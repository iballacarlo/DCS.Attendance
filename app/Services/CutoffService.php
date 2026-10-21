<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CutoffService
{
    public static function range(?string $value = null): array
    {
        $start = $value ? Carbon::parse($value, 'Asia/Manila')->startOfDay() : Carbon::today('Asia/Manila');
        if (! $value) {
            $start->day($start->day <= 15 ? 1 : 16);
        }
        if (! in_array($start->day, [1, 16], true)) {
            throw ValidationException::withMessages(['cutoff_start' => 'Cutoff must start on day 1 or 16.']);
        }

        return [$start, $start->day === 1 ? $start->copy()->day(15) : $start->copy()->endOfMonth()->startOfDay()];
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

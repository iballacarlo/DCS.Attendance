<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Carbon;

class DailyScheduleService
{
    // Seconds since midnight keep snapshots independent of later schedule edits.
    public static function snapshot(Employee $employee, Carbon $date): array
    {
        $frozen = SemesterScheduleService::frozen($employee, $date);
        if ($frozen !== null) {
            return $frozen['intervals'];
        }
        $seconds = fn ($time) => Carbon::parse('2000-01-01 '.$time, 'Asia/Manila')->secondsSinceMidnight();
        $intervals = SemesterScheduleService::schedules($employee, $date)->whereIn('schedule_type', ['class', 'consultation'])
            ->where('day_of_week', $date->dayOfWeek)->sortBy('start_time')
            ->map(fn ($s) => [(int) $seconds($s->start_time), (int) $seconds($s->end_time)])->all();
        $merged = [];
        foreach ($intervals as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($to, $merged[$last][1]);
            } else {
                $merged[] = [$from, $to];
            }
        }
        foreach (SemesterScheduleService::breaks($employee, $date)->where('day_of_week', $date->dayOfWeek)->where('schedule_type', 'lunch_break') as $break) {
            $remaining = [];
            $a = $seconds($break->start_time);
            $b = $seconds($break->end_time);
            foreach ($merged as [$from, $to]) {
                if ($b <= $from || $a >= $to) {
                    $remaining[] = [$from, $to];
                } else {
                    if ($from < $a) {
                        $remaining[] = [$from, (int) $a];
                    }
                    if ($to > $b) {
                        $remaining[] = [(int) $b, $to];
                    }
                }
            }
            $merged = $remaining;
        }

        return $merged;
    }
}

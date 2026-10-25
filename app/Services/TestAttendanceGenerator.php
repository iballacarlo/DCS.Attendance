<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TestAttendanceGenerator
{
    public function pairs(Collection $schedules, Carbon $date, Carbon $now, Collection $existing, bool $overwrite, bool $shortfall): array
    {
        $schedules = $schedules->sortBy('start_time')->values();
        $candidates = $schedules->filter(fn ($s) => Carbon::parse($date->toDateString().' '.$s->end_time, 'Asia/Manila')->lte($now)
            && ($overwrite || ! $existing->has($s->id)));
        $penaltyCandidates = $candidates->filter(fn ($s) => $this->at($date, $s->end_time)->diffInMinutes($this->at($date, $s->start_time), true) >= 6);
        $penaltyId = $shortfall && $penaltyCandidates->isNotEmpty() ? $penaltyCandidates->random()->id : null;
        $retained = $overwrite ? $existing->reject(fn ($log) => $candidates->contains('id', $log->faculty_schedule_id)) : $existing;
        $pairs = [];
        $previousOut = null;
        foreach ($schedules as $index => $schedule) {
            if (! $candidates->contains('id', $schedule->id)) {
                continue;
            }
            $start = $this->at($date, $schedule->start_time);
            $end = $this->at($date, $schedule->end_time);
            // Share gaps between neighboring sessions; adjacent classes tap exactly at their boundary.
            $earlyLimit = $index > 0 ? min(20, intdiv(max(0, $start->timestamp - $this->at($date, $schedules[$index - 1]->end_time)->timestamp), 120)) : min(20, (int) $start->diffInMinutes($date->copy()->startOfDay(), true));
            $lateLimit = $index + 1 < $schedules->count() ? min(20, intdiv(max(0, $this->at($date, $schedules[$index + 1]->start_time)->timestamp - $end->timestamp), 120)) : min(20, (int) $end->diffInMinutes($date->copy()->endOfDay(), true));
            $in = $start->copy()->subMinutes($this->pick(0, 2) ? $this->pick(0, $earlyLimit) : 0);
            $out = $end->copy()->addMinutes($this->pick(0, 2) ? $this->pick(0, $lateLimit) : 0);
            if ($schedule->id === $penaltyId) {
                $penalty = $this->pick(1, min(15, max(1, (int) ($start->diffInMinutes($end) / 3))));
                if ($this->pick(0, 1)) {
                    $in = $start->copy()->addMinutes($penalty);
                } else {
                    $out = $end->copy()->subMinutes($penalty);
                }
            }
            if ($previousOut && $in->lt($previousOut)) {
                $in = $previousOut->copy();
            }
            foreach ($retained as $log) {
                if ($log->scheduled_start < $schedule->start_time && $log->time_out) {
                    $in = $in->max($this->at($date, $log->time_out));
                }
                if ($log->scheduled_start > $schedule->start_time && $log->time_in) {
                    $out = $out->min($this->at($date, $log->time_in));
                }
            }
            $out = $out->min($now);
            if ($out->lte($in)) {
                continue;
            }
            $pairs[$schedule->id] = ['in' => $in, 'out' => $out];
            $previousOut = $out;
        }

        return $pairs;
    }

    protected function pick(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    private function at(Carbon $date, string $time): Carbon
    {
        return Carbon::parse($date->toDateString().' '.$time, 'Asia/Manila');
    }
}

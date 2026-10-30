<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\EntranceAttendance;
use App\Models\LeaveRequest;
use App\Models\RfidCard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceGenerationService
{
    public function preview(array $filters, string $seed): array
    {
        abort_unless(config('attendance_generator.enabled'), 404);

        return $this->run($filters, $seed, null);
    }

    public function generate(array $filters, string $seed, User $actor, ?array $guards = null): array
    {
        abort_unless(config('attendance_generator.enabled'), 404);
        abort_unless($actor->role === 'generator' && ! $actor->must_change_password, 403);

        return DB::transaction(function () use ($filters, $seed, $actor, $guards) {
            app(SemesterManagementService::class)->lock();

            return $this->run($filters, $seed, $actor, $guards);
        }, 3);
    }

    public static function periodRange(array $filters): array
    {
        if (($filters['period'] ?? 'cutoff') !== 'semester') {
            return CutoffService::range($filters['cutoff_start'] ?? null);
        }
        app(SemesterLifecycleService::class)->synchronize();
        $term = AcademicTerm::find((int) ($filters['term_id'] ?? 0));
        if (! $term || ! in_array($term->status, ['active', 'ended'], true)
            || $term->start_date->gt(Carbon::today('Asia/Manila')) || $term->start_date->diffInDays($term->end_date) > 366) {
            throw ValidationException::withMessages(['term_id' => 'Choose an active or ended semester that is not closed.']);
        }

        if ((isset($filters['semester_start_date']) && $filters['semester_start_date'] !== $term->start_date->toDateString())
            || (isset($filters['semester_end_date']) && $filters['semester_end_date'] !== $term->end_date->toDateString())) {
            throw ValidationException::withMessages(['term_id' => 'Semester dates changed. Preview this semester again.']);
        }

        return [$term->start_date->copy(), $term->end_date->copy()->endOfDay()];
    }

    private function run(array $filters, string $seed, ?User $actor, ?array $guards = null): array
    {
        [$start, $end] = self::periodRange($filters);
        $now = Carbon::now('Asia/Manila');
        $employees = Employee::where('status', 'active')->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        $calendar = CalendarEvent::with('employees:id')->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get();
        $result = ['absent' => 0, 'eligible' => 0, 'created' => 0, 'updated' => 0, 'to_create' => 0, 'to_overwrite' => 0, 'guards' => [], 'skipped' => 0, 'reasons' => [], 'samples' => [], 'faculty_count' => $employees->count(),
            'mode' => $filters['mode'], 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];
        foreach ($employees as $faculty) {
            $work = function () use ($faculty, $filters, $start, $end, $now, $calendar, $seed, $actor, $guards) {
                $employee = Employee::whereKey($faculty->id)->where('status', 'active')->when($actor, fn ($q) => $q->lockForUpdate())->first();
                $part = ['absent' => 0, 'eligible' => 0, 'created' => 0, 'updated' => 0, 'to_create' => 0, 'to_overwrite' => 0, 'guards' => [], 'skipped' => 0, 'reasons' => [], 'samples' => []];
                if (! $employee) {
                    $this->skip($part, 'Inactive or archived faculty');

                    return $part;
                }
                $employee->load(['schedules', 'scheduleBreaks']);
                $card = RfidCard::where('employee_id', $employee->id)->where('status', 'active')->orderBy('id')
                    ->when($actor, fn ($q) => $q->lockForUpdate())->first();
                if (! $card || ! trim($card->rfid_uid)) {
                    $this->skip($part, 'No active registered RFID');

                    return $part;
                }
                $leaves = LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
                    ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get();
                for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                    if ($day->toDateString() > $now->toDateString()) {
                        $this->skip($part, 'Future date');

                        continue;
                    }
                    if (SemesterScheduleService::termFor($day)?->status === 'closed') {
                        $this->skip($part, 'Closed semester');

                        continue;
                    }
                    if ($this->excluded($employee, $day, $calendar, $leaves)) {
                        $this->skip($part, 'Holiday, suspension or approved leave');

                        continue;
                    }
                    if ($filters['mode'] === 'school') {
                        $this->schoolDay($employee, $card, $day, $now, $seed, $actor, $part, (bool) ($filters['overwrite'] ?? false), $guards);
                    } else {
                        $this->classDay($employee, $day, $now, $seed, $actor, $part, (bool) ($filters['overwrite'] ?? false), $guards);
                    }
                }

                return $part;
            };
            $part = $actor ? DB::transaction($work, 3) : $work();
            foreach (['absent', 'eligible', 'created', 'updated', 'to_create', 'to_overwrite', 'skipped'] as $field) {
                $result[$field] += $part[$field];
            }
            foreach ($part['reasons'] as $reason => $count) {
                $result['reasons'][$reason] = ($result['reasons'][$reason] ?? 0) + $count;
            }
            $result['guards'] = [...$result['guards'], ...$part['guards']];
            $result['samples'] = array_slice([...$result['samples'], ...$part['samples']], 0, 10);
        }

        return $result;
    }

    private function schoolDay(Employee $employee, RfidCard $card, Carbon $day, Carbon $now, string $seed, ?User $actor, array &$result, bool $overwrite, ?array $guards): void
    {
        $record = EntranceAttendance::where('employee_id', $employee->id)->where('attendance_date', $day->toDateString())
            ->when($actor, fn ($q) => $q->lockForUpdate())->first();
        if ($record && ! $overwrite) {
            $this->skip($result, 'Existing record kept');

            return;
        }
        $intervals = $record ? $record->schedule_snapshot : DailyScheduleService::snapshot($employee, $day);
        if (! $intervals) {
            $this->skip($result, 'No required schedule');

            return;
        }
        $start = $day->copy()->addSeconds($intervals[0][0]);
        $end = $day->copy()->addSeconds($intervals[count($intervals) - 1][1]);
        if ($end->gt($now)) {
            $this->skip($result, 'Scheduled day has not ended');

            return;
        }
        $target = 'school:'.$employee->id.':'.$day->toDateString();
        if ($actor && $overwrite && $guards !== null && ! $this->unchanged($record, $target, $guards, $result)) {
            return;
        }
        if ($this->absent($seed, $target, $record, $result)) {
            return;
        }
        $pair = $this->pair($start, $end, $now, 900, 900, $seed, 'school:'.$employee->id.':'.$day->toDateString());
        if (! $pair) {
            $this->skip($result, 'Invalid or too short schedule');

            return;
        }
        $previous = $this->previous($record);
        $fingerprint = $this->fingerprint($record);
        if ($actor) {
            if ($record) {
                $record->update(['time_in' => $pair['in']->format('H:i:s'), 'time_out' => $pair['out']->format('H:i:s'),
                    'recorded_by' => $actor->id, 'out_recorded_by' => $actor->id]);
                $result['updated']++;
            } else {
                $service = app(EntranceAttendanceService::class);
                $service->record($card->rfid_uid, 'IN', $pair['in'], $actor->id);
                $service->record($card->rfid_uid, 'OUT', $pair['out'], $actor->id);
                $result['created']++;
            }
        }
        $this->include($result, $employee, $day, $pair, 'School attendance', $target, $fingerprint, $previous);
    }

    private function classDay(Employee $employee, Carbon $day, Carbon $now, string $seed, ?User $actor, array &$result, bool $overwrite, ?array $guards): void
    {
        $existing = AttendanceLog::where('employee_id', $employee->id)->where('attendance_date', $day->toDateString())
            ->where('is_legacy', false)->where('schedule_type', 'class')->get();
        $schedules = SemesterScheduleService::schedules($employee, $day)->where('schedule_type', 'class')->where('day_of_week', $day->dayOfWeek);
        $blocks = collect();
        foreach ($schedules as $schedule) {
            $record = $existing->firstWhere('faculty_schedule_id', $schedule->id);
            $blocks->push(['key' => 'schedule:'.$schedule->id, 'record' => $record, 'schedule' => $schedule,
                'start' => $record && $overwrite ? $record->scheduled_start : $schedule->start_time,
                'end' => $record && $overwrite ? $record->scheduled_end : $schedule->end_time,
                'subject' => $record && $overwrite ? $record->subject : $schedule->subject]);
        }
        if ($overwrite) {
            foreach ($existing as $record) {
                if ($blocks->contains(fn ($block) => $block['record']?->id === $record->id)) {
                    continue;
                }
                $blocks->push(['key' => $record->faculty_schedule_id ? 'schedule:'.$record->faculty_schedule_id : 'record:'.$record->id,
                    'record' => $record, 'schedule' => null, 'start' => $record->scheduled_start,
                    'end' => $record->scheduled_end, 'subject' => $record->subject]);
            }
        }
        if ($blocks->isEmpty()) {
            $this->skip($result, 'No class schedule');

            return;
        }
        $seconds = fn ($time) => (int) Carbon::parse('2000-01-01 '.$time, 'Asia/Manila')->secondsSinceMidnight();
        $blocks = $blocks->filter(function ($block) use (&$result, $seconds) {
            if (! $block['start'] || ! $block['end'] || $seconds($block['end']) - $seconds($block['start']) < 120) {
                $this->skip($result, 'Invalid or too short schedule snapshot');

                return false;
            }

            return true;
        })->sortBy(fn ($block) => $seconds($block['start']))->values();
        $candidates = collect();
        foreach ($blocks as $index => $block) {
            if (Carbon::parse($day->toDateString().' '.$block['end'], 'Asia/Manila')->gt($now)) {
                $this->skip($result, 'Class has not ended');

                continue;
            }
            if ($block['record'] && ! $overwrite) {
                $this->skip($result, 'Existing record kept');

                continue;
            }
            $overlap = $blocks->contains(fn ($other) => $other['key'] !== $block['key']
                && $seconds($other['start']) < $seconds($block['end']) && $seconds($other['end']) > $seconds($block['start']));
            $conflict = $existing->contains(fn ($log) => $log->id !== $block['record']?->id
                && (! $log->scheduled_start || ! $log->scheduled_end
                    || ($seconds($log->scheduled_start) < $seconds($block['end']) && $seconds($log->scheduled_end) > $seconds($block['start']))));
            if ($overlap || $conflict) {
                $this->skip($result, 'Schedule or saved snapshot conflict');

                continue;
            }
            $target = 'class:'.$employee->id.':'.$day->toDateString().':'.$block['key'];
            if ($actor && $overwrite && $guards !== null && ! $this->unchanged($block['record'], $target, $guards, $result)) {
                continue;
            }
            if ($this->absent($seed, $target, $block['record'], $result)) {
                continue;
            }
            $candidates->push([...$block, 'index' => $index, 'target' => $target]);
        }
        $plans = collect();
        while ($candidates->isNotEmpty()) {
            $retained = $existing->reject(fn ($log) => $overwrite && $candidates->contains(fn ($block) => $block['record']?->id === $log->id));
            $plans = collect();
            $rejected = [];
            foreach ($candidates as $block) {
                $start = Carbon::parse($day->toDateString().' '.$block['start'], 'Asia/Manila');
                $end = Carbon::parse($day->toDateString().' '.$block['end'], 'Asia/Manila');
                $index = $block['index'];
                $before = $index > 0 ? min(900, max(0, (int) ($start->timestamp - Carbon::parse($day->toDateString().' '.$blocks[$index - 1]['end'], 'Asia/Manila')->timestamp) / 2)) : 900;
                $after = $index + 1 < $blocks->count() ? min(900, max(0, (int) (Carbon::parse($day->toDateString().' '.$blocks[$index + 1]['start'], 'Asia/Manila')->timestamp - $end->timestamp) / 2)) : 900;
                $pair = $this->pair($start, $end, $now, (int) $before, (int) $after, $seed, $block['target']);
                if ($pair) {
                    foreach ($retained as $log) {
                        if ($seconds($log->scheduled_end) <= $seconds($block['start']) && $log->time_out) {
                            $pair['in'] = $pair['in']->max(Carbon::parse($day->toDateString().' '.$log->time_out, 'Asia/Manila'));
                        }
                        if ($seconds($log->scheduled_start) >= $seconds($block['end']) && $log->time_in) {
                            $pair['out'] = $pair['out']->min(Carbon::parse($day->toDateString().' '.$log->time_in, 'Asia/Manila'));
                        }
                    }
                }
                if (! $pair || $pair['out']->timestamp - $pair['in']->timestamp < 120
                    || abs($pair['in']->timestamp - $start->timestamp) > 900 || abs($pair['out']->timestamp - $end->timestamp) > 900) {
                    $this->skip($result, 'Invalid or conflicting IN/OUT pair');
                    $rejected[] = $block['target'];

                    continue;
                }
                $plans->push([...$block, 'pair' => $pair]);
            }
            if (! $rejected) {
                break;
            }
            $candidates = $candidates->reject(fn ($block) => in_array($block['target'], $rejected, true))->values();
        }
        foreach ($plans as $block) {
            $pair = $block['pair'];
            $record = $block['record'];
            $previous = $this->previous($record);
            $fingerprint = $this->fingerprint($record);
            if ($actor) {
                $attributes = ['time_in' => $pair['in']->format('H:i:s'), 'time_out' => $pair['out']->format('H:i:s'), 'method_in' => 'rfid', 'method_out' => 'rfid'];
                if ($record) {
                    $record->update($attributes);
                    $result['updated']++;
                    $log = $record;
                } else {
                    $log = AttendanceLog::create(['employee_id' => $employee->id, 'attendance_date' => $day->toDateString(), ...ClassAttendanceService::snapshot($block['schedule']), ...$attributes]);
                    $result['created']++;
                }
                AttendanceCalculator::recalculate($log);
            }
            $this->include($result, $employee, $day, $pair, $block['subject'] ?: 'Class session', $block['target'], $fingerprint, $previous);
        }
    }

    private function absent(string $seed, string $target, AttendanceLog|EntranceAttendance|null $record, array &$result): bool
    {
        // One shared draw: 90% full, 3% late, 3% undertime, 2% both, 2% absent.
        if ($this->pick($seed, $target.':scenario', 0, 99) < 98) {
            return false;
        }
        $result['guards'][$target] = $this->fingerprint($record);
        $this->skip($result, $record ? 'Random absence selected; existing record kept' : 'Random absence (no IN/OUT generated)');
        if (! $record) {
            $result['absent']++;
        }

        return true;
    }

    private function pair(Carbon $start, Carbon $end, Carbon $now, int $before, int $after, string $seed, string $key): ?array
    {
        $duration = $end->timestamp - $start->timestamp;
        if ($duration < 120 || $end->toDateString() !== $start->toDateString()) {
            return null;
        }
        $scenario = $this->pick($seed, $key.':scenario', 0, 99);
        $maxPenalty = min(15, intdiv($duration - 120, 120));
        $in = $start->copy()->subSeconds($this->pick($seed, $key.':early', 0, min($before, (int) $start->secondsSinceMidnight())));
        $out = $end->copy()->addSeconds($this->pick($seed, $key.':extra', 0, min($after, (int) ($end->copy()->endOfDay()->timestamp - $end->timestamp), max(0, $now->timestamp - $end->timestamp))));
        if ($maxPenalty > 0 && (($scenario >= 90 && $scenario < 93) || ($scenario >= 96 && $scenario < 98))) {
            $in = $start->copy()->addMinutes($this->pick($seed, $key.':late', 1, $maxPenalty));
        }
        if ($maxPenalty > 0 && $scenario >= 93 && $scenario < 98) {
            $out = $end->copy()->subMinutes($this->pick($seed, $key.':undertime', 1, $maxPenalty));
        }

        return $out->gt($in) && $out->lte($now) ? ['in' => $in, 'out' => $out] : null;
    }

    protected function pick(string $seed, string $key, int $min, int $max): int
    {
        return $min + hexdec(substr(hash_hmac('sha256', $key, $seed), 0, 7)) % ($max - $min + 1);
    }

    private function excluded(Employee $employee, Carbon $date, Collection $calendar, Collection $leaves): bool
    {
        $day = $date->toDateString();
        if ($leaves->contains(fn ($leave) => $leave->start_date->toDateString() <= $day && $leave->end_date->toDateString() >= $day)) {
            return true;
        }
        if (RegularHolidayService::eventFor($date)) {
            return true;
        }

        return $calendar->contains(fn ($event) => $event->start_date->toDateString() <= $day && $event->end_date->toDateString() >= $day
            && in_array($event->type, ['regular_holiday', 'special_holiday', 'suspension', 'official_school_activity', 'asynchronous'], true)
            && ($event->scope_type === 'campus' || ($event->scope_type === 'department' && $event->department === $employee->department)
                || ($event->scope_type === 'faculty' && $event->employees->contains('id', $employee->id))));
    }

    private function skip(array &$result, string $reason): void
    {
        $result['skipped']++;
        $result['reasons'][$reason] = ($result['reasons'][$reason] ?? 0) + 1;
    }

    private function fingerprint(AttendanceLog|EntranceAttendance|null $record): ?string
    {
        if (! $record) {
            return null;
        }
        $attributes = $record->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    private function previous(AttendanceLog|EntranceAttendance|null $record): ?array
    {
        return $record ? ['time_in' => $record->time_in, 'time_out' => $record->time_out] : null;
    }

    private function unchanged(AttendanceLog|EntranceAttendance|null $record, string $target, ?array $guards, array &$result): bool
    {
        if ($guards === null || ! array_key_exists($target, $guards) || $guards[$target] !== $this->fingerprint($record)) {
            $this->skip($result, 'Changed since preview; preview again');

            return false;
        }

        return true;
    }

    private function include(array &$result, Employee $employee, Carbon $day, array $pair, string $subject, string $target, ?string $fingerprint, ?array $previous): void
    {
        $result['eligible']++;
        $result[$previous ? 'to_overwrite' : 'to_create']++;
        $result['guards'][$target] = $fingerprint;
        if (count($result['samples']) < 10) {
            $result['samples'][] = ['faculty' => $employee->full_name, 'date' => $day->toDateString(), 'subject' => $subject,
                'time_in' => $pair['in']->format('H:i:s'), 'time_out' => $pair['out']->format('H:i:s'),
                'action' => $previous ? 'overwrite' : 'create', 'previous_in' => $previous['time_in'] ?? null, 'previous_out' => $previous['time_out'] ?? null];
        }
    }
}

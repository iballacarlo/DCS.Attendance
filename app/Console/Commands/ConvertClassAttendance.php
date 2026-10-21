<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollRecord;
use App\Services\AttendanceCalculator;
use App\Services\ClassAttendanceService;
use App\Services\DtrService;
use App\Services\PayrollComputationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConvertClassAttendance extends Command
{
    protected $signature = 'attendance:convert-classes {--preview : Report only; do not write records}';

    protected $description = 'Reconstruct legacy daily attendance into class sessions and recompute draft payroll, preserving original records';

    public function handle(PayrollComputationService $calculator): int
    {
        $preview = (bool) $this->option('preview');
        $report = ['preview' => $preview, 'created' => 0, 'already_converted' => 0, 'manual_review' => [], 'payroll' => [],
            'warning' => 'No historical schedule versions exist in the legacy database. Reconstruction uses the class blocks currently assigned. Original daily logs remain unchanged. Review this report before conversion and before changing existing plotting.'];
        $pendingSeconds = [];
        $work = function () use (&$report, &$pendingSeconds, $preview, $calculator) {
            foreach (AttendanceLog::where('is_legacy', true)->orderBy('id')->get() as $source) {
                if (AttendanceLog::where('legacy_source_id', $source->id)->exists()) {
                    $report['already_converted']++;

                    continue;
                }
                $employee = Employee::find($source->employee_id);
                if (! $preview) {
                    Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                }
                $date = Carbon::parse($source->attendance_date, 'Asia/Manila');
                $schedules = $employee->schedules()->where('schedule_type', 'class')->where('day_of_week', $date->dayOfWeek)->orderBy('start_time')->get();
                if ($schedules->isEmpty()) {
                    $report['manual_review'][] = ['source_id' => $source->id, 'date' => $date->format('m/d/Y'), 'reason' => 'No matching class schedule; no hours reconstructed.'];

                    continue;
                }
                if ($schedules->contains(fn ($schedule) => $schedule->break_minutes > 0)
                    || $employee->scheduleBreaks()->where('day_of_week', $date->dayOfWeek)->get()->contains(fn ($break) => $schedules->contains(fn ($schedule) => $break->start_time < $schedule->end_time && $break->end_time > $schedule->start_time))) {
                    $report['manual_review'][] = ['source_id' => $source->id, 'reason' => 'Legacy class includes a break. Split the plotting into class-only blocks before reconstruction.'];

                    continue;
                }
                if ($source->time_in && $source->time_out && $source->time_out <= $source->time_in) {
                    $report['manual_review'][] = ['source_id' => $source->id, 'reason' => 'Invalid legacy tap order.'];

                    continue;
                }
                $overlap = false;
                $previousEnd = null;
                foreach ($schedules as $schedule) {
                    if ($previousEnd && $schedule->start_time < $previousEnd) {
                        $overlap = true;
                    }
                    $previousEnd = $schedule->end_time;
                }
                if ($overlap) {
                    $report['manual_review'][] = ['source_id' => $source->id, 'reason' => 'Overlapping legacy class blocks; resolve plotting before conversion.'];

                    continue;
                }
                foreach ($schedules as $schedule) {
                    $key = ['employee_id' => $employee->id, 'attendance_date' => $source->attendance_date, 'faculty_schedule_id' => $schedule->id];
                    if (AttendanceLog::where($key)->where('is_legacy', false)->exists()) {
                        $report['already_converted']++;

                        continue;
                    }
                    $start = Carbon::parse($source->attendance_date.' '.$schedule->start_time, 'Asia/Manila');
                    $end = Carbon::parse($source->attendance_date.' '.$schedule->end_time, 'Asia/Manila');
                    $in = $source->time_in ? Carbon::parse($source->attendance_date.' '.$source->time_in, 'Asia/Manila') : null;
                    $out = $source->time_out ? Carbon::parse($source->attendance_date.' '.$source->time_out, 'Asia/Manila') : null;
                    if ($in && $out && ($in->gte($end) || $out->lte($start))) {
                        continue;
                    }
                    $candidate = new AttendanceLog([...$key, ...ClassAttendanceService::snapshot($schedule),
                        'time_in' => $in && ($out || ($in->gte($start) && $in->lt($end))) ? $in->max($start)->format('H:i:s') : null,
                        'time_out' => $out && ($in || ($out->gt($start) && $out->lte($end))) ? $out->min($end)->format('H:i:s') : null,
                        'method_in' => $source->time_in ? $source->method_in : null, 'method_out' => $source->time_out ? $source->method_out : null,
                        'legacy_source_id' => $source->id, 'remarks' => 'Reconstructed from legacy daily attendance #'.$source->id.'.']);
                    $pendingSeconds[$employee->id][$source->attendance_date] = ($pendingSeconds[$employee->id][$source->attendance_date] ?? 0) + AttendanceCalculator::workedSeconds($candidate);
                    if (! $preview) {
                        AttendanceCalculator::recalculate($candidate);
                    }
                    $report['created']++;
                }
            }
            foreach (PayrollRecord::with(['employee', 'payrollPeriod'])->get() as $record) {
                $period = $record->payrollPeriod;
                $computed = $calculator->calculate($record->employee, $period->start_date, $period->end_date);
                if ($preview) {
                    $extra = collect($pendingSeconds[$record->employee_id] ?? [])->filter(fn ($seconds, $date) => $date >= $period->start_date && $date <= $period->end_date)->sum();
                    $computed['gross_pay'] = round(((app(DtrService::class)->build($record->employee, $period->start_date, $period->end_date)['summary']['worked_seconds'] + $extra) / 3600) * (float) $record->employee->rate_amount, 2);
                }
                $protected = $record->status !== 'draft' || $period->status === 'finalized';
                $report['payroll'][] = ['id' => $record->id, 'protected' => $protected, 'old_gross' => (float) $record->gross_pay,
                    'recomputed_gross' => $computed['gross_pay'], 'difference' => round($computed['gross_pay'] - (float) $record->gross_pay, 2)];
                if (! $preview && ! $protected) {
                    $calculator->updateDraft($record, $computed);
                }
            }
        };
        if ($preview) {
            $work();
        } else {
            DB::transaction($work);
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\SemesterEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SemesterLifecycleService
{
    public function synchronize(): int
    {
        if (! Schema::hasTable('academic_terms')) {
            return 0;
        }
        $today = Carbon::today('Asia/Manila')->toDateString();
        $due = fn ($query) => $query->where(fn ($query) => $query->where('status', 'active')->whereDate('end_date', '<', $today))
            ->orWhere(fn ($query) => $query->whereIn('status', ['draft', 'scheduled'])->whereDate('start_date', '<=', $today));
        if (! AcademicTerm::where($due)->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($today, $due) {
            app(SemesterManagementService::class)->lock();
            $changes = 0;
            foreach (AcademicTerm::where($due)->orderBy('start_date')->lockForUpdate()->get() as $term) {
                $before = $term->toArray();
                $status = $term->end_date->toDateString() < $today ? 'ended' : 'active';
                // Ending operations is separate from closing/finalizing history.
                // Pending leave reviews never block the next semester's start.
                $term->update(['status' => $status]);
                SemesterEvent::create(['academic_term_id' => $term->id, 'actor_id' => null,
                    'action' => $status === 'active' ? 'automatic_activation' : 'automatic_end',
                    'reason' => $status === 'active' ? 'Semester start date reached in Asia/Manila.' : 'Semester end date passed in Asia/Manila; historical closing remains available.',
                    'before' => $before, 'after' => $term->fresh()->toArray()]);
                $changes++;
            }
            if ($changes) {
                SemesterScheduleService::reset();
            }

            return $changes;
        });
    }
}

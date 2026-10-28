<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\ClassReportService;
use App\Services\CutoffService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ClassReportController extends Controller
{
    public function index(Request $request, ClassReportService $service)
    {
        $filters = $request->validate([
            'cutoff_start' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'subject' => ['nullable', 'string', 'max:255'], 'section' => ['nullable', 'string', 'max:255'],
            'class_mode' => ['nullable', 'in:lecture,laboratory'],
        ]);
        if (! empty($filters['start_date']) && ! empty($filters['end_date']) &&
            (Carbon::parse($filters['end_date'])->lt(Carbon::parse($filters['start_date'])) ||
                Carbon::parse($filters['start_date'])->diffInDays(Carbon::parse($filters['end_date'])) > 366)) {
            throw ValidationException::withMessages(['end_date' => 'Choose an ordered date range of up to 366 days.']);
        }
        $cutoff = $filters['cutoff_start'] ?? null;
        // Existing dashboard/chart links open the cutoff containing their starting date.
        if (! $cutoff && ! empty($filters['start_date'])) {
            $anchor = Carbon::parse($filters['start_date'], 'Asia/Manila');
            $cutoff = $anchor->copy()->day($anchor->day <= 15 ? 1 : 16)->toDateString();
        }
        [$start, $end] = CutoffService::range($cutoff);
        $filters = [
            'cutoff_start' => $start->toDateString(),
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'employee_id' => $filters['employee_id'] ?? '', 'subject' => $filters['subject'] ?? '',
            'section' => $filters['section'] ?? '', 'class_mode' => $filters['class_mode'] ?? '',
        ];

        // Choices come from this faculty's actual report sessions, including saved
        // historical class snapshots in this period, never another faculty's classes.
        $basis = $service->build([...$filters, 'subject' => '', 'section' => '', 'class_mode' => '']);
        $sessions = collect($basis['rows']);
        $subjects = $sessions->pluck('subject')->filter(fn ($value) => $value !== null && $value !== '')->unique()->sort()->values();
        if ($filters['subject'] !== '' && ! $subjects->contains($filters['subject'])) {
            $filters['subject'] = '';
            $filters['section'] = '';
        }
        $sections = $sessions->when($filters['subject'] !== '', fn ($rows) => $rows->where('subject', $filters['subject']))
            ->pluck('section')->filter(fn ($value) => $value !== null && $value !== '')->unique()->sort()->values();
        if ($filters['section'] !== '' && ! $sections->contains($filters['section'])) {
            $filters['section'] = '';
        }

        return Inertia::render('Reports/Classes', [
            'report' => $filters['subject'] !== '' || $filters['section'] !== '' || $filters['class_mode'] !== ''
                ? $service->build($filters) : [...$basis, 'filters' => $filters],
            'employees' => Employee::orderBy('last_name')->get(),
            'subjects' => $subjects,
            'sections' => $sections,
        ]);
    }
}

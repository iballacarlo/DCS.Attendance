<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Services\AttendanceGenerationService;
use App\Services\CutoffService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceGeneratorController extends Controller
{
    public function index()
    {
        [$start] = CutoffService::range();

        $semesters = AcademicTerm::whereIn('status', ['active', 'ended'])->inAcademicOrder()->get();

        return Inertia::render('Generator/Index', [
            'semesters' => $semesters,
            'filters' => ['period' => 'cutoff', 'term_id' => ($semesters->firstWhere('status', 'active') ?? $semesters->first())?->id ?? '', 'mode' => 'class', 'cutoff_start' => $start->toDateString(), 'employee_id' => '', 'overwrite' => false],
            'employees' => Employee::where('status', 'active')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix']),
        ]);
    }

    public function preview(Request $request, AttendanceGenerationService $generator)
    {
        $filters = $this->selection($request);
        $seed = Str::random(32);
        $summary = $generator->preview($filters, $seed);
        $guards = $summary['guards'];
        unset($summary['guards']);
        $token = Str::random(48);
        $request->session()->put('attendance_generator_preview', ['filters' => $filters, 'seed' => $seed, 'token' => $token,
            'guards' => $guards, 'overwrite_count' => $summary['to_overwrite'],
            'actor_id' => $request->user()->id, 'security_version' => $request->user()->security_version, 'expires_at' => now()->addMinutes(10)->timestamp]);

        return response()->json(['preview' => $summary, 'token' => $token])->header('Cache-Control', 'no-store');
    }

    public function generate(Request $request, AttendanceGenerationService $generator)
    {
        if (! $request->has('preview_token')) {
            $filters = $this->selection($request);
            $request->validate(['confirm_overwrite' => ['sometimes', 'boolean']]);
            if ($filters['overwrite'] && ! $request->boolean('confirm_overwrite')) {
                throw ValidationException::withMessages(['confirm_overwrite' => 'Select overwrite to replace existing IN/OUT times.']);
            }
            $request->session()->forget('attendance_generator_preview');
            $result = $generator->generate($filters, Str::random(32), $request->user());
            unset($result['guards']);

            return response()->json(['result' => $result])->header('Cache-Control', 'no-store');
        }

        $data = $request->validate(['preview_token' => ['required', 'string', 'max:128'], 'confirm_overwrite' => ['sometimes', 'boolean']]);
        $preview = $request->session()->get('attendance_generator_preview');
        if (! $preview || ! hash_equals($preview['token'], $data['preview_token']) || $preview['expires_at'] <= now()->timestamp
            || $preview['actor_id'] !== $request->user()->id || $preview['security_version'] !== $request->user()->security_version) {
            throw ValidationException::withMessages(['preview_token' => 'Preview the current selection before generating records.']);
        }
        if (($preview['overwrite_count'] ?? 0) > 0 && ! $request->boolean('confirm_overwrite')) {
            throw ValidationException::withMessages(['confirm_overwrite' => 'Confirm replacing the existing records shown in the preview.']);
        }
        $request->session()->forget('attendance_generator_preview');
        $result = $generator->generate($preview['filters'], $preview['seed'], $request->user(), $preview['guards']);
        unset($result['guards']);

        return response()->json(['result' => $result])->header('Cache-Control', 'no-store');
    }

    private function selection(Request $request): array
    {
        $data = $request->validate([
            'mode' => ['required', 'in:class,school'],
            'period' => ['sometimes', 'in:cutoff,semester'],
            'term_id' => ['required_if:period,semester', 'nullable', 'integer'],
            'cutoff_start' => ['required_unless:period,semester', 'nullable', 'date_format:Y-m-d'],
            'overwrite' => ['sometimes', 'boolean'],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('archived_at')],
        ]);
        if (($data['period'] ?? 'cutoff') === 'semester') {
            [$start, $end] = AttendanceGenerationService::periodRange($data);

            return ['mode' => $data['mode'], 'period' => 'semester', 'term_id' => (int) $data['term_id'],
                'semester_start_date' => $start->toDateString(), 'semester_end_date' => $end->toDateString(),
                'employee_id' => $data['employee_id'] ?? null, 'overwrite' => (bool) ($data['overwrite'] ?? false)];
        }
        [$start] = CutoffService::range($data['cutoff_start']);

        return ['mode' => $data['mode'], 'cutoff_start' => $start->toDateString(), 'employee_id' => $data['employee_id'] ?? null,
            'overwrite' => (bool) ($data['overwrite'] ?? false)];
    }
}

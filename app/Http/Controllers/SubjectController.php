<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\FacultySchedule;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SubjectController extends Controller
{
    private const SEMESTERS = ['First Semester', 'Second Semester', 'Midyear Term'];

    public function index()
    {
        $usedCodes = FacultySchedule::withoutGlobalScopes()->whereNotNull('subject')->distinct()->pluck('subject')->all();
        $openTermIds = AcademicTerm::query()->where('status', '!=', 'closed')->pluck('id');
        $protectedCodes = FacultySchedule::withoutGlobalScopes()->whereNotNull('subject')
            ->where(fn ($query) => $query->whereNull('academic_term_id')->orWhereIn('academic_term_id', $openTermIds))
            ->distinct()->pluck('subject')->all();

        return Inertia::render('Settings/Subjects', [
            'subjects' => Subject::query()
                ->orderBy('year_level')
                ->orderByRaw("CASE semester WHEN 'First Semester' THEN 1 WHEN 'Second Semester' THEN 2 WHEN 'Midyear Term' THEN 3 ELSE 4 END")
                ->orderBy('code')
                ->get()
                ->each(function (Subject $subject) use ($usedCodes, $protectedCodes) {
                    $subject->setAttribute('used_by_schedules', in_array($subject->code, $usedCodes, true));
                    $subject->setAttribute('used_by_open_schedules', in_array($subject->code, $protectedCodes, true));
                }),
            'semesters' => self::SEMESTERS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Subject::create($data);

        return back()->with('success', 'Subject added. It is now available when preparing schedules.');
    }

    public function update(Request $request, Subject $subject)
    {
        $data = $this->validated($request, $subject);
        $used = FacultySchedule::withoutGlobalScopes()->where('subject', $subject->code)->exists();
        if ($data['code'] !== $subject->code && $used) {
            throw ValidationException::withMessages([
                'code' => 'This code is already used by saved schedules. Deactivate it and add the new code as another subject instead.',
            ]);
        }
        if ($used && ((int) $data['year_level'] !== $subject->year_level || $data['semester'] !== $subject->semester)) {
            throw ValidationException::withMessages([
                'year_level' => 'The year level and semester are fixed because saved schedules already use this subject.',
            ]);
        }
        if ($data['lecture_only'] && ! $subject->lecture_only && FacultySchedule::withoutGlobalScopes()
            ->where('subject', $subject->code)->where('class_mode', 'laboratory')->exists()) {
            throw ValidationException::withMessages([
                'lecture_only' => 'This subject has saved laboratory schedules and cannot be changed to Lecture only.',
            ]);
        }

        $subject->update($data);

        return back()->with('success', 'Subject details updated.');
    }

    public function toggle(Subject $subject)
    {
        if ($subject->is_active && $this->usedByOpenSchedule($subject)) {
            throw ValidationException::withMessages([
                'subject' => 'This subject is used by an active or upcoming schedule. Remove or replace it from those schedules before deactivating it.',
            ]);
        }
        $subject->update(['is_active' => ! $subject->is_active]);

        return back()->with('success', $subject->is_active
            ? 'Subject activated and available for new schedules.'
            : 'Subject deactivated. Existing schedule and attendance history was kept.');
    }

    private function validated(Request $request, ?Subject $subject = null): array
    {
        $request->merge([
            'code' => Str::upper(preg_replace('/\s+/', ' ', trim((string) $request->input('code')))),
            'name' => Str::upper(preg_replace('/\s+/', ' ', trim((string) $request->input('name')))),
        ]);

        return $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]+(?:[ -][A-Z0-9]+)*$/', Rule::unique('subjects', 'code')->ignore($subject)],
            'name' => ['required', 'string', 'max:255', Rule::unique('subjects', 'name')->ignore($subject)
                ->where(fn ($query) => $query->where('year_level', $request->integer('year_level'))->where('semester', $request->input('semester')))],
            'year_level' => ['required', 'integer', 'between:1,4'],
            'semester' => ['required', Rule::in(self::SEMESTERS)],
            'lecture_only' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function usedByOpenSchedule(Subject $subject): bool
    {
        $openTermIds = AcademicTerm::query()->where('status', '!=', 'closed')->select('id');

        return FacultySchedule::withoutGlobalScopes()->where('subject', $subject->code)
            ->where(fn ($query) => $query->whereNull('academic_term_id')->orWhereIn('academic_term_id', $openTermIds))
            ->exists();
    }
}

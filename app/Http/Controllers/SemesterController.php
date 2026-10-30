<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FacultyScheduleVersion;
use App\Models\FacultyTermAssignment;
use App\Models\SemesterEvent;
use App\Services\SemesterManagementService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class SemesterController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['term_id' => ['nullable', 'integer', 'exists:academic_terms,id'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'], 'view' => ['nullable', 'in:current,closed'],
            'academic_year' => ['nullable', 'regex:/^\d{4}-\d{4}$/'], 'semesters_page' => ['nullable', 'integer', 'min:1']]);
        $terms = AcademicTerm::inAcademicOrder()->get();
        $selected = ! empty($data['term_id']) ? $terms->firstWhere('id', (int) $data['term_id']) : null;
        $view = $selected ? ($selected->status === 'closed' ? 'closed' : 'current') : ($data['view'] ?? 'current');
        $academicYear = (string) ($data['academic_year'] ?? '');
        if ($selected && $academicYear !== '' && $selected->school_year !== $academicYear) {
            $academicYear = $selected->school_year;
        }
        $catalog = $terms->filter(fn ($item) => ($item->status === 'closed') === ($view === 'closed'))->values();
        $years = $catalog->pluck('school_year')->unique()->sortDesc()->values();
        $catalog = $catalog->when($academicYear !== '', fn ($rows) => $rows->where('school_year', $academicYear))->values();
        $page = (int) ($data['semesters_page'] ?? 1);
        $preferred = $selected ?? ($view === 'current' ? $catalog->firstWhere('status', 'active') : null);
        if ($preferred && ! $request->has('semesters_page')) {
            $index = $catalog->search(fn ($item) => $item->id === $preferred->id);
            $page = $index === false ? 1 : intdiv($index, 6) + 1;
        }
        $page = min($page, max(1, (int) ceil($catalog->count() / 6)));
        $listing = new LengthAwarePaginator($catalog->forPage($page, 6)->values(), $catalog->count(), 6, $page,
            ['path' => $request->url(), 'pageName' => 'semesters_page', 'query' => ['view' => $view, 'academic_year' => $academicYear]]);
        $term = $selected ?? ($listing->getCollection()->firstWhere('status', 'active') ?? $listing->getCollection()->first());
        $assignments = $term ? FacultyTermAssignment::where('academic_term_id', $term->id)->get()->keyBy('employee_id') : collect();
        $faculty = Employee::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $assignments->keys()))->orderBy('last_name')->get();

        return Inertia::render('Settings/Semesters', ['terms' => $terms, 'selectedTerm' => $term, 'today' => Carbon::today('Asia/Manila')->toDateString(),
            'semesterListing' => $listing, 'semesterFilters' => ['view' => $view, 'academic_year' => $academicYear], 'semesterYears' => $years,
            'startDateLocked' => $term?->status === 'active' && app(SemesterManagementService::class)->hasHistoricalBasis($term),
            'publishResult' => (int) $request->session()->get('semester_publish_result.term_id') === $term?->id ? $request->session()->get('semester_publish_result') : null,
            'faculty' => $faculty->map(fn ($e) => [...$e->only(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'archived_at']),
                'assigned' => $assignments->has($e->id),
                'needs_publication' => $term && $term->status !== 'closed' && ! $e->archived_at && $e->status === 'active'
                    && $assignments->has($e->id)
                    && app(SemesterManagementService::class)->publicationPending($term, $e, $assignments->get($e->id)->draft_blocks, $assignments->get($e->id)->profile),
                'published_versions' => $term ? FacultyScheduleVersion::where('academic_term_id', $term->id)->where('employee_id', $e->id)->count() : 0]),
            'history' => $term ? SemesterEvent::where('academic_term_id', $term->id)
                ->where('reason', '!=', 'Recovered saved schedules for administrator-confirmed semester dates')->orderByDesc('id')->paginate((int) $request->input('per_page', 10))->withQueryString() : null]);
    }

    public function store(Request $request, SemesterManagementService $service)
    {
        $term = $service->create($request->only(['school_year', 'semester', 'start_date', 'end_date']), $request->user());

        return redirect('/settings/semesters?term_id='.$term->id)->with('success', 'Semester saved. It starts automatically on its start date.');
    }

    public function update(Request $request, AcademicTerm $term, SemesterManagementService $service)
    {
        $service->update($term, $request->only(['school_year', 'semester', 'start_date', 'end_date']), $request->user());

        return back()->with('success', 'Semester details updated.');
    }

    public function destroy(Request $request, AcademicTerm $term, SemesterManagementService $service)
    {
        $service->deleteUpcoming($term, $request->user());

        return redirect('/settings/semesters')->with('success', 'Upcoming semester deleted.');
    }

    public function transition(Request $request, AcademicTerm $term, SemesterManagementService $service)
    {
        $data = $request->validate(['action' => ['required', 'in:activate,close,reopen,map_legacy,archive_legacy'], 'reason' => ['required_if:action,reopen,map_legacy,archive_legacy', 'nullable', 'string', 'min:5', 'max:1000']]);
        $defaultReason = $data['action'] === 'close' ? 'Semester closed by administrator.' : 'Semester activated by administrator.';
        $service->transition($term, $data['action'], $data['reason'] ?? $defaultReason, $request->user());

        return back()->with('success', 'Semester updated.');
    }

    public function publishAll(Request $request, AcademicTerm $term, SemesterManagementService $service)
    {
        $result = $service->publishAll($term, $request->user());

        return redirect('/settings/semesters?term_id='.$term->id)->with('semester_publish_result', $result);
    }

    public function copy(Request $request, AcademicTerm $term, int $employeeId, SemesterManagementService $service)
    {
        $employee = Employee::withoutGlobalScopes()->findOrFail($employeeId);
        $data = $request->validate(['source' => ['required', 'string', 'max:30']]);
        abort_if((string) $term->id === $data['source'], 422, 'Choose another semester as the source.');
        $service->copy($term, $employee, $data['source'], $request->user());

        return redirect('/employees/'.$employee->id.'/schedule?term_id='.$term->id)->with('success', 'Schedule copied as draft. Review every class before publishing.');
    }
}

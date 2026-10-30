<?php

namespace App\Http\Middleware;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FacultyScheduleVersion;
use App\Models\SystemSetting;
use App\Services\SemesterScheduleService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'employee_id' => $request->user()->employee_id,
                    'must_change_password' => $request->user()->must_change_password,
                    'email_verified' => $request->user()->hasVerifiedEmail(),
                    'employee' => $request->user()->employee,
                ] : null,
            ],
            'semesterOptions' => function () use ($request) {
                $query = AcademicTerm::inAcademicOrder();
                if ($request->user()?->role !== 'admin' || ! $request->routeIs('settings.semesters.index')) {
                    $query->where('status', '!=', 'draft');
                }
                if ($request->user()?->role === 'faculty') {
                    $query->whereIn('id', FacultyScheduleVersion::where('employee_id', $request->user()->employee_id ?? 0)->select('academic_term_id'));
                }

                return $query->get(['id', 'school_year', 'semester', 'start_date', 'end_date', 'status', 'reopened']);
            },
            'semesterManaged' => fn () => SemesterScheduleService::managed(),
            'academicTerm' => fn () => SystemSetting::academicTerm(),
            'facultyChoices' => fn () => $request->user() && in_array($request->user()->role, ['admin'], true)
                ? Employee::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix']) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'temporaryCredentials' => fn () => $request->session()->get('temporary_credentials'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}

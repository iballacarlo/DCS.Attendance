<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Services\ScheduleRecoveryService;
use App\Services\SelectiveBackupService;
use App\Services\SemesterBackupValidator;
use App\Services\SemesterScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BackupController extends Controller
{
    private const TABLES = [
        'faculty_ranks', 'employees', 'users', 'rfid_cards', 'fingerprint_templates', 'employment_histories',
        'academic_terms', 'faculty_term_assignments', 'faculty_schedule_versions', 'semester_day_snapshots', 'semester_events',
        'faculty_schedules', 'faculty_schedule_breaks', 'attendance_logs', 'hardware_attendance_events', 'entrance_attendances',
        'calendar_events', 'calendar_event_employee', 'dtr_signatories',
        'leave_types', 'leave_requests',
        'payroll_periods', 'payroll_records', 'audit_trails', 'system_settings',
    ];

    public function index(Request $request)
    {
        return Inertia::render('Settings/Backup', ['backupGroups' => collect(SelectiveBackupService::GROUPS)->except('payroll')
            ->map(fn ($group, $key) => ['value' => $key, 'label' => $group['label'], 'description' => $group['description']])->values(), 'recoveryResult' => $request->session()->get('schedule_recovery_result')]);
    }

    public function download(Request $request, SelectiveBackupService $service)
    {
        $data = $request->validate([
            'mode' => ['nullable', 'in:full,custom'],
            'groups' => ['required_if:mode,custom', 'array', 'min:1'],
            'groups.*' => ['string', 'distinct', Rule::in(array_keys(SelectiveBackupService::GROUPS))],
            'faculty_scope' => ['required_if:mode,custom', 'in:all,selected'],
            'employee_ids' => [Rule::requiredIf($request->input('mode') === 'custom' && $request->input('faculty_scope') === 'selected'), 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->whereNull('archived_at')],
        ]);
        $custom = ($data['mode'] ?? 'full') === 'custom';
        if ($custom && $data['faculty_scope'] === 'selected' && in_array('audit', $data['groups'], true)) {
            throw ValidationException::withMessages(['groups' => 'Audit history can only be backed up with all faculty.']);
        }
        if ($missingTables = $this->missingTables($custom ? $service->tables($data['groups']) : self::TABLES)) {
            return redirect()->route('settings.backup.index')->with(
                'error',
                'Backup is unavailable because the database is missing required tables: '.implode(', ', $missingTables).'. Run the latest database migrations first.'
            );
        }

        $backup = $custom ? $service->create($data['groups'], $data['faculty_scope'], $data['employee_ids'] ?? [], self::TABLES) : [
            'application' => 'DCS Attendance & DTR',
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'tables' => collect(self::TABLES)->mapWithKeys(
                fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()]
            )->all(),
        ];

        $filename = ($custom ? 'CUSTOM_BACKUP_' : 'SYSTEM_BACKUP_').now('Asia/Manila')->format('Y-m-d_His').'.json';

        return response()->streamDownload(function () use ($backup) {
            echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    public function restore(Request $request, SelectiveBackupService $service, ScheduleRecoveryService $recovery)
    {
        $request->validate(['backup_file' => ['required', 'file', 'max:20480'],
            'schedule_term_id' => ['nullable', 'integer', Rule::exists('academic_terms', 'id')->where('status', 'active')]]);
        $backup = json_decode($request->file('backup_file')->get(), true);

        if (! is_array($backup) || ! in_array($backup['application'] ?? null, ['CvSU Payroll System', 'DCS Attendance & DTR'], true) || ! in_array($backup['version'] ?? null, [1, 2], true)) {
            throw ValidationException::withMessages(['backup_file' => 'The selected file is not a valid attendance system backup.']);
        }
        if ($backup['version'] === 2) {
            [$count, $result] = DB::transaction(function () use ($request, $backup, $service, $recovery) {
                $count = $service->restore($backup, self::TABLES);
                $result = $request->filled('schedule_term_id')
                    ? $recovery->recover($backup, AcademicTerm::findOrFail($request->integer('schedule_term_id')), $request->user()) : null;

                return [$count, $result];
            });
            $message = $count ? "Custom backup recovered: {$count} missing records added. Existing records were kept."
                : 'No new records added. The backup records already exist locally; existing records were kept.';
            if ($result) {
                $message .= " {$result['published']} faculty schedules linked to {$result['term']}.";
            }

            return redirect()->route('settings.backup.index')->with('success', $message)->with('schedule_recovery_result', $result);
        }

        foreach (array_diff(self::TABLES, [
            'academic_terms', 'faculty_term_assignments', 'faculty_schedule_versions', 'semester_day_snapshots', 'semester_events',
            'entrance_attendances', 'employment_histories', 'system_settings', 'hardware_attendance_events',
            'calendar_events', 'calendar_event_employee', 'dtr_signatories',
            'leave_types', 'leave_requests',
        ]) as $table) {
            if (! isset($backup['tables'][$table]) || ! is_array($backup['tables'][$table])) {
                throw ValidationException::withMessages(['backup_file' => "Backup data for {$table} is missing."]);
            }
        }

        if ($missingTables = $this->missingTables()) {
            throw ValidationException::withMessages([
                'backup_file' => 'The local database is not up to date. Missing tables: '.implode(', ', $missingTables).'. Run the latest database migrations, then restore the backup again.',
            ]);
        }

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($backup) {
                $previousVersion = (int) DB::table('users')->max('security_version');
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->delete();
                }
                foreach (self::TABLES as $table) {
                    foreach (array_chunk($backup['tables'][$table] ?? [], 200) as $rows) {
                        if ($rows) {
                            DB::table($table)->insert($rows);
                        }
                    }
                }
                SemesterBackupValidator::check();
                SemesterScheduleService::reset();
                // A full restore must never make a previously revoked session valid again.
                $version = max($previousVersion, (int) DB::table('users')->max('security_version')) + 1;
                foreach (DB::table('users')->pluck('id') as $id) {
                    DB::table('users')->where('id', $id)->update([
                        'security_version' => $version,
                        'remember_token' => Str::random(60),
                    ]);
                }
                DB::table('password_reset_tokens')->delete();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->delete();
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Backup restored successfully. Sign in again to continue.');
    }

    private function missingTables(array $tables = self::TABLES): array
    {
        return array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));
    }
}

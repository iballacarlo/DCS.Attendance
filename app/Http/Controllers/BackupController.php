<?php

namespace App\Http\Controllers;

use App\Services\SelectiveBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BackupController extends Controller
{
    private const TABLES = [
        'faculty_ranks', 'employees', 'users', 'rfid_cards', 'fingerprint_templates', 'employment_histories',
        'faculty_schedules', 'faculty_schedule_breaks', 'attendance_logs', 'hardware_attendance_events',
        'calendar_events', 'calendar_event_employee', 'dtr_signatories',
        'leave_types', 'leave_requests',
        'payroll_periods', 'payroll_records', 'audit_trails', 'system_settings',
    ];

    public function index()
    {
        return Inertia::render('Settings/Backup', ['backupGroups' => collect(SelectiveBackupService::GROUPS)
            ->map(fn ($group, $key) => ['value' => $key, 'label' => $group['label'], 'description' => $group['description']])->values()]);
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
            'application' => 'CvSU Payroll System',
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

    public function restore(Request $request, SelectiveBackupService $service)
    {
        $request->validate(['backup_file' => ['required', 'file', 'max:20480']]);
        $backup = json_decode($request->file('backup_file')->get(), true);

        if (! is_array($backup) || ($backup['application'] ?? null) !== 'CvSU Payroll System' || ! in_array($backup['version'] ?? null, [1, 2], true)) {
            throw ValidationException::withMessages(['backup_file' => 'The selected file is not a valid payroll system backup.']);
        }
        if ($backup['version'] === 2) {
            $count = $service->restore($backup, self::TABLES);

            return redirect()->route('settings.backup.index')->with('success', "Custom backup recovered: {$count} missing records added. Existing records were kept.");
        }

        foreach (array_diff(self::TABLES, [
            'employment_histories', 'system_settings', 'hardware_attendance_events',
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
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return redirect()->route('settings.backup.index')->with('success', 'Backup restored successfully.');
    }

    private function missingTables(array $tables = self::TABLES): array
    {
        return array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));
    }
}

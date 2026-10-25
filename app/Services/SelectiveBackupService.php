<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SelectiveBackupService
{
    public const GROUPS = [
        'faculty' => ['label' => 'Faculty profiles', 'description' => 'Profiles, ranks, and employment history.', 'tables' => ['employees', 'faculty_ranks', 'employment_histories']],
        'schedules' => ['label' => 'Schedules', 'description' => 'Weekly class, consultation, and break blocks.', 'tables' => ['faculty_schedules', 'faculty_schedule_breaks']],
        'attendance' => ['label' => 'Attendance logs', 'description' => 'Taps, class snapshots, legacy records, and hardware events.', 'tables' => ['attendance_logs', 'hardware_attendance_events']],
        'payroll' => ['label' => 'Payroll records', 'description' => 'Payslips, adjustments, and their payroll periods.', 'tables' => ['payroll_records', 'payroll_periods']],
        'credentials' => ['label' => 'Attendance credentials', 'description' => 'RFID cards and fingerprint registrations.', 'tables' => ['rfid_cards', 'fingerprint_templates']],
        'accounts' => ['label' => 'User accounts', 'description' => 'Linked faculty accounts; all accounts when backing up all faculty.', 'tables' => ['users']],
        'leave' => ['label' => 'Leave requests', 'description' => 'Requests and their leave types.', 'tables' => ['leave_requests', 'leave_types']],
        'calendar' => ['label' => 'School calendar', 'description' => 'Shared events and events assigned to the selected faculty.', 'tables' => ['calendar_events', 'calendar_event_employee']],
        'settings' => ['label' => 'System settings', 'description' => 'Shared academic term settings and DTR signatories.', 'tables' => ['system_settings', 'dtr_signatories']],
        'audit' => ['label' => 'Audit history', 'description' => 'Available only when backing up all faculty.', 'tables' => ['audit_trails']],
    ];

    public function tables(array $groups): array
    {
        $tables = array_intersect($groups, ['faculty', 'schedules', 'attendance', 'payroll', 'credentials', 'accounts', 'leave', 'calendar'])
            ? ['employees', 'faculty_ranks'] : [];
        foreach ($groups as $group) {
            $tables = array_merge($tables, self::GROUPS[$group]['tables']);
        }
        if (in_array('attendance', $groups, true)) {
            $tables = array_merge($tables, self::GROUPS['schedules']['tables']);
        }

        return array_values(array_unique($tables));
    }

    public function create(array $groups, string $scope, array $ids, array $order): array
    {
        return DB::transaction(function () use ($groups, $scope, $ids, $order) {
            $employees = DB::table('employees')->when($scope === 'selected', fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();
            $employeeIds = in_array('employees', $this->tables($groups), true) ? $employees->pluck('id')->all() : [];
            $tables = [];
            foreach (array_intersect($order, $this->tables($groups)) as $table) {
                $query = DB::table($table);
                if ($table === 'employees') {
                    $query->whereIn('id', $employeeIds);
                } elseif ($table === 'faculty_ranks') {
                    $query->when($scope === 'selected', fn ($q) => $q->whereIn('id', $employees->pluck('faculty_rank_id')->filter()));
                } elseif ($table === 'users') {
                    $query->when($scope === 'selected', fn ($q) => $q->whereIn('employee_id', $employeeIds));
                } elseif ($table === 'payroll_periods') {
                    $query->whereIn('id', DB::table('payroll_records')->whereIn('employee_id', $employeeIds)->select('payroll_period_id'));
                } elseif ($table === 'leave_types') {
                    $query->when($scope === 'selected', fn ($q) => $q->whereIn('id', DB::table('leave_requests')->whereIn('employee_id', $employeeIds)->select('leave_type_id')));
                } elseif ($table === 'calendar_events') {
                    $query->when($scope === 'selected', fn ($q) => $q->where(fn ($q) => $q->where('scope_type', '!=', 'faculty')->orWhereIn('id', DB::table('calendar_event_employee')->whereIn('employee_id', $employeeIds)->select('calendar_event_id'))));
                } elseif (! in_array($table, ['system_settings', 'dtr_signatories', 'audit_trails'], true)) {
                    $query->whereIn('employee_id', $employeeIds);
                }
                $tables[$table] = $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            // Do not pull unselected accounts or credentials into a scoped backup just for an author link.
            $userIds = array_column($tables['users'] ?? [], 'id');
            foreach ($tables as &$rows) {
                foreach ($rows as &$row) {
                    foreach (['created_by', 'reviewed_by', 'archived_by'] as $field) {
                        if (isset($row[$field]) && ! in_array($row[$field], $userIds)) {
                            $row[$field] = null;
                        }
                    }
                }
                unset($row);
            }
            unset($rows);

            return ['application' => 'CvSU Payroll System', 'version' => 2, 'created_at' => now()->toIso8601String(),
                'selection' => ['groups' => $groups, 'faculty_scope' => $scope, 'employee_ids' => $employeeIds,
                    'recovery' => 'add_missing_keep_existing'],
                'tables' => $tables];
        });
    }

    public function restore(array $backup, array $order): int
    {
        $selection = $backup['selection'] ?? [];
        if (! is_array($selection)) {
            $this->invalid('The custom backup selection is invalid.');
        }
        $groups = $selection['groups'] ?? [];
        $scope = $selection['faculty_scope'] ?? null;
        if (! is_array($groups) || ! $groups || count(array_filter($groups, 'is_string')) !== count($groups) || array_diff($groups, array_keys(self::GROUPS)) || ! in_array($scope, ['all', 'selected'], true)
            || ($scope === 'selected' && in_array('audit', $groups, true)) || ! is_array($backup['tables'] ?? null)) {
            $this->invalid('The custom backup selection is invalid.');
        }
        $required = $this->tables($groups);
        if (array_diff($required, array_keys($backup['tables'])) || array_diff(array_keys($backup['tables']), $required)) {
            $this->invalid('The custom backup tables do not match its selected data.');
        }
        foreach ($required as $table) {
            if (! Schema::hasTable($table) || ! is_array($backup['tables'][$table])) {
                $this->invalid('The backup or local database is missing '.$table.'.');
            }
            $columns = Schema::getColumnListing($table);
            foreach ($backup['tables'][$table] as $row) {
                if (! is_array($row) || ! isset($row['id']) || ! is_int($row['id']) || $row['id'] < 1 || array_diff(array_keys($row), $columns)) {
                    $this->invalid('Invalid record format in '.$table.'.');
                }
            }
        }
        $ids = array_column($backup['tables']['employees'] ?? [], 'id');
        if (! is_array($selection['employee_ids'] ?? null) || count(array_filter($selection['employee_ids'], fn ($id) => is_int($id) && $id > 0)) !== count($selection['employee_ids']) || array_diff($ids, $selection['employee_ids']) || array_diff($selection['employee_ids'], $ids)
            || ($scope === 'selected' && in_array('employees', $required, true) && ! $ids)) {
            $this->invalid('Faculty records do not match the backup selection.');
        }
        foreach ($backup['tables'] as $table => $rows) {
            foreach ($rows as $row) {
                if (array_key_exists('employee_id', $row) && ! in_array($row['employee_id'], $ids, true)
                    && ! ($table === 'users' && $scope === 'all' && $row['employee_id'] === null)) {
                    $this->invalid('The backup contains records outside its faculty selection.');
                }
            }
        }
        try {
            return DB::transaction(function () use ($backup, $order) {
                $inserted = 0;
                foreach (array_intersect($order, array_keys($backup['tables'])) as $table) {
                    foreach ($backup['tables'][$table] as $row) {
                        $existing = DB::table($table)->where('id', $row['id'])->first();
                        if ($existing) {
                            foreach (['employee_id', 'employee_no', 'email', 'payroll_period_id', 'calendar_event_id', 'event_id'] as $field) {
                                if (array_key_exists($field, $row) && (string) $row[$field] !== (string) ($existing->$field ?? null)) {
                                    $this->invalid('A record ID in '.$table.' belongs to different data. No records were restored.');
                                }
                            }
                            if (in_array($table, ['payroll_periods', 'faculty_ranks', 'leave_types', 'system_settings'], true)) {
                                foreach (['start_date', 'end_date', 'name', 'code', 'key'] as $field) {
                                    if (array_key_exists($field, $row) && (string) $row[$field] !== (string) ($existing->$field ?? null)) {
                                        $this->invalid('Reference data in '.$table.' conflicts with the local database. No records were restored.');
                                    }
                                }
                            }

                            continue;
                        }
                        DB::table($table)->insert($row);
                        $inserted++;
                    }
                }

                return $inserted;
            });
        } catch (QueryException $exception) {
            $this->invalid('The custom backup conflicts with existing records or is missing required related data. No records were restored.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['backup_file' => $message]);
    }
}

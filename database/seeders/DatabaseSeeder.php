<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $faculty = [
            [
                'employee_no' => 'COS-2026-001',
                'first_name' => 'Maria',
                'middle_name' => 'Santos',
                'last_name' => 'Reyes',
                'email' => 'maria.reyes@cvsu.edu.ph',
                'contact_no' => '+639171234567',
                'position' => 'COS Faculty Member',
                'rate_type' => 'daily',
                'rate_amount' => 1200,
                'rfid_uid' => 'RFID-1001',
                'fingerprint_code' => 'FP-1001',
            ],
            [
                'employee_no' => 'COS-2026-002',
                'first_name' => 'Juan',
                'middle_name' => 'Cruz',
                'last_name' => 'Dela Cruz',
                'email' => 'juan.delacruz@cvsu.edu.ph',
                'contact_no' => '+639181234567',
                'position' => 'COS Instructor',
                'rate_type' => 'hourly',
                'rate_amount' => 180,
                'rfid_uid' => 'RFID-1002',
                'fingerprint_code' => 'FP-1002',
            ],
            [
                'employee_no' => 'COS-2026-003',
                'first_name' => 'Andrea',
                'middle_name' => null,
                'last_name' => 'Garcia',
                'email' => 'andrea.garcia@cvsu.edu.ph',
                'contact_no' => '+639191234567',
                'position' => 'COS Faculty Member',
                'rate_type' => 'daily',
                'rate_amount' => 1100,
                'rfid_uid' => 'RFID-1003',
                'fingerprint_code' => 'FP-1003',
            ],
        ];

        $firstEmployee = null;

        foreach ($faculty as $item) {
            $employee = Employee::updateOrCreate(['employee_no' => $item['employee_no']], [
                'employee_no' => $item['employee_no'],
                'first_name' => $item['first_name'],
                'middle_name' => $item['middle_name'],
                'last_name' => $item['last_name'],
                'email' => $item['email'],
                'contact_no' => $item['contact_no'],
                'highest_educational_attainment' => "Master's Degree",
                'service_start_date' => '2020-06-01',
                'position' => $item['position'],
                'department' => 'Department of Computer Studies',
                'employment_type' => 'Contract of Service',
                'rate_type' => $item['rate_type'],
                'rate_amount' => $item['rate_amount'],
                'contract_start' => '2026-08-01',
                'contract_end' => '2026-12-31',
                'status' => 'active',
            ]);

            $employee->rfidCards()->updateOrCreate(['rfid_uid' => $item['rfid_uid']], [
                'rfid_uid' => $item['rfid_uid'],
                'status' => 'active',
                'registered_at' => Carbon::now(),
            ]);

            $employee->fingerprintTemplates()->updateOrCreate(['fingerprint_code' => $item['fingerprint_code']], [
                'fingerprint_code' => $item['fingerprint_code'],
                'finger_label' => 'Right thumb',
                'status' => 'active',
                'registered_at' => Carbon::now(),
            ]);

            AttendanceLog::updateOrCreate([
                'employee_id' => $employee->id,
                'attendance_date' => Carbon::today()->toDateString(),
            ], [
                'employee_id' => $employee->id,
                'attendance_date' => Carbon::today()->toDateString(),
                'time_in' => '08:05:00',
                'time_out' => '17:00:00',
                'method_in' => 'rfid',
                'method_out' => 'rfid',
                'late_minutes' => 5,
                'undertime_minutes' => 0,
                'total_hours' => 7.92,
                'status' => 'late',
                'remarks' => 'Sample seeded attendance.',
            ]);

            $firstEmployee ??= $employee;
        }

        User::updateOrCreate(['email' => 'admin@dcs.test'], [
            'name' => 'System Administrator',
            'email' => 'admin@dcs.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        User::updateOrCreate(['email' => 'faculty@dcs.test'], [
            'name' => $firstEmployee->full_name,
            'email' => 'faculty@dcs.test',
            'password' => Hash::make('password'),
            'role' => 'faculty',
            'employee_id' => $firstEmployee->id,
        ]);
    }
}

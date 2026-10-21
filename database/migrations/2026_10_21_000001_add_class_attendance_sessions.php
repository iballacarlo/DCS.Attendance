<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('faculty_schedules', 'subject')) {
            Schema::table('faculty_schedules', function (Blueprint $table) {
                $table->string('subject')->nullable();
                $table->string('section', 100)->nullable();
            });
        }
        // InnoDB may use the old composite unique index for the employee FK.
        // Install its replacement before dropping it, including on partial retries.
        if (! Schema::hasIndex('attendance_logs', 'attendance_employee_session_index')) {
            Schema::table('attendance_logs', fn (Blueprint $table) => $table->index('employee_id', 'attendance_employee_session_index'));
        }
        if (Schema::hasIndex('attendance_logs', 'attendance_logs_employee_id_attendance_date_unique')) {
            Schema::table('attendance_logs', fn (Blueprint $table) => $table->dropUnique(['employee_id', 'attendance_date']));
        }
        if (! Schema::hasColumn('attendance_logs', 'faculty_schedule_id')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('faculty_schedule_id')->nullable()->index();
                $table->string('subject')->nullable();
                $table->string('section', 100)->nullable();
                $table->time('scheduled_start')->nullable();
                $table->time('scheduled_end')->nullable();
                $table->unsignedInteger('worked_seconds')->default(0);
                $table->unsignedBigInteger('legacy_source_id')->nullable()->index();
                $table->boolean('is_legacy')->default(true);
                $table->unique(['employee_id', 'attendance_date', 'faculty_schedule_id'], 'attendance_class_unique');
            });
        }
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('total_hours_worked', 12, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        // A destructive rollback would collapse multiple class records into one day.
        throw new RuntimeException('Restore the pre-conversion backup to roll back class attendance.');
    }
};

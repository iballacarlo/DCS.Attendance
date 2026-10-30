<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('academic_terms')) {
            Schema::create('academic_terms', function (Blueprint $table) {
                $table->id();
                $table->string('school_year', 9);
                $table->string('semester', 30);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 15)->default('draft');
                $table->boolean('reopened')->default(false);
                $table->timestamps();
                $table->unique(['school_year', 'semester']);
                $table->index(['start_date', 'end_date']);
            });
        }
        if (! Schema::hasTable('faculty_term_assignments')) {
            Schema::create('faculty_term_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('academic_term_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->json('profile');
                $table->json('draft_blocks')->nullable();
                $table->timestamps();
                $table->unique(['academic_term_id', 'employee_id']);
            });
        }
        if (! Schema::hasTable('faculty_schedule_versions')) {
            Schema::create('faculty_schedule_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('academic_term_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->date('effective_from');
                $table->json('profile');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamps();

            });
        }
        if (! Schema::hasIndex('faculty_schedule_versions', 'term_employee_effective_idx')) {
            Schema::table('faculty_schedule_versions', fn (Blueprint $table) => $table->index(['academic_term_id', 'employee_id', 'effective_from'], 'term_employee_effective_idx'));
        }
        if (! Schema::hasTable('semester_day_snapshots')) {
            Schema::create('semester_day_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('academic_term_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->date('date');
                $table->json('payload');
                $table->timestamps();
                $table->unique(['academic_term_id', 'employee_id', 'date'], 'semester_day_unique');
            });
        }
        if (! Schema::hasTable('semester_events')) {
            Schema::create('semester_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('academic_term_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('action', 40);
                $table->text('reason');
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamps();
            });
        }
        foreach (['faculty_schedules', 'faculty_schedule_breaks'] as $name) {
            if (Schema::hasColumn($name, 'academic_term_id')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('academic_term_id')->nullable()->index();
                $table->unsignedBigInteger('schedule_version_id')->nullable()->index();
            });
        }
        foreach (['attendance_logs', 'entrance_attendances'] as $name) {
            if (Schema::hasColumn($name, 'academic_term_id')) {
                continue;
            }
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('academic_term_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        foreach (['attendance_logs', 'entrance_attendances'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('academic_term_id'));
        }
        foreach (['faculty_schedules', 'faculty_schedule_breaks'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['academic_term_id', 'schedule_version_id']));
        }
        foreach (['semester_events', 'semester_day_snapshots', 'faculty_schedule_versions', 'faculty_term_assignments', 'academic_terms'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('attendance_device_room_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attendance_device_id', 'effective_from'], 'device_room_effective_idx');
        });
        Schema::table('faculty_schedules', fn (Blueprint $table) => $table->foreignId('room_id')->nullable()->after('section')->constrained()->nullOnDelete());
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('section')->constrained()->nullOnDelete();
            $table->string('room_code', 40)->nullable()->after('room_id');
            $table->boolean('school_attendance_warning')->default(false)->after('room_code');
            $table->dateTime('room_released_at')->nullable()->after('school_attendance_warning');
            $table->string('room_release_reason', 100)->nullable()->after('room_released_at');
        });
        Schema::table('hardware_attendance_events', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->after('attendance_device_id')->constrained()->nullOnDelete();
        });
        Schema::create('class_session_room_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('session_date');
            $table->foreignId('original_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('assigned_room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('swap_schedule_id')->nullable()->constrained('faculty_schedules')->cascadeOnDelete();
            $table->foreignId('swap_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->enum('status', ['approved', 'pending', 'accepted', 'rejected', 'cancelled'])->default('approved');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['faculty_schedule_id', 'session_date'], 'session_room_change_unique');
        });
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->time('previous_time_in')->nullable();
            $table->time('previous_time_out')->nullable();
            $table->time('corrected_time_in')->nullable();
            $table->time('corrected_time_out')->nullable();
            $table->string('reason', 1000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('class_session_room_changes');
        Schema::table('hardware_attendance_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('room_id'));
        Schema::table('hardware_attendance_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('attendance_device_id'));
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn(['room_code', 'school_attendance_warning', 'room_released_at', 'room_release_reason']);
        });
        Schema::table('faculty_schedules', fn (Blueprint $table) => $table->dropConstrainedForeignId('room_id'));
        Schema::dropIfExists('attendance_device_room_assignments');
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('rooms');
    }
};

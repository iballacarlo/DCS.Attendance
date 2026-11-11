<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('room_release_reason');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 1000)->nullable()->after('voided_by');
            $table->foreignId('resolved_against_log_id')->nullable()->after('void_reason')->constrained('attendance_logs')->nullOnDelete();
            $table->index(['room_id', 'attendance_date', 'voided_at'], 'room_attendance_active_idx');
        });

        Schema::create('attendance_conflict_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->foreignId('kept_attendance_log_id')->constrained('attendance_logs')->restrictOnDelete();
            $table->foreignId('voided_attendance_log_id')->unique()->constrained('attendance_logs')->restrictOnDelete();
            $table->foreignId('resolved_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->json('kept_snapshot');
            $table->json('voided_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_conflict_resolutions');
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('room_attendance_active_idx');
            $table->dropConstrainedForeignId('resolved_against_log_id');
            $table->dropColumn('void_reason');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn('voided_at');
        });
    }
};

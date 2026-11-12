<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_enrollments', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable()->after('requested_by')->constrained('attendance_devices')->nullOnDelete();
            $table->index(['attendance_device_id', 'status', 'expires_at'], 'hardware_enrollments_device_status_expiry_index');
        });

        DB::table('hardware_enrollments')
            ->whereNull('attendance_device_id')
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'cancelled', 'message' => 'Cancelled because no registration terminal was selected.']);
    }

    public function down(): void
    {
        Schema::table('hardware_enrollments', function (Blueprint $table) {
            $table->dropForeign(['attendance_device_id']);
            $table->dropIndex('hardware_enrollments_device_status_expiry_index');
            $table->dropColumn('attendance_device_id');
        });
    }
};

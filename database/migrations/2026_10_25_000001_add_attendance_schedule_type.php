<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Existing attendance was class-only; preserve it while identifying new consultation sessions.
            $table->string('schedule_type', 20)->default('class');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', fn (Blueprint $table) => $table->dropColumn('schedule_type'));
    }
};

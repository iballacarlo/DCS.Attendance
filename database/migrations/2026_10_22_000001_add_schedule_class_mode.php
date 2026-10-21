<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['faculty_schedules', 'attendance_logs'] as $name) {
            if (! Schema::hasColumn($name, 'class_mode')) {
                Schema::table($name, fn (Blueprint $table) => $table->string('class_mode', 20)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['faculty_schedules', 'attendance_logs'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('class_mode'));
        }
    }
};

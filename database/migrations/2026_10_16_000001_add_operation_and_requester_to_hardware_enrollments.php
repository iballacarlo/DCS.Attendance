<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_enrollments', function (Blueprint $table) {
            $table->enum('operation', ['replace', 'unlink'])->default('replace')->after('method');
            $table->unsignedBigInteger('requested_by')->nullable()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('hardware_enrollments', function (Blueprint $table) {
            $table->dropColumn(['operation', 'requested_by']);
        });
    }
};

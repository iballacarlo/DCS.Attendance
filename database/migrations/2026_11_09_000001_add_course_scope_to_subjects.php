<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('course_scope', 10)->default('BSIT')->after('name');
            $table->index(['course_scope', 'year_level', 'semester', 'is_active'], 'subjects_course_placement_index');
        });

        DB::table('subjects')->whereIn('code', ['NSTP 1', 'NSTP 2', 'CVSU 101'])->update(['course_scope' => 'BOTH']);
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropIndex('subjects_course_placement_index');
            $table->dropColumn('course_scope');
        });
    }
};

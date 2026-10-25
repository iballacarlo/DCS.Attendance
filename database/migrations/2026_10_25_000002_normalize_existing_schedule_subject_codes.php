<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (['DCIT 24A' => 'DCIT 24', 'DCIT 50A' => 'DCIT50', 'ITEC 55A' => 'ITEC 55', 'DCIT 65A' => 'DCIT 65', 'ITEC 80A' => 'ITEC 80'] as $old => $new) {
                // Include archived faculty so restored schedules also use the corrected codes.
                // Preserve IDs, class times, section, and historical attendance snapshots.
                DB::table('faculty_schedules')->where('subject', $old)->update(['subject' => $new]);
            }
        });
    }

    public function down(): void
    {
        // Canonical codes cannot distinguish corrected legacy rows from newly created rows.
    }
};

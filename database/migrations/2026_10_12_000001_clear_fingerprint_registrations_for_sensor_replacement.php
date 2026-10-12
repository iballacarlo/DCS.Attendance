<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('hardware_enrollments')
                ->where('method', 'fingerprint')
                ->whereIn('status', ['pending', 'processing'])
                ->update([
                    'status' => 'cancelled',
                    'current_identifier' => null,
                    'message' => 'Fingerprint registrations reset for sensor replacement.',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('fingerprint_templates')->delete();
        });
    }

    public function down(): void
    {
        // The explicitly requested credential deletion cannot be reversed.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('calendar_events')
            ->where('type', 'regular_holiday')
            ->update(['is_paid' => true]);
    }

    public function down(): void
    {
        // Regular holidays remain paid when rolling back this data correction.
    }
};

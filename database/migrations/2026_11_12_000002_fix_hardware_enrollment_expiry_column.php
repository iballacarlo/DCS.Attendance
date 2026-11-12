<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MySQL may silently add ON UPDATE CURRENT_TIMESTAMP to a non-null
     * TIMESTAMP column. That made expires_at reset to "now" whenever the
     * terminal claimed a request (pending -> processing), expiring it at once.
     * DATETIME has no automatic update behavior and is intentional here.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE hardware_enrollments MODIFY expires_at DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE hardware_enrollments MODIFY expires_at TIMESTAMP NOT NULL');
        }
    }
};

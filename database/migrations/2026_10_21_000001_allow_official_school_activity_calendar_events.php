<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A string keeps event types extensible while preserving all existing values.
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('type', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->enum('type', ['regular_holiday', 'special_holiday', 'asynchronous', 'suspension'])->change();
        });
    }
};

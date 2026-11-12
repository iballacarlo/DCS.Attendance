<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->enum('method', ['rfid', 'fingerprint']);
            $table->string('finger_label', 50)->nullable();
            $table->string('current_identifier', 100)->nullable();
            $table->string('identifier', 100)->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'expired'])->default('pending');
            $table->string('message')->nullable();
            // DATETIME avoids MySQL's implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_enrollments');
    }
};

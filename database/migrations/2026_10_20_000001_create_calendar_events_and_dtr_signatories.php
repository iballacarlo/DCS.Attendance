<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('total_hours', 8, 4)->default(0)->change();
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->enum('type', ['regular_holiday', 'special_holiday', 'asynchronous', 'suspension']);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_paid')->default(true);
            $table->enum('scope_type', ['campus', 'department', 'faculty'])->default('campus');
            $table->string('department')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
            $table->index(['scope_type', 'department']);
        });

        Schema::create('calendar_event_employee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unique(['calendar_event_id', 'employee_id']);
        });

        Schema::create('dtr_signatories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('designation', 100)->default('Dean / In Charge');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_signatories');
        Schema::dropIfExists('calendar_event_employee');
        Schema::dropIfExists('calendar_events');
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('total_hours', 6, 2)->default(0)->change();
        });
    }
};

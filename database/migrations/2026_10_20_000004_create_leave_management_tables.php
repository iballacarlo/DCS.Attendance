<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_remarks')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index(['status', 'start_date']);
        });

        $now = now();
        DB::table('leave_types')->insert([
            ['name' => 'Vacation Leave', 'code' => 'VL', 'is_paid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Sick Leave', 'code' => 'SL', 'is_paid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Emergency Leave', 'code' => 'EL', 'is_paid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Maternity Leave', 'code' => 'ML', 'is_paid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Paternity Leave', 'code' => 'PL', 'is_paid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Study Leave', 'code' => 'STL', 'is_paid' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Leave Without Pay', 'code' => 'LWOP', 'is_paid' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};

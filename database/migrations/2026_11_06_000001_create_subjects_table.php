<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('year_level');
            $table->string('semester', 30);
            $table->boolean('lecture_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['year_level', 'semester', 'is_active']);
        });

        $catalog = [
            [1, 'First Semester', 'COSC 50A', 'DISCRETE STRUCTURE'],
            [1, 'First Semester', 'DCIT 21A', 'INTRODUCTION TO COMPUTING'],
            [1, 'First Semester', 'DCIT 22A', 'COMPUTER PROGRAMMING 1'],
            [1, 'First Semester', 'NSTP 1', 'NATIONAL SERVICE TRAINING PROGRAM 1', true],
            [1, 'First Semester', 'CVSU 101', 'INSTITUTIONAL ORIENTATION', true],
            [1, 'Second Semester', 'DCIT 23A', 'COMPUTER PROGRAMMING 2'],
            [1, 'Second Semester', 'ITEC 50A', 'WEB SYSTEM AND TECHNOLOGIES 1'],
            [1, 'Second Semester', 'NSTP 2', 'NATIONAL SERVICE TRAINING PROGRAM 2', true],
            [2, 'First Semester', 'ITEC 55', 'PLATFORM TECHNOLOGIES'],
            [2, 'First Semester', 'DCIT 24', 'INFORMATION MANAGEMENT'],
            [2, 'First Semester', 'DCIT50', 'OBJECT ORIENTED PROGRAMMING'],
            [2, 'Second Semester', 'DCIT 25A', 'DATA STRUCTURES AND ALGORITHMS'],
            [2, 'Second Semester', 'ITEC 60A', 'INTEGRATED PROGRAMMING AND TECHNOLOGIES 1'],
            [2, 'Second Semester', 'ITEC 65A', 'OPEN SOURCE TECHNOLOGY'],
            [2, 'Second Semester', 'DCIT 55A', 'ADVANCE DATABASE MANAGEMENT SYSTEM'],
            [2, 'Second Semester', 'ITEC 70A', 'MULTIMEDIA SYSTEMS'],
            [2, 'Midyear Term', 'ITEC 75A', 'SYSTEM INTEGRATION AND ARCHITECTURE 1'],
            [3, 'First Semester', 'ITEC 80', 'INTRODUCTION TO HUMAN COMPUTER INTERACTION'],
            [3, 'First Semester', 'ITEC 85A', 'INFORMATION ASSURANCE AND SECURITY 1'],
            [3, 'First Semester', 'ITEC 90', 'NETWORK FUNDAMENTALS'],
            [3, 'First Semester', 'INSY 55', 'SYSTEM ANALYSIS AND DESIGN'],
            [3, 'First Semester', 'DCIT 26', 'APPLICATION DEVELOPMENT AND EMERGING TECHNOLOGIES'],
            [3, 'First Semester', 'DCIT 60A', 'METHODS OF RESEARCH'],
            [3, 'Second Semester', 'ITEC 95', 'QUANTITATIVE METHODS (MODELING & SIMULATION)'],
            [3, 'Second Semester', 'ITEC 101A', 'IT ELECTIVE 1 (HUMAN COMPUTER INTERACTION 2)'],
            [3, 'Second Semester', 'ITEC 106A', 'IT ELECTIVE 2 (WEB SYSTEM AND TECHNOLOGIES 2)'],
            [3, 'Second Semester', 'ITEC 100', 'INFORMATION ASSURANCE AND SECURITY 2'],
            [3, 'Second Semester', 'ITEC 105', 'NETWORK MANAGEMENT'],
            [3, 'Second Semester', 'ITEC 200-A', 'CAPSTONE PROJECT AND RESEARCH 1'],
            [4, 'First Semester', 'DCIT 65', 'SOCIAL AND PROFESSIONAL ISSUES'],
            [4, 'First Semester', 'ITEC 111', 'IT ELECTIVE 3 (INTEGRATED PROGRAMMING AND TECHNOLOGIES 2)'],
            [4, 'First Semester', 'ITEC 116', 'IT ELECTIVE 4 (SYSTEMS INTEGRATION AND ARCHITECTURE 2)'],
            [4, 'First Semester', 'ITEC 110', 'SYSTEMS ADMINISTRATION AND MAINTENANCE'],
            [4, 'First Semester', 'ITEC 200-B', 'CAPSTONE PROJECT AND RESEARCH 2'],
            [4, 'Second Semester', 'ITEC 199', 'PRACTICUM'],
        ];

        $now = now();
        DB::table('subjects')->insert(array_map(fn (array $subject) => [
            'year_level' => $subject[0],
            'semester' => $subject[1],
            'code' => $subject[2],
            'name' => $subject[3],
            'lecture_only' => $subject[4] ?? false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $catalog));
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};

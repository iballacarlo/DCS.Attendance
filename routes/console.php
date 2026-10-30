<?php

use App\Services\SemesterLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('semesters:sync', function () {
    $changes = app(SemesterLifecycleService::class)->synchronize();
    $this->info("Semester lifecycle synchronized: {$changes} changes.");
})->purpose('Start scheduled semesters and end elapsed semesters using Asia/Manila dates');

Schedule::command('semesters:sync')->everyMinute()->withoutOverlapping();

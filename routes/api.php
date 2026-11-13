<?php

use App\Http\Controllers\HardwareAttendanceController;
use Illuminate\Support\Facades\Route;

Route::post('hardware/tap', [HardwareAttendanceController::class, 'tap'])
    ->middleware('throttle:60,1');
Route::get('hardware/enrollment', [HardwareAttendanceController::class, 'pendingEnrollment'])
    // Multiple terminals may share the same public IP.  The device code and
    // hardware key are still required by the controller; use a higher
    // ceiling here so their independent polling loops do not throttle one
    // another and hide a cancellation command.
    ->middleware('throttle:600,1');
Route::get('hardware/fingerprint-slots', [HardwareAttendanceController::class, 'fingerprintSlots'])
    ->middleware('throttle:30,1');
Route::get('hardware/rfid-cards', [HardwareAttendanceController::class, 'rfidCards'])
    ->middleware('throttle:30,1');
Route::post('hardware/enrollments/{enrollment}/result', [HardwareAttendanceController::class, 'completeEnrollment'])
    ->middleware('throttle:30,1');
Route::get('hardware/enrollments/{enrollment}/status', [HardwareAttendanceController::class, 'enrollmentStatus'])
    ->middleware('throttle:600,1');

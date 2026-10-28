<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ClassReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DtrController;
use App\Http\Controllers\DtrSignatoryController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeCredentialController;
use App\Http\Controllers\EntranceAttendanceController;
use App\Http\Controllers\FacultyArchiveController;
use App\Http\Controllers\FacultyRankController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.store');
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])
        ->middleware('throttle:3,10')
        ->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::get('email/verify/{user}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:3,10')
        ->name('verification.send');

    Route::get('archived-faculty', [FacultyArchiveController::class, 'index'])->name('faculty.archive.index')->middleware('role:admin');
    Route::post('employees/archive', [FacultyArchiveController::class, 'store'])->name('faculty.archive.store')->middleware('role:admin');
    Route::post('archived-faculty/{archivedId}/restore', [FacultyArchiveController::class, 'restore'])->whereNumber('archivedId')->name('faculty.archive.restore')->middleware('role:admin');
    Route::resource('employees', EmployeeController::class)->except(['show'])->middleware('role:admin');
    Route::post('employees/{employee}/credentials/enrollments', [EmployeeCredentialController::class, 'store'])->name('employees.credentials.enrollments.store')->middleware('role:admin');
    Route::post('employees/{employee}/credentials/rfid/nfc', [EmployeeCredentialController::class, 'registerNfc'])->name('employees.credentials.rfid.nfc')->middleware(['role:admin', 'throttle:20,1']);
    Route::post('employees/{employee}/credentials/{method}/unlink', [EmployeeCredentialController::class, 'unlink'])->name('employees.credentials.unlink')->middleware('role:admin');
    Route::get('employees/{employee}/credentials/enrollments/{enrollment}', [EmployeeCredentialController::class, 'show'])->name('employees.credentials.enrollments.show')->middleware('role:admin');
    Route::delete('employees/{employee}/credentials/enrollments/{enrollment}', [EmployeeCredentialController::class, 'destroy'])->name('employees.credentials.enrollments.destroy')->middleware('role:admin');
    Route::post('employees/{employee}/email-verification', [EmailVerificationController::class, 'resendForEmployee'])->name('employees.email-verification.send')->middleware(['role:admin', 'throttle:5,10']);
    Route::get('employees/{employee}/schedule', [EmployeeController::class, 'editSchedule'])->name('employees.schedule.edit')->middleware('role:admin');
    Route::put('employees/{employee}/schedule', [EmployeeController::class, 'updateSchedule'])->name('employees.schedule.update')->middleware('role:admin');
    Route::get('faculty-ranks', [FacultyRankController::class, 'index'])->name('ranks.index')->middleware('role:admin');
    Route::redirect('settings', '/settings/accounts')->middleware('role:admin');
    Route::get('settings/general', [GeneralSettingsController::class, 'edit'])->name('settings.general.edit')->middleware('role:admin');
    Route::get('settings/holidays-suspensions', [CalendarController::class, 'index'])->name('settings.holidays.index')->middleware('role:admin');
    Route::put('settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update')->middleware('role:admin');
    Route::resource('settings/accounts', UserController::class)->except(['show'])->parameters(['accounts' => 'user'])->names([
        'index' => 'settings.accounts.index', 'create' => 'settings.accounts.create',
        'store' => 'settings.accounts.store', 'edit' => 'settings.accounts.edit',
        'update' => 'settings.accounts.update', 'destroy' => 'settings.accounts.destroy',
    ])->middleware('role:admin');
    Route::get('settings/backup', [BackupController::class, 'index'])->name('settings.backup.index')->middleware('role:admin');
    Route::get('settings/backup/download', [BackupController::class, 'download'])->name('settings.backup.download')->middleware('role:admin');
    Route::post('settings/backup/restore', [BackupController::class, 'restore'])->name('settings.backup.restore')->middleware('role:admin');
    Route::get('settings/dtr-signatories', [DtrSignatoryController::class, 'index'])->name('settings.dtr-signatories.index')->middleware('role:admin');
    Route::post('settings/dtr-signatories', [DtrSignatoryController::class, 'store'])->name('settings.dtr-signatories.store')->middleware('role:admin');
    Route::put('settings/dtr-signatories/{dtrSignatory}', [DtrSignatoryController::class, 'update'])->name('settings.dtr-signatories.update')->middleware('role:admin');
    Route::delete('settings/dtr-signatories/{dtrSignatory}', [DtrSignatoryController::class, 'destroy'])->name('settings.dtr-signatories.destroy')->middleware('role:admin');

    Route::get('my-schedule', [EmployeeController::class, 'mySchedule'])->name('faculty.schedule')->middleware('role:faculty');
    Route::get('entrance', [EntranceAttendanceController::class, 'index'])->name('entrance.index');
    Route::get('entrance-kiosk', [EntranceAttendanceController::class, 'kiosk'])->name('entrance.kiosk')->middleware('role:guard');
    Route::post('entrance-kiosk', [EntranceAttendanceController::class, 'store'])->name('entrance.kiosk.store')->middleware(['role:guard', 'throttle:60,1']);
    Route::post('entrance-kiosk/preview', [EntranceAttendanceController::class, 'preview'])->name('entrance.kiosk.preview')->middleware(['role:guard', 'throttle:120,1']);
    Route::get('class-reports', [ClassReportController::class, 'index'])->name('class-reports.index')->middleware('role:admin');
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/dtr', [DtrController::class, 'show'])->name('attendance.dtr');

    Route::get('school-calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('school-calendar/events', [CalendarController::class, 'store'])->name('calendar.events.store')->middleware('role:admin');
    Route::put('school-calendar/events/{calendarEvent}', [CalendarController::class, 'update'])->name('calendar.events.update')->middleware('role:admin');
    Route::delete('school-calendar/events/{calendarEvent}', [CalendarController::class, 'destroy'])->name('calendar.events.destroy')->middleware('role:admin');
    Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveController::class, 'store'])->name('leave.store')->middleware('role:faculty');
    Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel')->middleware('role:faculty');
    Route::put('leave/{leaveRequest}/review', [LeaveController::class, 'review'])->name('leave.review')->middleware('role:admin');

});

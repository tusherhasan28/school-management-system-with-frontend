<?php

use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\InvitationController;
use App\Http\Controllers\Tenant\PublicHomeController;
use App\Modules\Academics\Http\Controllers\AcademicsController;
use App\Modules\Academics\Http\Controllers\ClassSubjectController;
use App\Modules\Admissions\Http\Controllers\AdmissionController;
use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Homework\Http\Controllers\HomeworkController;
use App\Modules\Admissions\Http\Controllers\PublicAdmissionController;
use App\Modules\Imports\Http\Controllers\ImportController;
use App\Modules\People\Http\Controllers\GuardianController;
use App\Modules\People\Http\Controllers\PromotionController;
use App\Modules\People\Http\Controllers\StaffController;
use App\Modules\People\Http\Controllers\StudentController;
use App\Modules\Setup\Http\Controllers\CampusController;
use App\Modules\Setup\Http\Controllers\SetupController;
use App\Modules\Teaching\Http\Controllers\AssignmentController;
use App\Modules\Teaching\Http\Controllers\CalendarController;
use App\Modules\Teaching\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

/*
| {school}.APP_DOMAIN — one school. Middleware: tenant → web → tenant.session.
|
| Namespaces (see docs/architecture.md §2):
|   /, /about, /admission, /notices, /contact   public website
|   /login, /forgot-password, /invitation/...   tenant auth
|   /app/*                                      school administration
|   /teacher/*, /student/*, /parent/*           portals (Milestone 6)
|   /settings/*                                 personal account settings
*/

Route::get('/', PublicHomeController::class)->name('home');
Route::get('admission', [PublicAdmissionController::class, 'create'])->name('public.admission');
Route::post('admission', [PublicAdmissionController::class, 'store'])->middleware('throttle:public-forms')->name('public.admission.store');

// Fortify (login, password reset, 2FA, email verification) for tenant users only.
require base_path('vendor/laravel/fortify/routes/routes.php');

Route::middleware('guest:web')->group(function () {
    Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitation/{token}', [InvitationController::class, 'store'])->middleware('throttle:6,1')->name('invitation.accept');
});

Route::middleware(['auth:web', 'active', 'verified'])->prefix('app')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::controller(SetupController::class)->prefix('setup')->name('setup.')->group(function () {
        Route::get('/', 'show')->name('show');
        Route::put('profile', 'profile')->name('profile');
        Route::post('session', 'session')->name('session');
        Route::post('classes', 'classes')->name('classes');
        Route::post('subjects', 'subjects')->name('subjects');
        Route::post('finish', 'finish')->name('finish');
    });

    Route::get('campuses', [CampusController::class, 'index'])->name('campuses.index');
    Route::post('campuses', [CampusController::class, 'store'])->name('campuses.store');
    Route::get('campuses/{campus}', [CampusController::class, 'show'])->name('campuses.show');
    Route::put('campuses/{campus}', [CampusController::class, 'update'])->name('campuses.update');
    Route::delete('campuses/{campus}', [CampusController::class, 'destroy'])->name('campuses.destroy');

    Route::controller(AcademicsController::class)->prefix('academics')->name('academics.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('sessions', 'storeSession')->name('sessions.store');
        Route::put('sessions/{session}', 'updateSession')->name('sessions.update');
        Route::post('sessions/{session}/current', 'makeCurrent')->name('sessions.current');
        Route::post('classes', 'storeClass')->name('classes.store');
        Route::put('classes/{class}', 'updateClass')->name('classes.update');
        Route::delete('classes/{class}', 'destroyClass')->name('classes.destroy');
        Route::post('classes/{class}/sections', 'storeSection')->name('sections.store');
        Route::put('sections/{section}', 'updateSection')->name('sections.update');
        Route::delete('sections/{section}', 'destroySection')->name('sections.destroy');
        Route::post('subjects', 'storeSubject')->name('subjects.store');
        Route::put('subjects/{subject}', 'updateSubject')->name('subjects.update');
        Route::delete('subjects/{subject}', 'destroySubject')->name('subjects.destroy');
    });
    Route::get('academics/classes/{class}/subjects', [ClassSubjectController::class, 'edit'])->name('academics.class-subjects.edit');
    Route::put('academics/classes/{class}/subjects', [ClassSubjectController::class, 'update'])->name('academics.class-subjects.update');

    Route::get('students/promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::post('students/promotions', [PromotionController::class, 'store'])->name('promotions.store');
    Route::resource('students', StudentController::class)->except('destroy');
    Route::post('students/{student}/guardians', [StudentController::class, 'linkGuardian'])->name('students.guardians.store');
    Route::delete('students/{student}/guardians/{guardian}', [StudentController::class, 'unlinkGuardian'])->name('students.guardians.destroy');
    Route::post('students/{student}/status', [StudentController::class, 'changeStatus'])->name('students.status');

    Route::get('guardians', [GuardianController::class, 'index'])->name('guardians.index');
    Route::get('guardians/{guardian}', [GuardianController::class, 'show'])->name('guardians.show');
    Route::put('guardians/{guardian}', [GuardianController::class, 'update'])->name('guardians.update');

    Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'staff']);
    Route::post('staff/{staff}/login', [StaffController::class, 'createLogin'])->name('staff.login');

    Route::get('admissions', [AdmissionController::class, 'index'])->name('admissions.index');
    Route::get('admissions/{admission}', [AdmissionController::class, 'show'])->name('admissions.show');
    Route::post('admissions/{admission}/review', [AdmissionController::class, 'review'])->name('admissions.review');
    Route::post('admissions/{admission}/convert', [AdmissionController::class, 'convert'])->name('admissions.convert');

    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
    Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');
    Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{import}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
    Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->name('imports.errors');

    // Milestone 3: academic operations
    Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');

    Route::get('timetable', [TimetableController::class, 'index'])->name('timetable.index');
    Route::get('timetable/mine', [TimetableController::class, 'mine'])->name('timetable.mine');
    Route::post('timetable', [TimetableController::class, 'store'])->name('timetable.store');
    Route::put('timetable/{routine}', [TimetableController::class, 'update'])->name('timetable.update');
    Route::delete('timetable/{routine}', [TimetableController::class, 'destroy'])->name('timetable.destroy');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('calendar/holidays', [CalendarController::class, 'storeHoliday'])->name('calendar.holidays.store');
    Route::put('calendar/holidays/{holiday}', [CalendarController::class, 'updateHoliday'])->name('calendar.holidays.update');
    Route::delete('calendar/holidays/{holiday}', [CalendarController::class, 'destroyHoliday'])->name('calendar.holidays.destroy');
    Route::put('calendar/settings', [CalendarController::class, 'updateSettings'])->name('calendar.settings');

    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/take', [AttendanceController::class, 'take'])->name('attendance.take');
    Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::post('attendance/marks/{mark}/correct', [AttendanceController::class, 'correct'])->name('attendance.correct');
    Route::get('attendance/report', [AttendanceController::class, 'report'])->name('attendance.report');

    Route::get('homework', [HomeworkController::class, 'index'])->name('homework.index');
    Route::get('homework/create', [HomeworkController::class, 'create'])->name('homework.create');
    Route::post('homework', [HomeworkController::class, 'store'])->name('homework.store');
    Route::get('homework/{homework}', [HomeworkController::class, 'show'])->name('homework.show');
    Route::post('homework/{homework}', [HomeworkController::class, 'update'])->name('homework.update'); // POST: multipart with file
    Route::post('homework/{homework}/grade', [HomeworkController::class, 'grade'])->name('homework.grade');
    Route::get('homework/{homework}/attachment', [HomeworkController::class, 'attachment'])->name('homework.attachment');
});

require __DIR__.'/settings.php';

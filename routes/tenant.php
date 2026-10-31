<?php

use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\InvitationController;
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
use App\Modules\Exams\Http\Controllers\ExamController;
use App\Modules\Exams\Http\Controllers\ExamSetupController;
use App\Modules\Exams\Http\Controllers\MarkSheetController;
use App\Modules\Exams\Http\Controllers\ResultController;
use App\Modules\Finance\Http\Controllers\FeeSetupController;
use App\Modules\Finance\Http\Controllers\FinanceDashboardController;
use App\Modules\Finance\Http\Controllers\FinanceReportController;
use App\Modules\Finance\Http\Controllers\GatewayController;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\StudentFeeController;
use App\Modules\Setup\Http\Controllers\SetupController;
use App\Modules\Notices\Http\Controllers\NoticeController;
use App\Modules\Website\Http\Controllers\PublicSiteController;
use App\Modules\Website\Http\Controllers\WebsiteAdminController;
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

// Public website (server-rendered Blade; public data only).
Route::controller(PublicSiteController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('about', 'about')->name('site.about');
    Route::get('academics', 'academics')->name('site.academics');
    Route::get('teachers', 'teachers')->name('site.teachers');
    Route::get('notices', 'notices')->name('site.notices');
    Route::get('notices/{notice}', 'notice')->whereNumber('notice')->name('site.notice');
    Route::get('notices/{notice}/attachment', 'noticeAttachment')->whereNumber('notice')->name('site.notice.attachment');
    Route::get('events', 'events')->name('site.events');
    Route::get('gallery', 'gallery')->name('site.gallery');
    Route::get('gallery/{album}', 'album')->whereNumber('album')->name('site.album');
    Route::get('contact', 'contact')->name('site.contact');
    Route::get('p/{slug}', 'customPage')->where('slug', '[a-z0-9\-]+')->name('site.page');
    Route::get('media/{path}', 'media')->where('path', '.+')->name('site.media');
    Route::get('sitemap.xml', 'sitemap')->name('site.sitemap');
    Route::get('robots.txt', 'robots')->name('site.robots');
});
Route::get('admission', [PublicAdmissionController::class, 'create'])->name('public.admission');
Route::post('admission', [PublicAdmissionController::class, 'store'])->middleware('throttle:public-forms')->name('public.admission.store');

// Online payments: provider notifications (signature-verified, CSRF-exempt) and the payer's return page.
Route::post('payments/callback/{gateway}', [GatewayController::class, 'callback'])->middleware('throttle:120,1')->name('payments.callback');
Route::get('payments/return/{gateway}', [GatewayController::class, 'return'])->middleware('throttle:60,1')->name('payments.return');

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

    // Milestone 4: examinations and results
    Route::get('exam-setup', [ExamSetupController::class, 'index'])->name('exams.setup');
    Route::post('exam-types', [ExamSetupController::class, 'storeType'])->name('exam-types.store');
    Route::put('exam-types/{type}', [ExamSetupController::class, 'updateType'])->name('exam-types.update');
    Route::delete('exam-types/{type}', [ExamSetupController::class, 'destroyType'])->name('exam-types.destroy');
    Route::post('grading-scales', [ExamSetupController::class, 'storeScale'])->name('grading-scales.store');
    Route::get('grading-scales/{scale}', [ExamSetupController::class, 'editScale'])->name('grading-scales.edit');
    Route::put('grading-scales/{scale}', [ExamSetupController::class, 'updateScale'])->name('grading-scales.update');
    Route::post('grading-scales/{scale}/duplicate', [ExamSetupController::class, 'duplicateScale'])->name('grading-scales.duplicate');
    Route::post('grading-scales/{scale}/default', [ExamSetupController::class, 'defaultScale'])->name('grading-scales.default');
    Route::delete('grading-scales/{scale}', [ExamSetupController::class, 'destroyScale'])->name('grading-scales.destroy');

    Route::get('exams', [ExamController::class, 'index'])->name('exams.index');
    Route::post('exams', [ExamController::class, 'store'])->name('exams.store');
    Route::get('exams/{exam}', [ExamController::class, 'show'])->name('exams.show');
    Route::put('exams/{exam}', [ExamController::class, 'update'])->name('exams.update');
    Route::post('exams/{exam}/subjects', [ExamController::class, 'addSubjects'])->name('exams.subjects.store');
    Route::put('exam-subjects/{subject}', [ExamController::class, 'updateSubject'])->name('exams.subjects.update');
    Route::delete('exam-subjects/{subject}', [ExamController::class, 'destroySubject'])->name('exams.subjects.destroy');
    Route::post('exams/{exam}/open', [ExamController::class, 'open'])->name('exams.open');
    Route::post('exams/{exam}/calculate', [ExamController::class, 'calculate'])->name('exams.calculate');
    Route::post('exams/{exam}/approve', [ExamController::class, 'approve'])->name('exams.approve');
    Route::post('exams/{exam}/publish', [ExamController::class, 'publish'])->name('exams.publish');
    Route::post('exams/{exam}/reopen', [ExamController::class, 'reopen'])->name('exams.reopen');
    Route::post('exams/{exam}/verify-all', [MarkSheetController::class, 'verifyAll'])->name('exams.verify-all');
    Route::get('exams/{exam}/results', [ResultController::class, 'index'])->name('exams.results');
    Route::get('exams/{exam}/report-cards', [ResultController::class, 'reportCards'])->name('exams.report-cards');

    Route::get('marks', [MarkSheetController::class, 'index'])->name('marks.index');
    Route::get('marks/{sheet}', [MarkSheetController::class, 'show'])->name('marks.show');
    Route::post('marks/{sheet}', [MarkSheetController::class, 'save'])->name('marks.save');
    Route::post('marks/{sheet}/verify', [MarkSheetController::class, 'verify'])->name('marks.verify');
    Route::post('marks/{sheet}/return', [MarkSheetController::class, 'returnSheet'])->name('marks.return');

    // Milestone 5: fees and payments
    Route::prefix('fees')->name('fees.')->group(function () {
        Route::get('/', FinanceDashboardController::class)->name('dashboard');
        Route::get('setup', [FeeSetupController::class, 'index'])->name('setup');
        Route::post('categories', [FeeSetupController::class, 'storeCategory'])->name('categories.store');
        Route::put('categories/{category}', [FeeSetupController::class, 'updateCategory'])->name('categories.update');
        Route::post('structures', [FeeSetupController::class, 'storeStructure'])->name('structures.store');
        Route::put('structures/{structure}', [FeeSetupController::class, 'updateStructure'])->name('structures.update');
        Route::put('settings', [FeeSetupController::class, 'updateSettings'])->name('settings');
        Route::put('gateways/{gateway}', [FeeSetupController::class, 'saveGateway'])->name('gateways.save');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices/generate', [InvoiceController::class, 'generate'])->middleware('throttle:10,1')->name('invoices.generate');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
        Route::post('invoices/{invoice}/lines', [InvoiceController::class, 'addLine'])->name('invoices.lines');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');

        Route::get('students/{student}', [StudentFeeController::class, 'show'])->name('students.show');
        Route::post('students/{student}/invoices', [InvoiceController::class, 'store'])->name('students.invoices.store');
        Route::post('students/{student}/payments', [PaymentController::class, 'store'])->middleware('throttle:30,1')->name('students.payments.store');
        Route::post('students/{student}/assignments', [StudentFeeController::class, 'storeAssignment'])->name('students.assignments.store');
        Route::post('students/{student}/discounts', [StudentFeeController::class, 'storeDiscount'])->name('students.discounts.store');
        Route::post('assignments/{assignment}/toggle', [StudentFeeController::class, 'toggleAssignment'])->name('assignments.toggle');
        Route::post('discounts/{discount}/toggle', [StudentFeeController::class, 'toggleDiscount'])->name('discounts.toggle');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');

        Route::get('reports', [FinanceReportController::class, 'index'])->name('reports');
        Route::get('reports/{report}/export', [FinanceReportController::class, 'export'])->name('reports.export');
    });

    // Milestone 6: website builder, notices and events
    Route::prefix('website')->name('website.')->controller(WebsiteAdminController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::put('/', 'update')->name('update');
        Route::post('images/{slot}', 'uploadImage')->whereIn('slot', ['logo', 'hero', 'principal'])->name('images.upload');
        Route::delete('images/{slot}', 'removeImage')->whereIn('slot', ['logo', 'hero', 'principal'])->name('images.remove');
        Route::put('teachers', 'teachers')->name('teachers');
        Route::get('pages', 'pages')->name('pages');
        Route::get('pages/create', 'editPage')->name('pages.create');
        Route::post('pages', 'savePage')->name('pages.store');
        Route::get('pages/{page}', 'editPage')->name('pages.edit');
        Route::put('pages/{page}', 'savePage')->name('pages.update');
        Route::delete('pages/{page}', 'destroyPage')->name('pages.destroy');
        Route::get('menu', 'menu')->name('menu');
        Route::post('menu', 'storeMenuItem')->name('menu.store');
        Route::put('menu/{item}', 'updateMenuItem')->name('menu.update');
        Route::delete('menu/{item}', 'destroyMenuItem')->name('menu.destroy');
        Route::get('gallery', 'gallery')->name('gallery');
        Route::post('albums', 'storeAlbum')->name('albums.store');
        Route::get('albums/{album}', 'album')->name('albums.show');
        Route::put('albums/{album}', 'updateAlbum')->name('albums.update');
        Route::delete('albums/{album}', 'destroyAlbum')->name('albums.destroy');
        Route::post('albums/{album}/photos', 'uploadPhotos')->middleware('throttle:30,1')->name('albums.photos');
        Route::put('photos/{photo}', 'updatePhoto')->name('photos.update');
        Route::delete('photos/{photo}', 'destroyPhoto')->name('photos.destroy');
    });

    Route::get('notices', [NoticeController::class, 'index'])->name('notices.index');
    Route::get('notices/create', [NoticeController::class, 'create'])->name('notices.create');
    Route::post('notices', [NoticeController::class, 'store'])->name('notices.store');
    Route::get('notices/{notice}/edit', [NoticeController::class, 'edit'])->name('notices.edit');
    Route::post('notices/{notice}', [NoticeController::class, 'update'])->name('notices.update'); // POST: multipart
    Route::delete('notices/{notice}', [NoticeController::class, 'destroy'])->name('notices.destroy');
    Route::get('notices/{notice}/attachment', [NoticeController::class, 'attachment'])->name('notices.attachment');
    Route::post('events', [NoticeController::class, 'saveEvent'])->name('events.store');
    Route::put('events/{event}', [NoticeController::class, 'saveEvent'])->name('events.update');
    Route::delete('events/{event}', [NoticeController::class, 'destroyEvent'])->name('events.destroy');
});

require __DIR__.'/settings.php';

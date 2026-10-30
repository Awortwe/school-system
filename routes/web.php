<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\CashbookController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\InvoiceAdjustmentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentFinanceController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:10,1')->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('change-password', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('change-password', [ChangePasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'staff.active', 'password.changed', 'school.scope'])->group(function () {
    require __DIR__.'/modules.php';
    Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

    Route::prefix('students')->name('students.')->group(function () {
        Route::get('/', [StudentController::class, 'index'])->middleware('permission:students.view')->name('index');
        Route::get('create', [StudentController::class, 'create'])->middleware('permission:students.create')->name('create');
        Route::post('/', [StudentController::class, 'store'])->middleware('permission:students.create')->name('store');
        Route::get('{student}', [StudentController::class, 'show'])->middleware('permission:students.view')->name('show');
        Route::get('{student}/edit', [StudentController::class, 'edit'])->middleware('permission:students.update')->name('edit');
        Route::put('{student}', [StudentController::class, 'update'])->middleware('permission:students.update')->name('update');
        Route::delete('{student}', [StudentController::class, 'destroy'])->middleware('permission:students.delete')->name('destroy');

        Route::post('{student}/guardians', [GuardianController::class, 'store'])->middleware('permission:guardians.create')->name('guardians.store');
        Route::put('{student}/guardians/{guardian}', [GuardianController::class, 'update'])->middleware('permission:guardians.update')->name('guardians.update');
        Route::delete('{student}/guardians/{guardian}', [GuardianController::class, 'destroy'])->middleware('permission:guardians.update')->name('guardians.destroy');

        Route::get('{student}/statement', [StudentFinanceController::class, 'statement'])->middleware('permission:reports.financial')->name('statement');
        Route::get('{student}/statement/pdf', [StudentFinanceController::class, 'statementPdf'])->middleware('permission:reports.financial')->name('statement.pdf');
    });

    Route::prefix('admissions')->name('admissions.')->group(function () {
        Route::get('/', [AdmissionController::class, 'index'])->middleware('permission:admissions.view')->name('index');
        Route::get('create', [AdmissionController::class, 'create'])->middleware('permission:admissions.create')->name('create');
        Route::post('/', [AdmissionController::class, 'store'])->middleware('permission:admissions.create')->name('store');
        Route::get('{admission}', [AdmissionController::class, 'show'])->middleware('permission:admissions.view')->name('show');
        Route::put('{admission}', [AdmissionController::class, 'update'])->middleware('permission:admissions.update')->name('update');
        Route::post('{admission}/decide', [AdmissionController::class, 'decide'])->middleware('permission:admissions.approve')->name('decide');
        Route::post('{admission}/convert', [AdmissionController::class, 'convert'])->middleware('permission:admissions.approve')->name('convert');
    });

    Route::prefix('academics')->name('academics.')->group(function () {
        Route::get('assessment', [\App\Http\Controllers\AssessmentController::class, 'index'])->middleware('permission:academics.view');
        Route::post('assessment', [\App\Http\Controllers\AssessmentController::class, 'store'])->middleware('permission:academics.manage');
        Route::get('/', [AcademicController::class, 'index'])->middleware('permission:academics.view')->name('index');

        Route::post('years', [AcademicController::class, 'storeYear'])->middleware('permission:academics.manage')->name('years.store');
        Route::put('years/{academicYear}', [AcademicController::class, 'updateYear'])->middleware('permission:academics.manage')->name('years.update');
        Route::post('years/{academicYear}/current', [AcademicController::class, 'currentYear'])->middleware('permission:academics.manage')->name('years.current');
        Route::delete('years/{academicYear}', [AcademicController::class, 'destroyYear'])->middleware('permission:academics.manage')->name('years.destroy');

        Route::post('terms', [AcademicController::class, 'storeTerm'])->middleware('permission:academics.manage')->name('terms.store');
        Route::put('terms/{term}', [AcademicController::class, 'updateTerm'])->middleware('permission:academics.manage')->name('terms.update');
        Route::post('terms/{term}/current', [AcademicController::class, 'currentTerm'])->middleware('permission:academics.manage')->name('terms.current');
        Route::delete('terms/{term}', [AcademicController::class, 'destroyTerm'])->middleware('permission:academics.manage')->name('terms.destroy');

        Route::post('levels', [AcademicController::class, 'storeLevel'])->middleware('permission:academics.manage')->name('levels.store');
        Route::put('levels/{level}', [AcademicController::class, 'updateLevel'])->middleware('permission:academics.manage')->name('levels.update');
        Route::delete('levels/{level}', [AcademicController::class, 'destroyLevel'])->middleware('permission:academics.manage')->name('levels.destroy');

        Route::post('classes', [AcademicController::class, 'storeClass'])->middleware('permission:classes.manage')->name('classes.store');
        Route::put('classes/{schoolClass}', [AcademicController::class, 'updateClass'])->middleware('permission:classes.manage')->name('classes.update');
        Route::delete('classes/{schoolClass}', [AcademicController::class, 'destroyClass'])->middleware('permission:classes.manage')->name('classes.destroy');

        Route::post('sections', [AcademicController::class, 'storeSection'])->middleware('permission:classes.manage')->name('sections.store');
        Route::put('sections/{section}', [AcademicController::class, 'updateSection'])->middleware('permission:classes.manage')->name('sections.update');
        Route::delete('sections/{section}', [AcademicController::class, 'destroySection'])->middleware('permission:classes.manage')->name('sections.destroy');

        Route::post('subjects', [AcademicController::class, 'storeSubject'])->middleware('permission:subjects.manage')->name('subjects.store');
        Route::put('subjects/{subject}', [AcademicController::class, 'updateSubject'])->middleware('permission:subjects.manage')->name('subjects.update');
        Route::delete('subjects/{subject}', [AcademicController::class, 'destroySubject'])->middleware('permission:subjects.manage')->name('subjects.destroy');

        Route::post('class-subjects', [AcademicController::class, 'storeClassSubject'])->middleware('permission:subjects.manage')->name('class-subjects.store');
        Route::delete('class-subjects/{classSubject}', [AcademicController::class, 'destroyClassSubject'])->middleware('permission:subjects.manage')->name('class-subjects.destroy');
    });

    Route::get('timetable', [TimetableController::class, 'index'])->middleware('permission:timetable.view')->name('timetable.index');
    Route::post('timetable', [TimetableController::class, 'store'])->middleware('permission:timetable.manage')->name('timetable.store');
    Route::post('timetable/periods', [TimetableController::class, 'storePeriod'])->middleware('permission:timetable.manage')->name('timetable.periods.store');
    Route::delete('timetable/periods/{period}', [TimetableController::class, 'destroyPeriod'])->middleware('permission:timetable.manage')->name('timetable.periods.destroy');

    Route::get('attendance', [AttendanceController::class, 'index'])->middleware('permission:attendance.view')->name('attendance.index');
    Route::post('attendance', [AttendanceController::class, 'store'])->middleware('permission:attendance.record')->name('attendance.store');

    Route::prefix('results')->name('results.')->group(function () {
        Route::get('/', [ResultsController::class, 'index'])->middleware('permission:results.view')->name('index');
        Route::post('generate', [ResultsController::class, 'generate'])->middleware('permission:results.enter')->name('generate');
        Route::get('reports', [ResultsController::class, 'reports'])->middleware('permission:results.view')->name('reports');
        Route::post('publish', [ResultsController::class, 'publish'])->middleware('permission:results.publish')->name('publish');
        Route::get('{sheet}', [ResultsController::class, 'show'])->middleware('permission:results.view')->name('show');
        Route::put('{sheet}/scores', [ResultsController::class, 'updateScores'])->middleware('permission:results.enter')->name('scores');
        Route::post('{sheet}/submit', [ResultsController::class, 'submit'])->middleware('permission:results.submit')->name('submit');
        Route::post('{sheet}/approve', [ResultsController::class, 'approve'])->middleware('permission:results.approve')->name('approve');
        Route::post('{sheet}/reopen', [ResultsController::class, 'reopen'])->middleware('permission:results.reopen')->name('reopen');
    });

    Route::prefix('report-cards')->name('report-cards.')->group(function () {
        Route::get('{student}/{term}/preview', [ReportCardController::class, 'preview'])->middleware('permission:results.view')->name('preview');
        Route::get('published/{report}', [ReportCardController::class, 'published'])->middleware('permission:results.view')->name('published');
        Route::get('published/{report}/download', [ReportCardController::class, 'download'])->middleware('permission:results.view')->name('download');
        Route::get('classes/{section}/{term}', [ReportCardController::class, 'batch'])->middleware('permission:results.view')->name('batch');
    });

    Route::prefix('finance')->name('finance.')->group(function () {
        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view')->name('invoices.index');
        Route::get('invoices/create', [InvoiceController::class, 'create'])->middleware('permission:invoices.create')->name('invoices.create');
        Route::post('invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.create')->name('invoices.store');
        Route::post('invoices/bulk', [InvoiceController::class, 'bulk'])->middleware('permission:invoices.create')->name('invoices.bulk');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view')->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('permission:invoices.view')->name('invoices.pdf');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:invoices.void')->name('invoices.void');
        Route::post('invoices/{invoice}/adjustment', [InvoiceController::class, 'adjustment'])->middleware('permission:adjustments.request')->name('invoices.adjustment');

        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view')->name('payments.index');
        Route::get('payments/create', [PaymentController::class, 'create'])->middleware('permission:payments.record')->name('payments.create');
        Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:payments.record')->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view')->name('payments.show');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('permission:payments.view')->name('payments.receipt');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:payments.reverse')->name('payments.reverse');

        Route::get('fees', [FeeController::class, 'index'])->middleware('permission:fees.view')->name('fees.index');
        Route::post('fees/categories', [FeeController::class, 'storeCategory'])->middleware('permission:fees.manage')->name('fees.categories.store');
        Route::post('fees/items', [FeeController::class, 'storeItem'])->middleware('permission:fees.manage')->name('fees.items.store');
        Route::put('fees/items/{feeItem}', [FeeController::class, 'updateItem'])->middleware('permission:fees.manage')->name('fees.items.update');
        Route::delete('fees/items/{feeItem}', [FeeController::class, 'destroyItem'])->middleware('permission:fees.manage')->name('fees.items.destroy');
        Route::post('fees/structures', [FeeController::class, 'storeStructure'])->middleware('permission:fees.manage')->name('fees.structures.store');
        Route::put('fees/structures/{feeStructure}', [FeeController::class, 'updateStructure'])->middleware('permission:fees.manage')->name('fees.structures.update');
        Route::delete('fees/structures/{feeStructure}', [FeeController::class, 'destroyStructure'])->middleware('permission:fees.manage')->name('fees.structures.destroy');

        Route::get('adjustments', [InvoiceAdjustmentController::class, 'index'])->middleware('permission:adjustments.view')->name('adjustments.index');
        Route::post('adjustments/{adjustment}/approve', [InvoiceAdjustmentController::class, 'approve'])->middleware('permission:adjustments.approve')->name('adjustments.approve');
        Route::post('adjustments/{adjustment}/reject', [InvoiceAdjustmentController::class, 'reject'])->middleware('permission:adjustments.approve')->name('adjustments.reject');

        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view')->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create')->name('expenses.store');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:expenses.approve')->name('expenses.approve');
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.approve')->name('expenses.reject');
        Route::post('expenses/categories', [ExpenseController::class, 'storeCategory'])->middleware('permission:expenses.create')->name('expenses.categories.store');

        Route::get('income', [IncomeController::class, 'index'])->middleware('permission:incomes.view')->name('income.index');
        Route::post('income', [IncomeController::class, 'store'])->middleware('permission:incomes.create')->name('income.store');
        Route::post('income/categories', [IncomeController::class, 'storeCategory'])->middleware('permission:incomes.create')->name('income.categories.store');

        Route::get('refunds', [RefundController::class, 'index'])->middleware('permission:payments.reverse')->name('refunds.index');
        Route::post('refunds', [RefundController::class, 'store'])->middleware('permission:payments.reverse')->name('refunds.store');

        Route::get('cashbook', [CashbookController::class, 'index'])->middleware('permission:reports.financial')->name('cashbook.index');
        Route::get('reports', [FinanceReportController::class, 'index'])->middleware('permission:reports.financial')->name('reports.index');
    });

    Route::prefix('staff')->name('staff.')->group(function () {
        Route::get('/', [StaffController::class, 'index'])->middleware('permission:staff.view')->name('index');
        Route::get('create', [StaffController::class, 'create'])->middleware('permission:staff.create')->name('create');
        Route::post('/', [StaffController::class, 'store'])->middleware('permission:staff.create')->name('store');
        Route::get('{staff}/edit', [StaffController::class, 'edit'])->middleware('permission:staff.update')->name('edit');
        Route::put('{staff}', [StaffController::class, 'update'])->middleware('permission:staff.update')->name('update');
        Route::post('{staff}/toggle', [StaffController::class, 'toggle'])->middleware('permission:staff.update')->name('toggle');
        Route::post('{staff}/permissions', [StaffController::class, 'permissions'])->middleware('permission:users.manage')->name('permissions');
        Route::post('departments', [StaffController::class, 'storeDepartment'])->middleware('permission:staff.create')->name('departments.store');
        Route::post('designations', [StaffController::class, 'storeDesignation'])->middleware('permission:staff.create')->name('designations.store');
    });

    Route::get('settings', [SettingController::class, 'edit'])->middleware('permission:settings.view')->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');
    Route::post('settings', [SettingController::class, 'update'])->middleware('permission:settings.manage');
    Route::post('settings/signatures', [SettingController::class, 'storeSignature'])->middleware('permission:settings.manage')->name('settings.signatures.store');
    Route::delete('settings/signatures/{signature}', [SettingController::class, 'destroySignature'])->middleware('permission:settings.manage')->name('settings.signatures.destroy');

    Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
});

<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{PayrollController, TransportController, InventoryController};
Route::get('business-documents', [\App\Http\Controllers\BusinessDocumentController::class, 'index']);
Route::get('business-reports/{module}/{type}', [\App\Http\Controllers\BusinessReportController::class, 'export']);
Route::post('business-documents', [\App\Http\Controllers\BusinessDocumentController::class, 'store']);
Route::get('business-documents/{document}/download', [\App\Http\Controllers\BusinessDocumentController::class, 'download']);
Route::middleware(['permission:payroll.view', 'permission:salaries.view'])->prefix('payroll')->group(function () {
    Route::get('/', [PayrollController::class, 'index']);
    Route::post('profiles', [PayrollController::class, 'profile'])->middleware('permission:payroll.manage');
    Route::get('profiles/{profile}/payment-details', [PayrollController::class, 'bank'])->middleware('permission:payroll.pay');
    Route::post('components', [PayrollController::class, 'component'])->middleware('permission:payroll.manage');
    Route::post('rules', [PayrollController::class, 'rule'])->middleware('permission:payroll.approve');
    Route::post('periods', [PayrollController::class, 'period'])->middleware('permission:payroll.manage');
    Route::post('{period}/generate', [PayrollController::class, 'generate'])->middleware('permission:payroll.manage');
    Route::post('{period}/stage', [PayrollController::class, 'transition']);
    Route::get('{period}/payslips', [PayrollController::class, 'payslips']);
    Route::post('entries/{entry}/payments', [PayrollController::class, 'pay'])->middleware('permission:payroll.pay');
    Route::post('entries/{entry}/adjustments', [PayrollController::class, 'adjust'])->middleware('permission:payroll.approve');
});
Route::middleware('permission:transport.view')->prefix('transport')->group(function () {
    Route::get('/', [TransportController::class, 'index']);
    Route::post('{kind}/{record}/update', [TransportController::class, 'updateRegistry'])->middleware('permission:transport.manage');
    foreach (['vehicles' => 'vehicle', 'drivers' => 'driver', 'routes' => 'route', 'assignments' => 'assignment', 'enrollments' => 'enroll', 'fees' => 'fee', 'maintenance' => 'maintenance'] as $path => $method) Route::post($path, [TransportController::class, $method])->middleware('permission:transport.manage');
    Route::post('enrollments/{enrollment}/close', [TransportController::class, 'close'])->middleware('permission:transport.manage');
    Route::post('fees/{fee}/approve', [TransportController::class, 'approveFee'])->middleware('permission:fees.manage');
    Route::post('bill', [TransportController::class, 'bill'])->middleware('permission:invoices.create');
});
Route::middleware('permission:inventory.view')->prefix('inventory')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    Route::post('items/{item}/settings', [InventoryController::class, 'itemSettings'])->middleware('permission:inventory.manage');
    foreach (['categories' => 'category', 'locations' => 'location', 'suppliers' => 'supplier', 'items' => 'item', 'movements' => 'movement', 'purchases' => 'purchase', 'assets' => 'asset'] as $path => $method) Route::post($path, [InventoryController::class, $method])->middleware('permission:inventory.manage');
    Route::post('purchases/{purchase}/stage', [InventoryController::class, 'purchaseStage']);
    Route::post('assets/{asset}/events', [InventoryController::class, 'assetEvent'])->middleware('permission:inventory.manage');
});

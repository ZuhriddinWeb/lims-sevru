<?php

/*
|--------------------------------------------------------------------------
| Buyurtmachi bo'limlar, buyurtmachi portali va proba miqdori — API
|--------------------------------------------------------------------------
| SampleIntakeServiceProvider orqali /api prefiksi bilan yuklanadi.
| routes/api.php ga hech narsa qo'shish SHART EMAS.
*/

use App\Http\Controllers\Api\Intake\CustomerDepartmentController;
use App\Http\Controllers\Api\Intake\CustomerPortalController;
use App\Http\Controllers\Api\Intake\SampleIntakeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    /* ---------- Buyurtmachi bo'limlar katalogi ---------- */
    Route::get('/customer-departments/options', [CustomerDepartmentController::class, 'options'])
        ->middleware('permission:samples.view|samples.create|customer_departments.view');
    Route::get('/customer-departments/portal-users', [CustomerDepartmentController::class, 'portalUsers'])
        ->middleware('permission:customer_departments.manage');
    Route::get('/customer-departments', [CustomerDepartmentController::class, 'index'])
        ->middleware('permission:customer_departments.view');
    Route::post('/customer-departments', [CustomerDepartmentController::class, 'store'])
        ->middleware('permission:customer_departments.manage');
    Route::put('/customer-departments/{customerDepartment}', [CustomerDepartmentController::class, 'update'])
        ->middleware('permission:customer_departments.manage');
    Route::delete('/customer-departments/{customerDepartment}', [CustomerDepartmentController::class, 'destroy'])
        ->middleware('permission:customer_departments.manage');

    /* ---------- Proba: qabul ma'lumoti va miqdor hisobi ---------- */
    Route::get('/samples/{sample}/intake', [SampleIntakeController::class, 'show'])
        ->middleware('permission:samples.view');
    Route::put('/samples/{sample}/quantity', [SampleIntakeController::class, 'updateQuantity'])
        ->middleware('permission:sample_quantity.manage');
    Route::post('/samples/{sample}/quantity/movements', [SampleIntakeController::class, 'storeMovement'])
        ->middleware('permission:sample_quantity.manage');
    Route::delete('/samples/{sample}/quantity/movements/{movement}', [SampleIntakeController::class, 'destroyMovement'])
        ->middleware('permission:sample_quantity.manage');
    Route::get('/sample-balances', [SampleIntakeController::class, 'balances'])
        ->middleware('permission:samples.view');

    /* ---------- Buyurtmachi portali (faqat o'qish; ruxsat controller ichida) ---------- */
    Route::prefix('portal')->group(function () {
        Route::get('/context', [CustomerPortalController::class, 'context']);
        Route::get('/samples', [CustomerPortalController::class, 'samples']);
        Route::get('/samples/{sample}', [CustomerPortalController::class, 'show']);
    });
});

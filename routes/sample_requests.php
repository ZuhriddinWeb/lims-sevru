<?php

/*
|--------------------------------------------------------------------------
| Buyurtma so'rovlari (qoralama buyurtma) — API  [Paket 24]
|--------------------------------------------------------------------------
| SampleRequestServiceProvider ulaydi (/api prefiksi bilan) — routes/api.php ga tegilmaydi.
|   /api/portal/requests/*   — buyurtmachi (ruxsat controller ichida; CustomerPortalFence portal/* ni ochiq qoldiradi)
|   /api/sample-requests/*   — laboratoriya
*/

use App\Http\Controllers\Api\Intake\CustomerContactController;
use App\Http\Controllers\Api\Intake\LabScheduleController;
use App\Http\Controllers\Api\Intake\PortalScheduleController;
use App\Http\Controllers\Api\Intake\PortalScheduleSheetController;
use App\Http\Controllers\Api\Intake\ScheduleSheetController;
use App\Http\Controllers\Api\Intake\PortalRequestController;
use App\Http\Controllers\Api\Intake\PortalTemplateController;
use App\Http\Controllers\Api\Intake\SampleRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    /* ---------- Buyurtmachi: Buyurtmalarim ---------- */
    Route::prefix('portal/requests')->group(function () {
        Route::get('/options', [PortalRequestController::class, 'options']);
        Route::get('/', [PortalRequestController::class, 'index']);
        Route::post('/', [PortalRequestController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/{id}', [PortalRequestController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [PortalRequestController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PortalRequestController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/submit', [PortalRequestController::class, 'submit'])->whereNumber('id');
        Route::post('/{id}/withdraw', [PortalRequestController::class, 'withdraw'])->whereNumber('id');
        Route::post('/{id}/cancel', [PortalRequestController::class, 'cancel'])->whereNumber('id');
    });

    /* ---------- [P33] Buyurtmachi: Shablonlarim (faqat o'z bo'linmasi) ---------- */
    Route::prefix('portal/request-templates')->group(function () {
        Route::get('/', [PortalTemplateController::class, 'index']);
        Route::post('/', [PortalTemplateController::class, 'store'])->middleware('throttle:30,1');
        Route::post('/order', [PortalTemplateController::class, 'order']);
        Route::put('/{id}', [PortalTemplateController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PortalTemplateController::class, 'destroy'])->whereNumber('id');
    });

    /* ---------- [P34] Bo'linma xodimlari ma'lumotnomasi (kim olib keladi + telefon) ---------- */
    Route::prefix('portal/contacts')->group(function () {
        Route::get('/', [CustomerContactController::class, 'index']);
        Route::post('/check', [CustomerContactController::class, 'check'])->middleware('throttle:120,1');
        Route::put('/{id}', [CustomerContactController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [CustomerContactController::class, 'destroy'])->whereNumber('id');
    });

    /* ---------- [P36] Buyurtmachi: Grafigim (grafik bo'yicha namunalar) ---------- */
    Route::prefix('portal/schedules')->group(function () {
        Route::get('/', [PortalScheduleController::class, 'index']);
        Route::post('/', [PortalScheduleController::class, 'store'])->middleware('throttle:30,1');
        Route::put('/{id}', [PortalScheduleController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PortalScheduleController::class, 'destroy'])->whereNumber('id');
    });
    Route::prefix('portal/schedule-runs')->group(function () {
        Route::get('/', [PortalScheduleController::class, 'runs']);
        Route::get('/history', [PortalScheduleController::class, 'history']);
        Route::get('/{id}', [PortalScheduleController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [PortalScheduleController::class, 'customize'])->whereNumber('id');
        Route::post('/{id}/skip', [PortalScheduleController::class, 'skip'])->whereNumber('id');
        Route::post('/{id}/unskip', [PortalScheduleController::class, 'unskip'])->whereNumber('id');
        Route::post('/{id}/reset', [PortalScheduleController::class, 'reset'])->whereNumber('id');
    });

    /* ---------- [P36] Laboratoriya: Grafik bo'yicha qabul ---------- */
    Route::prefix('schedule-board')->group(function () {
        Route::get('/', [LabScheduleController::class, 'index'])->middleware('permission:schedules.receive');
        Route::post('/{id}/arrive', [LabScheduleController::class, 'arrive'])->whereNumber('id')
            ->middleware(['permission:schedules.receive', 'throttle:60,1']);
        Route::get('/settings', [LabScheduleController::class, 'settings']);
        Route::put('/settings/{departmentId}', [LabScheduleController::class, 'saveSettings'])->whereNumber('departmentId');
    });

    /* ---------- [P37] Grafik jadvallari (Excel ko'rinishida) ---------- */
    Route::prefix('portal/schedule-sheets')->group(function () {
        Route::get('/', [PortalScheduleSheetController::class, 'index']);
        Route::get('/{id}', [PortalScheduleSheetController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/export', [PortalScheduleSheetController::class, 'export'])->whereNumber('id');
    });
    Route::prefix('schedule-sheets')->middleware('permission:schedule_sheets.view|schedule_sheets.enter')->group(function () {
        Route::get('/', [ScheduleSheetController::class, 'index']);
        Route::get('/{id}', [ScheduleSheetController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/export', [ScheduleSheetController::class, 'export'])->whereNumber('id');
        Route::put('/{id}/cell', [ScheduleSheetController::class, 'cell'])->whereNumber('id')
            ->middleware(['permission:schedule_sheets.enter', 'throttle:240,1']);
    });
    Route::prefix('schedule-sheet-templates')->middleware('permission:schedule_sheets.manage')->group(function () {
        Route::get('/', [ScheduleSheetController::class, 'templates']);
        Route::get('/options', [ScheduleSheetController::class, 'options']);
        Route::post('/', [ScheduleSheetController::class, 'store']);
        Route::put('/{id}', [ScheduleSheetController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ScheduleSheetController::class, 'destroy'])->whereNumber('id');
    });

    /* ---------- Laboratoriya: Buyurtmalar ---------- */
    Route::prefix('sample-requests')->group(function () {
        Route::get('/options', [SampleRequestController::class, 'options'])
            ->middleware('permission:sample_requests.view|sample_requests.create');
        Route::get('/', [SampleRequestController::class, 'index'])
            ->middleware('permission:sample_requests.view');
        Route::post('/', [SampleRequestController::class, 'store'])
            ->middleware('permission:sample_requests.create');
        Route::get('/{id}', [SampleRequestController::class, 'show'])->whereNumber('id')
            ->middleware('permission:sample_requests.view|sample_requests.create');
        Route::put('/{id}', [SampleRequestController::class, 'update'])->whereNumber('id')
            ->middleware('permission:sample_requests.create');
        Route::delete('/{id}', [SampleRequestController::class, 'destroy'])->whereNumber('id')
            ->middleware('permission:sample_requests.create');
        Route::post('/{id}/submit', [SampleRequestController::class, 'submit'])->whereNumber('id')
            ->middleware('permission:sample_requests.create');
        Route::post('/{id}/receive', [SampleRequestController::class, 'receive'])->whereNumber('id')
            ->middleware('permission:sample_requests.receive');
        Route::post('/{id}/reject', [SampleRequestController::class, 'reject'])->whereNumber('id')
            ->middleware('permission:sample_requests.receive');
    });
});

<?php

/*
|--------------------------------------------------------------------------
| Rahbariyat paneli 2.0, smenalar, xodimlar faoliyati, ish stoli, audit jurnali, savatcha, yordamchi — API
|--------------------------------------------------------------------------
| LabInsightsServiceProvider orqali /api prefiksi bilan yuklanadi.
| routes/api.php ga hech narsa qo'shish SHART EMAS.
*/

use App\Http\Controllers\Api\Help\HelpController;
use App\Http\Controllers\Api\Insights\AuditLogController;
use App\Http\Controllers\Api\Insights\ExecutiveInsightsController;
use App\Http\Controllers\Api\Insights\LabShiftController;
use App\Http\Controllers\Api\Insights\RecycleBinController;
use App\Http\Controllers\Api\Insights\StaffActivityController;
use App\Http\Controllers\Api\Insights\WorkDeskController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    /* ---------- Rahbariyat paneli 2.0 ---------- */
    Route::get('/insights/executive', [ExecutiveInsightsController::class, 'index'])
        ->middleware('permission:executive.view')
        ->name('insights.executive');   // nomi — keshlangan marshrutlarda modul borligini tekshirish uchun

    /* ---------- [P18] Ish stoli (bosh sahifa) — kartochkalar ruxsatga qarab ---------- */
    Route::get('/insights/desk', [WorkDeskController::class, 'index']);

    /* ---------- Faol smenalar va joriy smena (barcha xodimlar uchun) ---------- */
    Route::get('/insights/shifts', [LabShiftController::class, 'active']);

    /* ---------- [P17] Xodimlar faoliyati (ruxsat — controller ichida: boshliq/rahbariyat — hamma, boshqalar — o'zi) ---------- */
    Route::get('/insights/staff', [StaffActivityController::class, 'index']);
    Route::get('/insights/staff/export', [StaffActivityController::class, 'export']);
    Route::get('/insights/staff/{user}', [StaffActivityController::class, 'show'])->whereNumber('user');

    /* ---------- [P19] Audit jurnali ---------- */
    Route::middleware('permission:logs.view')->group(function () {
        Route::get('/audit/logs', [AuditLogController::class, 'index']);
        Route::get('/audit/summary', [AuditLogController::class, 'summary']);
        Route::get('/audit/export', [AuditLogController::class, 'export']);
    });

    /* ---------- [P19] Savatcha (butunlay o'chirish yo'q — ISO/IEC 17025) ---------- */
    Route::middleware('permission:trash.view|trash.restore')->group(function () {
        Route::get('/recycle-bin', [RecycleBinController::class, 'counts']);
        Route::get('/recycle-bin/{type}', [RecycleBinController::class, 'index'])->where('type', '[a-z_]+');
        Route::get('/recycle-bin/{type}/{id}', [RecycleBinController::class, 'show'])->where('type', '[a-z_]+')->whereNumber('id');
    });
    Route::post('/recycle-bin/{type}/{id}/restore', [RecycleBinController::class, 'restore'])
        ->where('type', '[a-z_]+')->whereNumber('id')
        ->middleware('permission:trash.restore');

    /* ---------- [P21] Yordamchi (lokal AI): barcha tizimga kirganlar; javoblar ruxsatga qarab saralanadi ---------- */
    Route::get('/help/suggestions', [HelpController::class, 'suggestions']);
    Route::get('/help/entries/{id}', [HelpController::class, 'entry'])->whereNumber('id');
    Route::post('/help/ask', [HelpController::class, 'ask'])->middleware('throttle:40,1');
    Route::post('/help/feedback', [HelpController::class, 'feedback'])->middleware('throttle:60,1');

    /* ---------- Smenalar sozlamasi ---------- */
    Route::get('/lab-shifts', [LabShiftController::class, 'index'])
        ->middleware('permission:shifts.manage');
    Route::post('/lab-shifts', [LabShiftController::class, 'store'])
        ->middleware('permission:shifts.manage');
    Route::put('/lab-shifts/{labShift}', [LabShiftController::class, 'update'])
        ->middleware('permission:shifts.manage');
    Route::delete('/lab-shifts/{labShift}', [LabShiftController::class, 'destroy'])
        ->middleware('permission:shifts.manage');
});

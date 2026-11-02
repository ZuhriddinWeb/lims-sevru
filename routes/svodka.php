<?php

/*
|--------------------------------------------------------------------------
| Buyurtmachi svodkasi — API  [Paket 29]
|--------------------------------------------------------------------------
| SvodkaServiceProvider ulaydi (/api prefiksi bilan) — routes/api.php ga tegilmaydi.
|   /api/portal/svodka/*   — FAQAT buyurtmachi (ruxsat controller ichida; CustomerPortalFence portal/* ni ochiq qoldiradi)
|   /api/svodka-releases/* — laboratoriya (boshliq chiqaradi; nuqta nomlari yo'q)
*/

use App\Http\Controllers\Api\Svodka\PortalSvodkaController;
use App\Http\Controllers\Api\Svodka\SvodkaReleaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    /* ---------- Buyurtmachi ---------- */
    Route::prefix('portal/svodka')->group(function () {
        Route::get('/context', [PortalSvodkaController::class, 'context']);
        Route::get('/spravochnik', [PortalSvodkaController::class, 'spravochnik']);
        Route::post('/sections', [PortalSvodkaController::class, 'storeSection']);
        Route::put('/sections/{id}', [PortalSvodkaController::class, 'updateSection'])->whereNumber('id');
        Route::delete('/sections/{id}', [PortalSvodkaController::class, 'destroySection'])->whereNumber('id');
        Route::post('/points/bulk', [PortalSvodkaController::class, 'bulkPoints']);
        Route::post('/points', [PortalSvodkaController::class, 'storePoint']);
        Route::put('/points/{id}', [PortalSvodkaController::class, 'updatePoint'])->whereNumber('id');
        Route::delete('/points/{id}', [PortalSvodkaController::class, 'destroyPoint'])->whereNumber('id');
        Route::get('/day', [PortalSvodkaController::class, 'day']);
        Route::put('/assign', [PortalSvodkaController::class, 'assign']);
        Route::get('/export', [PortalSvodkaController::class, 'export'])->middleware('throttle:20,1');
        Route::get('/release/{id}', [PortalSvodkaController::class, 'release'])->whereNumber('id');
    });

    /* ---------- Laboratoriya ---------- */
    Route::prefix('svodka-releases')->group(function () {
        Route::get('/departments', [SvodkaReleaseController::class, 'departments'])
            ->middleware('permission:svodka.release|customer_departments.manage');
        Route::put('/settings/{department}', [SvodkaReleaseController::class, 'updateSettings'])->whereNumber('department')
            ->middleware('permission:customer_departments.manage');
        Route::get('/periods', [SvodkaReleaseController::class, 'periods'])
            ->middleware('permission:svodka.release');
        Route::get('/period', [SvodkaReleaseController::class, 'period'])
            ->middleware('permission:svodka.release');
        Route::post('/', [SvodkaReleaseController::class, 'release'])
            ->middleware('permission:svodka.release');
        // [P31] svodkani ko'ruvchilar — har bir foydalanuvchiga alohida (faqat admin; tekshiruv kontrollerda)
        Route::get('/viewers', [SvodkaReleaseController::class, 'viewers']);
        Route::post('/viewers', [SvodkaReleaseController::class, 'addViewer']);
        Route::delete('/viewers/{user}', [SvodkaReleaseController::class, 'removeViewer'])->whereNumber('user');
    });
});

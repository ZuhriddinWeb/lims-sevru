<?php

/*
|--------------------------------------------------------------------------
| Buyurtma so'rovlari (qoralama buyurtma) — API  [Paket 24]
|--------------------------------------------------------------------------
| SampleRequestServiceProvider ulaydi (/api prefiksi bilan) — routes/api.php ga tegilmaydi.
|   /api/portal/requests/*   — buyurtmachi (ruxsat controller ichida; CustomerPortalFence portal/* ni ochiq qoldiradi)
|   /api/sample-requests/*   — laboratoriya
*/

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

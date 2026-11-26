<?php

/*
| [Paket 44] Markaziy portalga uzatish — sozlamalar (faqat admin / central.manage).
| CentralLinkServiceProvider /api prefiksi bilan yuklaydi.
*/

use App\Http\Controllers\Api\Central\CentralLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'permission:central.manage'])->prefix('central-link')->group(function () {
    Route::get('/', [CentralLinkController::class, 'show']);
    Route::put('/', [CentralLinkController::class, 'update']);
    Route::get('/preview', [CentralLinkController::class, 'preview']);
    Route::post('/push', [CentralLinkController::class, 'push'])->middleware('throttle:12,1');
});

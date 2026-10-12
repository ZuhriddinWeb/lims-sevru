<?php

/*
|--------------------------------------------------------------------------
| Ishchi varaqlar moduli — API marshrutlari
|--------------------------------------------------------------------------
| routes/api.php ichida, auth:sanctum guruhining ICHIGA bitta qator qo'shiladi:
|
|     require __DIR__ . '/worksheets.php';
|
| Modulni olib tashlashda shu qatorni o'chirish kifoya.
*/

use App\Http\Controllers\Api\WorksheetController;
use App\Http\Controllers\Api\WorksheetTemplateController;
use Illuminate\Support\Facades\Route;

/* ---------- Shablonlar (konstruktor) ---------- */
Route::get('/worksheet-templates', [WorksheetTemplateController::class, 'index'])
    ->middleware('permission:worksheets.view|worksheet_templates.manage');
Route::get('/worksheet-templates/{worksheetTemplate}', [WorksheetTemplateController::class, 'show'])
    ->middleware('permission:worksheets.view|worksheet_templates.manage');

Route::middleware('permission:worksheet_templates.manage')->group(function () {
    Route::post('/worksheet-templates/preview', [WorksheetTemplateController::class, 'preview']);
    Route::post('/worksheet-templates', [WorksheetTemplateController::class, 'store']);
    Route::put('/worksheet-templates/{worksheetTemplate}', [WorksheetTemplateController::class, 'update']);
    Route::post('/worksheet-templates/{worksheetTemplate}/duplicate', [WorksheetTemplateController::class, 'duplicate']);
    Route::delete('/worksheet-templates/{worksheetTemplate}', [WorksheetTemplateController::class, 'destroy']);
});

/* ---------- Ishchi varaqlar ---------- */
// Yangi varaq / partiyaga qo'shish uchun mos probalar ({worksheet} marshrutidan OLDIN turishi shart)
Route::get('/worksheets/candidates', [WorksheetController::class, 'candidates'])
    ->middleware('permission:worksheets.create|worksheets.update');

Route::middleware('permission:worksheets.view')->group(function () {
    Route::get('/worksheets', [WorksheetController::class, 'index']);
    Route::get('/worksheets/staff', [WorksheetController::class, 'staff']);
    Route::get('/worksheets/by-samples', [WorksheetController::class, 'bySamples']);
    Route::get('/worksheets/{worksheet}', [WorksheetController::class, 'show']);
    Route::post('/worksheets/{worksheet}/printed', [WorksheetController::class, 'markPrinted']);
    Route::get('/worksheets/{worksheet}/scans/{scan}', [WorksheetController::class, 'showScan']);
});

Route::post('/worksheets', [WorksheetController::class, 'store'])
    ->middleware('permission:worksheets.create');

Route::middleware('permission:worksheets.update')->group(function () {
    Route::put('/worksheets/{worksheet}', [WorksheetController::class, 'update']);
    Route::post('/worksheets/{worksheet}/complete', [WorksheetController::class, 'complete']);
    // Partiya: probalar
    Route::post('/worksheets/{worksheet}/samples', [WorksheetController::class, 'addSamples']);
    Route::delete('/worksheets/{worksheet}/samples/{sample}', [WorksheetController::class, 'removeSample']);
    Route::post('/worksheets/{worksheet}/samples/{sample}/retest', [WorksheetController::class, 'markRetest']);
    Route::delete('/worksheets/{worksheet}/samples/{sample}/retest', [WorksheetController::class, 'clearRetest']);
    Route::post('/worksheets/{worksheet}/scans', [WorksheetController::class, 'uploadScans']);
    Route::delete('/worksheets/{worksheet}/scans/{scan}', [WorksheetController::class, 'deleteScan']);
});

Route::middleware('permission:worksheets.approve')->group(function () {
    Route::post('/worksheets/{worksheet}/approve', [WorksheetController::class, 'approve']);
    Route::post('/worksheets/{worksheet}/reopen', [WorksheetController::class, 'reopen']);
});

Route::delete('/worksheets/{worksheet}', [WorksheetController::class, 'destroy'])
    ->middleware('permission:worksheets.delete');

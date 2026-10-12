<?php

/*
|--------------------------------------------------------------------------
| Laboratoriyalararo tekshiruv moduli — API marshrutlari
|--------------------------------------------------------------------------
| InterlabServiceProvider orqali /api prefiksi bilan yuklanadi.
| routes/api.php ga hech narsa qo'shish SHART EMAS.
*/

use App\Http\Controllers\Api\Interlab\InterlabNetworkController;
use App\Http\Controllers\Api\Interlab\InterlabPublicController;
use App\Http\Controllers\Api\Interlab\InterlabReferralController;
use Illuminate\Support\Facades\Route;

/* ---------- Serverlar o'rtasida (login'siz, imzo va shifr bilan himoyalangan) ---------- */
Route::prefix('interlab/v1')->middleware('throttle:600,1')->group(function () {
    Route::get('/identity', [InterlabPublicController::class, 'identity']);
    Route::post('/inbox', [InterlabPublicController::class, 'inbox']);
});

/* ---------- Foydalanuvchilar uchun ---------- */
Route::middleware('auth:sanctum')->prefix('interlab')->group(function () {

    Route::middleware('permission:interlab.view')->group(function () {
        Route::get('/summary', [InterlabReferralController::class, 'summary']);
        Route::post('/sync', [InterlabReferralController::class, 'sync']);
        Route::get('/referrals', [InterlabReferralController::class, 'index']);
        Route::get('/referrals/{referral}', [InterlabReferralController::class, 'show']);
        Route::get('/peers/active', [InterlabReferralController::class, 'activePeers']);
    });

    Route::post('/messages/{message}/retry', [InterlabReferralController::class, 'retry'])
        ->middleware('permission:interlab.send|interlab.process|interlab.manage');

    // Jo'natuvchi
    Route::middleware('permission:interlab.send')->group(function () {
        Route::get('/samples', [InterlabReferralController::class, 'samples']);
        Route::post('/referrals', [InterlabReferralController::class, 'store']);
        Route::post('/referrals/{referral}/cancel', [InterlabReferralController::class, 'cancel']);
        Route::post('/referrals/{referral}/reviewed', [InterlabReferralController::class, 'reviewed']);
    });

    // Tekshiruvchi
    Route::middleware('permission:interlab.process')->group(function () {
        Route::post('/referrals/{referral}/accept', [InterlabReferralController::class, 'accept']);
        Route::post('/referrals/{referral}/reject', [InterlabReferralController::class, 'reject']);
        Route::put('/referrals/{referral}/results', [InterlabReferralController::class, 'saveResults']);
        Route::post('/referrals/{referral}/results/send', [InterlabReferralController::class, 'sendResults']);
    });

    // Tarmoq sozlamalari (admin / laboratoriya boshlig'i)
    Route::middleware('permission:interlab.manage')->prefix('network')->group(function () {
        Route::get('/', [InterlabNetworkController::class, 'show']);
        Route::post('/identity', [InterlabNetworkController::class, 'createIdentity']);
        Route::put('/identity', [InterlabNetworkController::class, 'updateIdentity']);
        Route::post('/invites', [InterlabNetworkController::class, 'createInvite']);
        Route::delete('/invites/{invite}', [InterlabNetworkController::class, 'revokeInvite']);
        Route::post('/peers', [InterlabNetworkController::class, 'connect']);
        Route::post('/peers/{peer}/reject', [InterlabNetworkController::class, 'reject']);
        Route::post('/peers/{peer}/ping', [InterlabNetworkController::class, 'ping']);
        Route::post('/peers/{peer}/disable', [InterlabNetworkController::class, 'disable']);
        Route::post('/peers/{peer}/enable', [InterlabNetworkController::class, 'enable']);
        Route::delete('/peers/{peer}', [InterlabNetworkController::class, 'destroy']);
    });
});

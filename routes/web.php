<?php

use Illuminate\Support\Facades\Route;

// Sanctum SPA uchun CSRF cookie
Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent();
});

// Barcha boshqa marshrutlar Vue SPA ga yo'naltiriladi
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');

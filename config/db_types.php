<?php

/*
 * Bazadan kelayotgan qiymatlar turi (App\Providers\DatabaseTypesServiceProvider).
 *
 * integer_ids — *_id / *_by ustunlarini son qilib berish.
 *   bo'sh (null) — avtomatik: faqat SQL Server (sqlsrv) ulanishida yoqiladi;
 *   true         — har qanday bazada yoqilgan;
 *   false        — o'chirilgan.
 * .env: DB_INTEGER_IDS=false  →  so'ng php artisan optimize:clear
 */
return [
    'integer_ids' => env('DB_INTEGER_IDS'),
];

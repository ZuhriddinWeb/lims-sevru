<?php

return [
    /*
    | Yordamchi (lokal AI) butunlay yoqilgan/o'chirilgan.
    | O'chirish:  .env ga  HELP_ENABLED=false  qo'shing, so'ng  php artisan optimize:clear
    | O'chirilganda tugma hamma uchun yo'qoladi va /api/help/* 404 qaytaradi.
    */
    'enabled' => (bool) env('HELP_ENABLED', true),
];

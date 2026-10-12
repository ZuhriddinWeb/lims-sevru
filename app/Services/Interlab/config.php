<?php

/*
 * Laboratoriyalararo tekshiruv moduli sozlamalari (standart qiymatlar).
 * .env orqali o'zgartirish mumkin — config/ papkasiga fayl qo'yish shart emas.
 */
return [
    // HTTPS sertifikatini tekshirish. Ichki tarmoqda o'z-o'zidan imzolangan sertifikatlar
    // ko'p bo'lgani uchun standart — o'chiq. Xabarlar baribir uchidan-uchiga shifrlangan va imzolangan.
    'verify_tls' => (bool) env('INTERLAB_VERIFY_TLS', false),

    // Ulanish va javob kutish vaqti (soniya)
    'connect_timeout' => (int) env('INTERLAB_CONNECT_TIMEOUT', 4),
    'timeout' => (int) env('INTERLAB_TIMEOUT', 15),

    // Bundan eski xabarlar qabul qilinmaydi (kun)
    'max_message_age_days' => (int) env('INTERLAB_MAX_AGE_DAYS', 30),

    // Laboratoriyalar holatini tekshirish oralig'i (soniya)
    'ping_interval' => (int) env('INTERLAB_PING_INTERVAL', 120),

    // Bir sinxronlashda ko'pi bilan nechta xabar yuboriladi
    'batch' => (int) env('INTERLAB_BATCH', 50),

    // Kiruvchi xabarning eng katta hajmi (bayt)
    'max_envelope_bytes' => 2 * 1024 * 1024,
];

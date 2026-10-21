<?php

return [
    // Yuborilgan, lekin namuna olib kelinmagan so'rov necha kundan keyin «muddati o'tgan» bo'ladi.
    // Muddati o'tgan so'rovni laboratoriya baribir qabul qila oladi; buyurtmachi qaytarib olib qayta yuborishi mumkin.
    'expire_days' => (int) env('SAMPLE_REQUEST_EXPIRE_DAYS', 14),

    // Bitta so'rovdagi namunalar soni chegarasi
    'max_items' => (int) env('SAMPLE_REQUEST_MAX_ITEMS', 100),
];

<?php

return [
    App\Providers\AppServiceProvider::class,
    Laravel\Sanctum\SanctumServiceProvider::class,
    App\Providers\InterlabServiceProvider::class, // Laboratoriyalararo tekshiruv
    App\Providers\SampleIntakeServiceProvider::class, // [P15]
];

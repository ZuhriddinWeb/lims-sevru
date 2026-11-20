<?php

namespace App\Providers;

use App\Console\Commands\SampleRequestsExpire;
use App\Services\Samples\SampleRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Buyurtma so'rovlari (qoralama buyurtma) — Paket 24.
 * SampleIntakeServiceProvider o'zi ro'yxatdan o'tkazadi — bootstrap/providers.php ga qator SHART EMAS.
 */
class SampleRequestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('sample_requests.php'), 'sample_requests');
        $this->app->scoped(SampleRequests::class);
        $this->app->scoped(\App\Services\Samples\RequestSchedules::class);
        $this->app->scoped(\App\Services\Samples\RequestSettings::class); // [P39]
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/sample_requests.php'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SampleRequestsExpire::class, \App\Console\Commands\SchedulesTick::class]);
        }

        // Yuborilgan, lekin muddatida olib kelinmagan so'rovlar — har soatda (php artisan schedule:run)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('sample-requests:expire')->hourly()->withoutOverlapping(30);
            // [P39] yopilmagan (muddati o'tgan) buyurtmalar — har kuni ertalab eslatma
            $schedule->command('sample-requests:expire --remind')->dailyAt('09:10')->withoutOverlapping(30);
            // [P36/P39] grafik: partiyalar, avtomatik buyurtma (vaqtidan N daqiqa oldin), muddat, kechikish xabari
            $schedule->command('schedules:tick')->everyFiveMinutes()->withoutOverlapping(10);
        });
    }
}

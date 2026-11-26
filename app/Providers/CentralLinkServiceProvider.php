<?php

namespace App\Providers;

use App\Console\Commands\CentralPush;
use App\Services\Central\CentralLink;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * [Paket 44] Markaziy portalga uzatish. SampleIntakeServiceProvider o'zi ro'yxatdan o'tkazadi —
 * bootstrap/providers.php ga qator SHART EMAS.
 */
class CentralLinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CentralLink::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/central.php'));
        }
        if ($this->app->runningInConsole()) {
            $this->commands([CentralPush::class]);
        }
        // Har 5 daqiqada tekshiradi; o'chiq bo'lsa yoki oraliq o'tmagan bo'lsa — hech narsa qilmaydi
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('central:push')->everyFiveMinutes()->withoutOverlapping(10);
        });
    }
}

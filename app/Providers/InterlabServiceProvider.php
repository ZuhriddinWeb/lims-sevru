<?php

namespace App\Providers;

use App\Console\Commands\InterlabSync;
use App\Services\Interlab\Identity;
use App\Services\Interlab\Notifier;
use App\Services\Interlab\Outbox;
use App\Services\Interlab\Transport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Laboratoriyalararo tekshiruv moduli — barcha ulanishlar shu yerda.
 *
 * O'rnatish: bootstrap/providers.php ga BITTA qator:
 *     App\Providers\InterlabServiceProvider::class,
 * Olib tashlash: shu qatorni o'chirish kifoya (mavjud kodga boshqa hech narsa qo'shilmagan).
 */
class InterlabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(app_path('Services/Interlab/config.php'), 'interlab');

        // Bitta so'rov davomida bir xil nusxa (identity keshi va h.k.)
        $this->app->singleton(Identity::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(Notifier::class);
        $this->app->singleton(Outbox::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/interlab.php'));
        }

        // class_exists — fayl ko'chirilmay qolsa ham butun artisan ishdan chiqmasin
        if ($this->app->runningInConsole() && class_exists(InterlabSync::class)) {
            $this->commands([InterlabSync::class]);
        }

        // php artisan schedule:run har daqiqa ishga tushirilsa — sinxronlash avtomatik
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('interlab:sync')->everyMinute()->withoutOverlapping(10);
        });
    }
}

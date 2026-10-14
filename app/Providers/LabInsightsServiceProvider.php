<?php

namespace App\Providers;

use App\Http\Middleware\EnsureInsightsRoutes;
use App\Services\Insights\ExecutiveInsights;
use App\Services\Insights\ShiftCalendar;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Rahbariyat paneli 2.0, smenalar, xodimlar faoliyati.
 *
 * Ulanish: SampleIntakeServiceProvider (Paket 15) uni o'zi ro'yxatdan o'tkazadi.
 * bootstrap/providers.php ga qator qo'shish ixtiyoriy (qo'shilsa ham ikki marta ulanmaydi):
 *     App\Providers\LabInsightsServiceProvider::class,
 *
 * Bu yerda ulanadi: API marshrutlari (routes/insights.php, /api prefiksi bilan).
 * Marshrutlar keshlangan bo'lsa (php artisan route:cache / optimize) va keshda bu modul yo'q bo'lsa —
 * EnsureInsightsRoutes so'rov boshida ularni qo'shadi («route could not be found» chiqmasin).
 * routes/api.php va eski ExecutiveController ga tegilmaydi — eski panel /api/executive da ishlayveradi.
 */
class LabInsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ShiftCalendar::class);
        $this->app->scoped(ExecutiveInsights::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            self::loadRoutes();
        } elseif (! $this->app->runningInConsole()) {
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->prependMiddleware(EnsureInsightsRoutes::class);
        }
    }

    public static function loadRoutes(): void
    {
        Route::middleware('api')->prefix('api')->group(base_path('routes/insights.php'));
    }
}

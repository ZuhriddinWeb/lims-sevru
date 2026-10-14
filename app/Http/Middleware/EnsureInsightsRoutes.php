<?php

namespace App\Http\Middleware;

use App\Providers\LabInsightsServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Marshrutlar keshi (php artisan route:cache / optimize) modul o'rnatilishidan OLDIN yaratilgan bo'lsa,
 * keshda /api/insights/... va /api/lab-shifts marshrutlari bo'lmaydi va «route could not be found» chiqadi.
 * Bu oraliq qatlam faqat kesh bor paytda ishlaydi: keshda modul marshruti topilmasa — uni so'rov boshida qo'shadi.
 * Eng to'g'ri yo'l baribir: php artisan optimize:clear (keyin kerak bo'lsa qayta keshlang).
 */
class EnsureInsightsRoutes
{
    private static bool $checked = false;

    public function __construct(private Router $router)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! self::$checked) {
            self::$checked = true;
            if (! $this->router->has('insights.executive')) {
                LabInsightsServiceProvider::loadRoutes();
                $this->router->getRoutes()->refreshNameLookups();
                $this->router->getRoutes()->refreshActionLookups();
            }
        }

        return $next($request);
    }
}

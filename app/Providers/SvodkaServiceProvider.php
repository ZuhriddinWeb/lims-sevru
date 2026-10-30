<?php

namespace App\Providers;

use App\Models\SampleElement;
use App\Services\Svodka\SvodkaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Buyurtmachi svodkasi — Paket 29. SampleIntakeServiceProvider o'zi ulaydi (bootstrap/providers.php ga qator SHART EMAS).
 *   - /api/portal/svodka/*   — buyurtmachi (spravochnik, svodka, Excel); laboratoriya xodimiga yopiq
 *   - /api/svodka-releases/* — laboratoriya boshlig'i (chiqarish; nuqta nomlarisiz)
 *   - natija yozilganda: davr tayyor bo'lsa boshliqqa xabar
 */
class SvodkaServiceProvider extends ServiceProvider
{
    /** @var array<int, true> shu so'rovda tekshiriladigan namunalar */
    private static array $pending = [];

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/svodka.php'));
        }

        SampleElement::saved(function (SampleElement $se) {
            try {
                if (! $se->wasChanged(['result_value', 'status']) && ! $se->wasRecentlyCreated) {
                    return;
                }
                if ($se->status !== 'done' || $se->result_value === null) {
                    return;
                }
                $sid = (int) $se->sample_id;
                if (isset(self::$pending[$sid])) {
                    return;
                }
                self::$pending[$sid] = true;
                DB::afterCommit(function () use ($sid) {
                    unset(self::$pending[$sid]);
                    $svc = app(SvodkaService::class);
                    if ($svc->available()) {
                        $svc->checkReady($sid);
                    }
                });
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}

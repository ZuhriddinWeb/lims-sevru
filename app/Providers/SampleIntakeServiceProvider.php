<?php

namespace App\Providers;

use App\Http\Middleware\CustomerPortalFence;
use App\Http\Middleware\SampleIntakeHook;
use App\Models\CustomerDepartment;
use App\Models\Sample;
use App\Models\SampleQuantityMovement;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\SampleAnalyses;
use App\Services\Samples\SampleLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Buyurtmachi bo'limlar, buyurtmachi portali va proba miqdori hisobi.
 *
 * O'rnatish: bootstrap/providers.php ga BITTA qator:
 *     App\Providers\SampleIntakeServiceProvider::class,
 *
 * Bu yerda ulanadi:
 *   - API marshrutlari (routes/sample_intake.php, /api prefiksi bilan);
 *   - Sample modeliga customerDepartment va quantityMovements bog'lanishlari (Sample.php ga tegilmaydi);
 *   - yangi ustunlarning turi (SQL Server raqamlarni satr qilib qaytaradi — JSON'da son bo'lsin);
 *   - CustomerPortalFence — buyurtmachi foydalanuvchisiga laboratoriya API'sini yopadi;
 *   - SampleIntakeHook — proba formasidagi bo'lim/shifr/miqdorni saqlaydi;
 *   - probalar ro'yxati/kartochkasida bo'lim nomi va ?customer_department_id= / ?customer_code= filtri.
 * SampleController.php ga qo'lda hech narsa qo'shish SHART EMAS.
 */
class SampleIntakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // [P16] Rahbariyat paneli 2.0 / smenalar / xodimlar faoliyati moduli o'rnatilgan bo'lsa — shu yerdan ulanadi
        // (bootstrap/providers.php ga qator qo'shish esdan chiqsa ham API ishlaydi; qo'shilgan bo'lsa — ikki marta ulanmaydi)
        if (class_exists(\App\Providers\LabInsightsServiceProvider::class)) {
            $this->app->register(\App\Providers\LabInsightsServiceProvider::class);
        }
        // [T19] SQL Server: *_id ustunlari son bo'lib kelsin (natija kiritishdagi 404 va shu turdagi xatolar)
        if (class_exists(\App\Providers\DatabaseTypesServiceProvider::class)) {
            $this->app->register(\App\Providers\DatabaseTypesServiceProvider::class);
        }
        // [P24] Buyurtma so'rovlari (qoralama buyurtma)
        if (class_exists(\App\Providers\SampleRequestServiceProvider::class)) {
            $this->app->register(\App\Providers\SampleRequestServiceProvider::class);
        }

        $this->app->scoped(CustomerPortal::class);
        $this->app->scoped(SampleLedger::class);
        $this->app->scoped(SampleAnalyses::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/sample_intake.php'));
        }

        Sample::resolveRelationUsing('customerDepartment', fn (Sample $sample) =>
            $sample->belongsTo(CustomerDepartment::class, 'customer_department_id')->withTrashed());
        Sample::resolveRelationUsing('quantityMovements', fn (Sample $sample) =>
            $sample->hasMany(SampleQuantityMovement::class)->orderBy('created_at'));

        $casts = [
            'customer_department_id' => 'integer',
            'received_quantity'      => 'float',
            'retained_quantity'      => 'float',
        ];
        Sample::retrieved(fn (Sample $sample) => $sample->mergeCasts($casts));
        Sample::saved(fn (Sample $sample) => $sample->mergeCasts($casts));

        // Probalar API'si (/api/samples...): bo'lim nomi doim javobda; ro'yxatda bo'lim va shifr filtri
        Sample::addGlobalScope('sample_intake', function (Builder $query) {
            $request = $this->app->bound('request') ? $this->app['request'] : null;
            $path = $request ? trim($request->path(), '/') : '';
            if (! str_starts_with($path, 'api/samples')) {
                return;
            }
            $query->with('customerDepartment:id,code,name');

            if ($path === 'api/samples' && $request->isMethod('GET')) {
                $table = $query->getModel()->getTable();
                if ($request->filled('customer_department_id')) {
                    $query->where("{$table}.customer_department_id", (int) $request->input('customer_department_id'));
                }
                if ($code = trim((string) $request->input('customer_code'))) {
                    $query->where("{$table}.customer_code", 'like', "%{$code}%");
                }
            }
        });

        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('api', CustomerPortalFence::class);
        $router->pushMiddlewareToGroup('api', SampleIntakeHook::class);
    }
}

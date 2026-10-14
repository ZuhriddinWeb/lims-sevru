<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * SQL Server: id ustunlari bazadan SON bo'lib kelsin (MySQL'dagidek).
 *
 * Muammo. pdo_sqlsrv drayveri bigint ustunlarni — ya'ni barcha tashqi kalitlarni (sample_id, equipment_id,
 * user_id ...) — PHP'ga SATR qilib beradi, birlamchi kalit `id` ni esa Laravel o'zi songa aylantiradi:
 *     $sampleElement->sample_id  →  "16"   (satr)
 *     $sample->id                →  16     (son)
 * Kodda `$sampleElement->sample_id !== $sample->id` kabi qat'iy taqqoslashlar MySQL'da to'g'ri ishlaydi,
 * SQL Server'da esa doim «teng emas» bo'ladi. Natijada:
 *     natija kiritish                     → 404 (xabarsiz, ekranda «Amalni bajarib bo'lmadi»)
 *     uskuna / sinov obyekti hujjatini o'chirish → 404
 *     ishchi varaq skanini ochish/o'chirish → 404
 *     bildirishnomani «o'qildi» qilish      → 403
 *     interfeysda laboratoriya bo'yicha guruh ro'yxatini filtrlash — bo'sh ro'yxat
 *
 * Yechim. Bazadan o'qilgan har bir modelda *_id va *_by ustunlari (qiymati butun son bo'lsa, o'z cast'i
 * bo'lmasa) `integer` deb belgilanadi. Model fayllariga va controllerlarga tegilmaydi.
 *
 * Qachon ishlaydi: faqat asosiy ulanish sqlsrv bo'lsa. Majburan yoqish/o'chirish — .env:
 *     DB_INTEGER_IDS=false   (o'chirish)      DB_INTEGER_IDS=true   (har qanday bazada yoqish)
 * keyin: php artisan optimize:clear
 *
 * Ulanishi: SampleIntakeServiceProvider o'zi ro'yxatdan o'tkazadi — bootstrap/providers.php ga qator SHART EMAS.
 */
class DatabaseTypesServiceProvider extends ServiceProvider
{
    private const INT_RE = '/^(0|-?[1-9][0-9]{0,17})$/';

    public function boot(): void
    {
        if (! self::enabled()) {
            return;
        }

        Event::listen('eloquent.retrieved: *', static function ($event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                self::normalize($model);
            }
        });
    }

    public static function enabled(): bool
    {
        $flag = config('db_types.integer_ids');
        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOL);
        }

        $default = config('database.default');

        return config("database.connections.{$default}.driver") === 'sqlsrv';
    }

    /** *_id / *_by ustunlarini songa aylantiradi (faqat qiymati butun son bo'lgan satrlarni). */
    public static function normalize(Model $model): void
    {
        $casts = [];
        foreach ($model->getAttributes() as $key => $value) {
            if (! is_string($value) || ! (str_ends_with($key, '_id') || str_ends_with($key, '_by'))) {
                continue;
            }
            if (preg_match(self::INT_RE, $value) && ! $model->hasCast($key)) {
                $casts[$key] = 'integer';
            }
        }
        if ($casts) {
            $model->mergeCasts($casts);
        }
    }
}

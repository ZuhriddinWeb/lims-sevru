<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Sample;
use App\Models\TestObject;
use App\Models\User;
use App\Models\WaterOrder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Savatcha (Recycle Bin) — soft-delete qilingan (o'chirilgan) yozuvlarni
 * ko'rish, tiklash (restore) yoki butunlay o'chirish (forceDelete).
 *
 * ISO 17025: hech narsa izsiz yo'qolmaydi. Admin o'chirilgan yozuvni
 * ko'rib, kerak bo'lsa tiklaydi; butunlay o'chirish faqat alohida ruxsat bilan.
 */
class TrashController extends Controller
{
    /**
     * Savatcha qamrab oladigan turlar.
     * key => [model, title (qaysi ustun sarlavha), subtitle, label_*]
     */
    protected function types(): array
    {
        return [
            'samples' => [
                'model'    => Sample::class,
                'title'    => ['code', 'identifier', 'name', 'id'],
                'subtitle' => ['status', 'created_at'],
                'label_ru' => 'Пробы',
                'label_uz' => 'Probalar',
                'label_en' => 'Samples',
                'icon'     => 'flask-conical',
            ],
            'test_objects' => [
                'model'    => TestObject::class,
                'title'    => ['identifier', 'code', 'name', 'name_ru', 'name_uz', 'id'],
                'subtitle' => ['type', 'created_at'],
                'label_ru' => 'Объекты испытаний',
                'label_uz' => 'Sinov obyektlari',
                'label_en' => 'Test objects',
                'icon'     => 'boxes',
            ],
            'equipment' => [
                'model'    => Equipment::class,
                'title'    => ['name', 'name_ru', 'name_uz', 'inventory_number', 'id'],
                'subtitle' => ['inventory_number', 'serial_number', 'created_at'],
                'label_ru' => 'Оборудование',
                'label_uz' => 'Jihozlar',
                'label_en' => 'Equipment',
                'icon'     => 'microscope',
            ],
            'water_orders' => [
                'model'    => WaterOrder::class,
                'title'    => ['number', 'code', 'id'],
                'subtitle' => ['status', 'created_at'],
                'label_ru' => 'Заявки на воду',
                'label_uz' => 'Suv buyurtmalari',
                'label_en' => 'Water orders',
                'icon'     => 'droplets',
            ],
            'users' => [
                'model'    => User::class,
                'title'    => ['name', 'login', 'email', 'id'],
                'subtitle' => ['login', 'email'],
                'label_ru' => 'Пользователи',
                'label_uz' => 'Foydalanuvchilar',
                'label_en' => 'Users',
                'icon'     => 'users',
            ],
        ];
    }

    protected function resolveType(string $type): array
    {
        $types = $this->types();

        abort_unless(isset($types[$type]), 404, "Noma'lum tur: {$type}");

        return $types[$type];
    }

    /** Model obyektidan birinchi mavjud ustunni oladi. */
    protected function pick($record, array $candidates, $default = null)
    {
        foreach ($candidates as $col) {
            $val = $record->getAttribute($col);
            if ($val !== null && $val !== '') {
                return $val;
            }
        }

        return $default;
    }

    /**
     * Har bir tur bo'yicha nechta o'chirilgan yozuv borligi — kartochkalar uchun.
     */
    public function counts(): JsonResponse
    {
        $out = [];

        foreach ($this->types() as $key => $cfg) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = $cfg['model'];

            $out[] = [
                'type'     => $key,
                'label_ru' => $cfg['label_ru'],
                'label_uz' => $cfg['label_uz'],
                'label_en' => $cfg['label_en'],
                'icon'     => $cfg['icon'],
                'count'    => $model::onlyTrashed()->count(),
            ];
        }

        return response()->json(['data' => $out]);
    }

    /**
     * Berilgan turdagi o'chirilgan yozuvlar ro'yxati.
     */
    public function index(Request $request, string $type): JsonResponse
    {
        $cfg = $this->resolveType($type);
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = $cfg['model'];

        $query = $model::onlyTrashed()->orderByDesc('deleted_at');

        if ($search = trim((string) $request->query('search', ''))) {
            // Sarlavha ustunlari bo'yicha oddiy qidiruv.
            $cols = $cfg['title'];
            $query->where(function ($q) use ($cols, $search) {
                foreach ($cols as $col) {
                    if ($col === 'id') {
                        continue;
                    }
                    $q->orWhere($col, 'like', '%' . $search . '%');
                }
            });
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 20;

        $page = $query->paginate($perPage);

        $rows = collect($page->items())->map(function ($record) use ($cfg) {
            return [
                'id'         => $record->getKey(),
                'title'      => (string) $this->pick($record, $cfg['title'], '#' . $record->getKey()),
                'subtitle'   => $this->pick($record, $cfg['subtitle']),
                'deleted_at' => optional($record->deleted_at)->toDateTimeString(),
                'created_at' => optional($record->created_at)->toDateTimeString(),
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    /**
     * Yozuvni tiklash (restore).
     */
    public function restore(string $type, int $id): JsonResponse
    {
        $cfg = $this->resolveType($type);
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = $cfg['model'];

        $record = $model::onlyTrashed()->find($id);

        if (! $record) {
            return response()->json([
                'message' => 'Yozuv topilmadi yoki allaqachon tiklangan.',
            ], 404);
        }

        try {
            $record->restore();
        } catch (QueryException $e) {
            // Masalan, shifr/kod endi yangi yozuv tomonidan band bo'lsa.
            return response()->json([
                'message' => 'Tiklab bo\'lmadi: bu yozuvning kodi yoki noyob qiymati '
                    . 'hozir boshqa yozuv tomonidan band. Avval o\'sha yangi yozuvni '
                    . 'o\'zgartiring, so\'ng qayta urinib ko\'ring.',
            ], 422);
        }

        return response()->json([
            'message' => 'Yozuv muvaffaqiyatli tiklandi.',
            'id'      => $record->getKey(),
        ]);
    }

    /**
     * Yozuvni butunlay (qaytarib bo'lmas) o'chirish.
     */
    public function forceDelete(string $type, int $id): JsonResponse
    {
        $cfg = $this->resolveType($type);
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = $cfg['model'];

        $record = $model::onlyTrashed()->find($id);

        if (! $record) {
            return response()->json([
                'message' => 'Yozuv topilmadi yoki allaqachon o\'chirilgan.',
            ], 404);
        }

        try {
            $record->forceDelete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Butunlay o\'chirib bo\'lmadi: bu yozuvga bog\'liq boshqa '
                    . 'ma\'lumotlar mavjud. Avval ularni ko\'rib chiqing.',
            ], 422);
        }

        return response()->json([
            'message' => 'Yozuv butunlay o\'chirildi.',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Sample;
use App\Models\TestObject;
use App\Models\WaterOrder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hisobotlar — TZ 2.13 (probalar bo'yicha hisobot) va uskuna/obyekt reyestrlari.
 * Qo'shimcha kutubxona talab qilmaydi: CSV (UTF-8 BOM bilan) Excel'da to'g'ri ochiladi.
 */
class ReportController extends Controller
{
    /* ================= PROBALAR REYESTRI ================= */

    public function samples(Request $request): StreamedResponse
    {
        $query = Sample::with([
            'customerGroup', 'assignedGroup', 'registeredBy',
            'sampleElements.element', 'sampleElements.equipment',
        ]);

        $this->applySampleFilters($query, $request);
        $this->restrictToGroup($query, 'samples.view_all');

        $locale = $request->input('locale', 'ru');
        $name = 'probalar_' . now()->format('Y-m-d_Hi') . '.csv';

        return $this->stream($name, function ($out) use ($query, $locale) {
            fputcsv($out, [
                'Шифр', 'Номер пробы', 'Документ', 'Подразделение',
                'Группа-заказчик', 'Группа-исполнитель', 'Состояние',
                'Дата регистрации', 'Дата результата', 'Статус',
                'Элемент', 'Результат', 'Ед. изм.', 'Оборудование', 'Дата результата элемента',
            ], ';');

            $query->chunk(200, function ($samples) use ($out, $locale) {
                foreach ($samples as $s) {
                    $base = [
                        $s->code,
                        $s->sample_number,
                        $s->source_document,
                        $s->source_division,
                        $this->localized($s->customerGroup, 'name', $locale),
                        $this->localized($s->assignedGroup, 'name', $locale),
                        $s->state_of_matter === 'liquid' ? 'Жидкая' : 'Твёрдая',
                        optional($s->registered_at)->format('d.m.Y H:i'),
                        optional($s->completed_at)->format('d.m.Y H:i'),
                        $s->status,
                    ];

                    if ($s->sampleElements->isEmpty()) {
                        fputcsv($out, array_merge($base, ['', '', '', '', '']), ';');
                        continue;
                    }

                    foreach ($s->sampleElements as $se) {
                        fputcsv($out, array_merge($base, [
                            $se->element?->symbol . ' — ' . $this->localized($se->element, 'name', $locale),
                            $se->result_value !== null ? str_replace('.', ',', (string) $se->result_value) : '',
                            $se->result_unit,
                            $se->equipment?->name,
                            optional($se->result_entered_at)->format('d.m.Y H:i'),
                        ]), ';');
                    }
                }
            });
        });
    }

    /* ================= USKUNALAR REYESTRI ================= */

    public function equipment(Request $request): StreamedResponse
    {
        $query = Equipment::with(['laboratory', 'group']);

        if ($request->filled('laboratory_id')) {
            $query->where('laboratory_id', $request->input('laboratory_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $locale = $request->input('locale', 'ru');
        $name = 'uskunalar_' . now()->format('Y-m-d_Hi') . '.csv';

        return $this->stream($name, function ($out) use ($query, $locale) {
            fputcsv($out, [
                'Наименование', 'Модель', 'Серийный номер', 'Инвентарный номер',
                'Лаборатория', 'Группа', 'Местоположение', 'Состояние', 'Заблокировано',
                'Последняя калибровка', 'Следующая калибровка', 'Использований',
            ], ';');

            $query->chunk(200, function ($items) use ($out, $locale) {
                foreach ($items as $e) {
                    fputcsv($out, [
                        $e->name, $e->model, $e->serial_number, $e->inventory_number,
                        $this->localized($e->laboratory, 'name', $locale),
                        $this->localized($e->group, 'name', $locale),
                        $e->location, $e->status, $e->is_blocked ? 'Да' : 'Нет',
                        optional($e->last_calibration_at)->format('d.m.Y'),
                        optional($e->next_calibration_at)->format('d.m.Y'),
                        $e->usage_count,
                    ], ';');
                }
            });
        });
    }

    /* ================= SINOV OBYEKTLARI REYESTRI ================= */

    public function testObjects(Request $request): StreamedResponse
    {
        $query = TestObject::with('laboratory');

        foreach (['type', 'status', 'laboratory_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }

        $name = 'obyektlar_' . now()->format('Y-m-d_Hi') . '.csv';

        return $this->stream($name, function ($out) use ($query) {
            fputcsv($out, [
                'Идентификатор', 'Название', 'Тип', 'Код', 'Партия',
                'Источник', 'Дата поступления', 'Место хранения', 'Срок хранения',
                'Статус', 'Дата утилизации',
            ], ';');

            $query->chunk(200, function ($items) use ($out) {
                foreach ($items as $o) {
                    fputcsv($out, [
                        $o->identifier, $o->name, $o->type, $o->code, $o->batch,
                        $o->source_supplier,
                        optional($o->received_at)->format('d.m.Y'),
                        $o->storage_location,
                        optional($o->storage_expiry)->format('d.m.Y'),
                        $o->status,
                        optional($o->disposed_at)->format('d.m.Y'),
                    ], ';');
                }
            });
        });
    }

    /* ================= SUV BUYURTMALARI REYESTRI ================= */

    public function waterOrders(Request $request): StreamedResponse
    {
        $query = WaterOrder::with(['assignedGroup', 'monitoringPoint', 'results']);

        foreach (['status', 'assigned_group_id', 'monitoring_point_id', 'period_month'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        $this->restrictToGroup($query, 'water_orders.view_all');

        $locale = $request->input('locale', 'ru');
        $name = 'suv_buyurtmalari_' . now()->format('Y-m-d_Hi') . '.csv';

        return $this->stream($name, function ($out) use ($query, $locale) {
            fputcsv($out, [
                'Номер заказа', 'Документ', 'Подразделение', 'Группа', 'Точка мониторинга',
                'Дата регистрации', 'Статус', 'Показатель', 'Значение', 'Ед. изм.',
                'Норма от', 'Норма до', 'Оценка', 'Дата измерения',
            ], ';');

            $query->chunk(200, function ($orders) use ($out, $locale) {
                foreach ($orders as $o) {
                    $base = [
                        $o->order_number, $o->source_document, $o->division,
                        $this->localized($o->assignedGroup, 'name', $locale),
                        $o->monitoringPoint?->name,
                        optional($o->registered_at)->format('d.m.Y H:i'),
                        $o->status,
                    ];

                    if ($o->results->isEmpty()) {
                        fputcsv($out, array_merge($base, ['', '', '', '', '', '', '']), ';');
                        continue;
                    }

                    foreach ($o->results as $r) {
                        fputcsv($out, array_merge($base, [
                            $r->parameter,
                            $r->value !== null ? str_replace('.', ',', (string) $r->value) : '',
                            $r->unit,
                            $r->norm_min, $r->norm_max, $r->assessment,
                            optional($r->measured_at)->format('d.m.Y'),
                        ]), ';');
                    }
                }
            });
        });
    }

    /* ================= BITTA PROBA BAYONNOMASI ================= */

    public function sampleProtocol(Sample $sample)
    {
        $sample->load([
            'customerGroup', 'assignedGroup', 'registeringLab', 'registeringGroup',
            'registeredBy', 'sampleElements.element', 'sampleElements.equipment',
            'sampleElements.resultEnteredBy',
        ]);

        return response()->json([
            'sample'       => $sample,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => auth()->user()->name,
        ]);
    }

    /* ================= YORDAMCHI METODLAR ================= */

    private function applySampleFilters($query, Request $request): void
    {
        foreach (['period_month', 'status', 'customer_group_id', 'assigned_group_id', 'state_of_matter'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('registered_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('registered_at', '<=', $request->input('date_to'));
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%$search%")
                  ->orWhere('sample_number', 'like', "%$search%");
            });
        }
        $query->orderBy('registered_at', 'desc');
    }

    private function restrictToGroup($query, string $viewAllPermission): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && ! $user->hasPermission($viewAllPermission) && $user->laboratory_group_id) {
            $query->where('assigned_group_id', $user->laboratory_group_id);
        }
    }

    private function localized($model, string $base, string $locale): ?string
    {
        if (! $model) {
            return null;
        }
        return $model->{"{$base}_{$locale}"} ?? $model->{"{$base}_ru"} ?? $model->{$base} ?? null;
    }

    private function stream(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM — Excel kirillchani to'g'ri o'qishi uchun
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

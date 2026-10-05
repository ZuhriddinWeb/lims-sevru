<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoringPoint;
use App\Models\WaterOrderResult;
use Illuminate\Http\Request;

/**
 * AI yordamchi — suv/havo manbalari bo'yicha vaqt davomidagi
 * yomonlashish yoki normada ekanligini tahlil qiladi (TZ: ИИ помощник).
 *
 * Standart holatda lokal statistik tahlil ishlaydi (trend, me'yordan chetlanish).
 * AI_PROVIDER sozlansa, tashqi LLM orqali matnli xulosa ham olinadi.
 */
class AiAssistantController extends Controller
{
    public function analyze(Request $request)
    {
        $data = $request->validate([
            'monitoring_point_id' => ['nullable', 'exists:monitoring_points,id'],
            'parameter'           => ['nullable', 'string'],
            'date_from'           => ['nullable', 'date'],
            'date_to'             => ['nullable', 'date'],
        ]);

        $query = WaterOrderResult::query()
            ->with('order.monitoringPoint')
            ->whereNotNull('value');

        if (! empty($data['monitoring_point_id'])) {
            $query->whereHas('order', fn ($q) =>
                $q->where('monitoring_point_id', $data['monitoring_point_id']));
        }
        if (! empty($data['parameter'])) {
            $query->where('parameter', $data['parameter']);
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('measured_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('measured_at', '<=', $data['date_to']);
        }

        $results = $query->orderBy('measured_at')->get();

        if ($results->isEmpty()) {
            return response()->json([
                'status'   => 'no_data',
                'message'  => 'Tanlangan shart bo\'yicha ma\'lumot topilmadi',
                'analysis' => [],
            ]);
        }

        // Parametrlar bo'yicha guruhlab trend tahlil qilish
        $analysis = $results->groupBy('parameter')->map(function ($items, $parameter) {
            $values = $items->pluck('value')->map(fn ($v) => (float) $v)->values();
            $n = $values->count();
            $avg = round($values->avg(), 4);
            $min = $values->min();
            $max = $values->max();

            // Oddiy chiziqli trend (birinchi va oxirgi qiymat o'rtasida)
            $first = (float) $values->first();
            $last  = (float) $values->last();
            $changePct = $first != 0 ? round(($last - $first) / abs($first) * 100, 1) : 0;

            if (abs($changePct) < 5) {
                $trend = 'stable';       // Barqaror
            } elseif ($changePct > 0) {
                $trend = 'increasing';   // O'smoqda
            } else {
                $trend = 'decreasing';   // Kamaymoqda
            }

            // Me'yordan chetga chiqishlar
            $critical = $items->where('assessment', 'critical')->count();
            $warning  = $items->where('assessment', 'warning')->count();

            // Umumiy baho
            if ($critical > 0) {
                $verdict = 'deterioration'; // Yomonlashish
            } elseif ($warning > 0) {
                $verdict = 'attention';     // E'tibor talab etadi
            } else {
                $verdict = 'normal';        // Normada
            }

            return [
                'parameter'      => $parameter,
                'measurements'   => $n,
                'average'        => $avg,
                'min'            => $min,
                'max'            => $max,
                'change_percent' => $changePct,
                'trend'          => $trend,
                'critical_count' => $critical,
                'warning_count'  => $warning,
                'verdict'        => $verdict,
                'series'         => $items->map(fn ($r) => [
                    'date'  => optional($r->measured_at)->format('Y-m-d'),
                    'value' => (float) $r->value,
                    'assessment' => $r->assessment,
                ])->values(),
            ];
        })->values();

        $response = [
            'status'   => 'ok',
            'point'    => ! empty($data['monitoring_point_id'])
                ? MonitoringPoint::find($data['monitoring_point_id'])
                : null,
            'analysis' => $analysis,
            'summary'  => $this->buildSummary($analysis),
        ];

        // Tashqi AI provayderi sozlangan bo'lsa — matnli xulosa qo'shish
        if (config('services.ai.provider') !== 'local' && config('services.ai.api_key')) {
            $response['ai_text'] = $this->callExternalAi($analysis);
        }

        return response()->json($response);
    }

    private function buildSummary($analysis): string
    {
        $deterioration = $analysis->where('verdict', 'deterioration')->pluck('parameter');
        $attention     = $analysis->where('verdict', 'attention')->pluck('parameter');

        if ($deterioration->isNotEmpty()) {
            return 'Yomonlashish aniqlandi: ' . $deterioration->implode(', ') .
                   '. Ushbu ko\'rsatkichlar me\'yordan chiqqan, zudlik bilan chora ko\'rish tavsiya etiladi.';
        }
        if ($attention->isNotEmpty()) {
            return 'E\'tibor talab etadi: ' . $attention->implode(', ') .
                   '. Ko\'rsatkichlar chegaraga yaqinlashmoqda, kuzatuvni kuchaytiring.';
        }
        return 'Barcha ko\'rsatkichlar me\'yor doirasida. Jiddiy chetlanishlar aniqlanmadi.';
    }

    private function callExternalAi($analysis): ?string
    {
        // Bu yerda tashqi LLM API chaqiriladi (ixtiyoriy).
        // config('services.ai.*') orqali sozlanadi.
        return null;
    }
}

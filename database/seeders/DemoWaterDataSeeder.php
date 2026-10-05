<?php

namespace Database\Seeders;

use App\Models\MonitoringPoint;
use App\Models\User;
use App\Models\WaterOrder;
use App\Models\WaterOrderResult;
use Illuminate\Database\Seeder;

class DemoWaterDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('username', 'water')->first() ?? User::first();

        // Nuqta kodi => har bir ko'rsatkich uchun [boshlang'ich, oylik o'zgarish]
        $trends = [
            'WP-01' => ['Цианиды' => [0.010, 0.0070], 'Медь' => [0.30, 0.05], 'pH' => [7.4, -0.05]],
            'WP-02' => ['Цианиды' => [0.020, 0.0010], 'pH' => [7.2, 0.02]],
            'WP-03' => ['Нитраты' => [18.0, 0.4], 'Ртуть' => [0.0002, 0.00001], 'pH' => [7.0, 0.01]],
            'AP-01' => ['Пыль неорганическая' => [0.28, 0.02]],
        ];

        foreach ($trends as $code => $params) {
            $point = MonitoringPoint::with('parameters')->where('code', $code)->first();
            if (! $point) continue;

            // So'nggi 8 oy, har oyda bitta buyurtma
            for ($i = 7; $i >= 0; $i--) {
                $date = now()->subMonths($i)->startOfMonth()->addDays(9);

                $order = WaterOrder::create([
                    'order_number'        => 'WO-DEMO-' . $code . '-' . $date->format('Ym'),
                    'source_document'     => 'Demo ' . $date->format('m.Y'),
                    'division'            => 'Демонстрационные данные',
                    'assigned_group_id'   => $point->laboratory_group_id,
                    'monitoring_point_id' => $point->id,
                    'status'              => 'completed',
                    'period_month'        => $date->format('Y-m'),
                    'registered_by'       => $user?->id,
                    'registered_at'       => $date,
                    'started_at'          => $date,
                    'started_by'          => $user?->id,
                    'completed_at'        => $date->copy()->addDay(),
                ]);

                foreach ($params as $name => [$base, $step]) {
                    $norm = $point->parameters->firstWhere('parameter', $name);

                    // Trend + kichik tasodifiy tebranish
                    $months = 7 - $i;
                    $value  = $base + $step * $months;
                    $value += $value * (mt_rand(-40, 40) / 1000);

                    $result = new WaterOrderResult([
                        'parameter'   => $name,
                        'value'       => round($value, 6),
                        'unit'        => $norm?->unit,
                        'norm_min'    => $norm?->norm_min,
                        'norm_max'    => $norm?->norm_max,
                        'measured_at' => $date,
                        'entered_by'  => $user?->id,
                    ]);
                    $result->assessment = $result->evaluateAssessment();
                    $order->results()->save($result);
                }
            }
        }

        $this->command->info('Demo suv ma\'lumotlari yaratildi: 8 oy × 4 nuqta');
    }
}
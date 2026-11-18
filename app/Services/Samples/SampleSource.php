<?php

namespace App\Services\Samples;

use App\Models\ScheduleRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 36] Namuna manbai: oddiy buyurtma (yoki laboratoriyada qo'lda) yoki grafik bo'yicha.
 *   ?source=request|schedule|all   ?run_id=  (bitta grafik partiyasining namunalari)
 * «Mening probalarim», Excel (umumiy hisobot) va «Grafigim» shu filtrdan foydalanadi.
 */
class SampleSource
{
    private static function scheduleSub()
    {
        return fn ($q) => $q->select('sample_request_items.sample_id')->from('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->where('sample_requests.origin', RequestSchedules::ORIGIN)
            ->whereNotNull('sample_request_items.sample_id');
    }

    public static function apply(Builder $query, Request $request): void
    {
        if (! RequestSchedules::available()) {
            return;
        }
        if ($request->filled('run_id')) {
            $reqId = ScheduleRun::whereKey((int) $request->input('run_id'))->value('sample_request_id');
            $query->whereIn('samples.id', fn ($q) => $q->select('sample_id')->from('sample_request_items')
                ->where('sample_request_id', (int) ($reqId ?: 0))->whereNotNull('sample_id'));

            return;
        }
        $source = (string) $request->input('source', 'all');
        if ($source === 'schedule') {
            $query->whereIn('samples.id', self::scheduleSub());
        } elseif ($source === 'request') {
            $query->whereNotIn('samples.id', self::scheduleSub());
        }
    }

    /** @param int[] $deptIds  @return int[] grafik rejimi yoqilganlari */
    public static function scheduleDepartments(array $deptIds): array
    {
        if (! RequestSchedules::available()) {
            return [];
        }

        return array_values(array_intersect($deptIds, app(RequestSchedules::class)->enabledDepartmentIds()));
    }

    /** @param int[] $sampleIds  @return array<int, array{source: string, label: string}> */
    public static function labels(array $sampleIds): array
    {
        if (! $sampleIds || ! Schema::hasTable('sample_request_items')) {
            return [];
        }
        $rows = DB::table('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->whereIn('sample_request_items.sample_id', $sampleIds)
            ->get(['sample_request_items.sample_id', 'sample_requests.id as request_id', 'sample_requests.number', 'sample_requests.origin']);
        $runs = RequestSchedules::available()
            ? DB::table('schedule_runs')->join('request_schedules', 'request_schedules.id', '=', 'schedule_runs.schedule_id')
                ->whereIn('schedule_runs.sample_request_id', $rows->pluck('request_id')->all() ?: [0])
                ->get(['schedule_runs.sample_request_id', 'schedule_runs.planned_time', 'request_schedules.name'])->keyBy('sample_request_id')
            : collect();
        $out = [];
        foreach ($rows as $r) {
            $run = $runs->get($r->request_id);
            $out[(int) $r->sample_id] = $r->origin === RequestSchedules::ORIGIN
                ? ['source' => 'schedule', 'label' => trim(($run->planned_time ?? '') . ' ' . ($run ? '«' . $run->name . '»' : ''))]
                : ['source' => 'request', 'label' => (string) $r->number];
        }

        return $out;
    }
}

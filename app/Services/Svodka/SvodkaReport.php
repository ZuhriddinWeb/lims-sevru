<?php

namespace App\Services\Svodka;

use App\Models\Element;
use App\Models\SvodkaSection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bir kunlik svodka — FAQAT buyurtmachi uchun (nuqta nomlari bilan). [Paket 29]
 * Qiymatlar faqat laboratoriya boshlig'i chiqargan nusxadan olinadi; chiqarilmagan davr — bo'sh.
 */
class SvodkaReport
{
    public function __construct(private SvodkaService $svc)
    {
    }

    public function day(int $deptId, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $set = $this->svc->settings($deptId);
        $sections = SvodkaSection::where('customer_department_id', $deptId)->with('points')
            ->orderBy('sort_order')->orderBy('id')->get();
        $samples = $this->svc->periodSamples($deptId, $date, null);
        $map = $this->svc->mappings($samples);
        $rel = [1 => $this->svc->coveringRelease($deptId, $date, 1), 2 => $this->svc->coveringRelease($deptId, $date, 2)];

        // Hali qabul qilinmagan (yuborilgan buyurtmadagi) namunalar — faqat shifr, «kutilmoqda»
        $planned = DB::table('sample_request_items')
            ->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
            ->where('sample_requests.customer_department_id', $deptId)
            ->whereDate('sample_requests.svodka_date', $date)
            ->whereIn('sample_requests.status', ['submitted', 'partial', 'expired'])
            ->where('sample_request_items.status', 'pending')
            ->whereNotNull('sample_request_items.svodka_point_id')
            ->get(['sample_request_items.id', 'sample_request_items.customer_code', 'sample_request_items.svodka_point_id',
                'sample_requests.svodka_shift', 'sample_requests.number']);

        $entries = [];
        $unassigned = [];
        $periodFlags = [1 => ['samples' => 0, 'released' => 0, 'waiting' => 0], 2 => ['samples' => 0, 'released' => 0, 'waiting' => 0]];
        foreach ($samples as $s) {
            $sid = (int) $s->id;
            $m = $map[$sid];
            $snap = $rel[$m['shift']]?->payload[(string) $sid] ?? null;
            $finals = SvodkaService::finals($s);
            $periodFlags[$m['shift']]['samples']++;
            if ($snap !== null) {
                $periodFlags[$m['shift']]['released']++;
            }
            if ($finals && ($snap === null || $finals != $snap)) {
                $periodFlags[$m['shift']]['waiting']++;      // natija bor, lekin hali chiqarilmagan / tuzatilgan
            }
            $entry = [
                'sample_id' => $sid,
                'shift'     => $m['shift'],
                'code'      => $s->customer_code ?: $s->sample_number,
                'values'    => $snap === null ? null : array_map(fn ($v) => (float) $v[0], $snap),
                'pending'   => false,
                'released'  => $snap !== null,
                'source'    => $m['source'],
            ];
            if ($m['point_id']) {
                $entries[$m['point_id']][] = $entry;
            } else {
                $unassigned[] = $entry + [
                    'lab_code'      => $s->code,
                    'registered_at' => $s->registered_at?->toDateTimeString(),
                    'elements'      => $s->sampleElements->map(fn ($se) => $se->element?->symbol)->filter()->values(),
                ];
            }
        }
        foreach ($planned as $p) {
            $entries[(int) $p->svodka_point_id][] = [
                'sample_id' => null, 'shift' => in_array((int) $p->svodka_shift, [1, 2], true) ? (int) $p->svodka_shift : null,
                'code' => $p->customer_code, 'values' => null, 'pending' => true, 'released' => false, 'source' => 'planned',
            ];
        }

        $elementIds = $sections->flatMap(fn ($sec) => collect($sec->columns ?? [])->pluck('element_id'))->map(fn ($v) => (int) $v)->unique()->values()->all();
        $elements = $elementIds ? Element::whereIn('id', $elementIds)->get(['id', 'symbol', 'default_unit'])->keyBy('id') : collect();

        $outSections = [];
        foreach ($sections as $sec) {
            $used = $sec->points->contains(fn ($p) => ! empty($entries[(int) $p->id]));
            if (! $sec->is_active && ! $used) {
                continue;
            }
            $cols = collect($sec->columns ?? [])->map(function ($c) use ($elements) {
                $e = $elements->get((int) ($c['element_id'] ?? 0));

                return [
                    'element_id' => (int) ($c['element_id'] ?? 0),
                    'label'      => trim((string) ($c['label'] ?? '')) ?: ($e ? $e->symbol . ($e->default_unit ? ',' . $e->default_unit : '') : '?'),
                ];
            })->filter(fn ($c) => $c['element_id'] > 0)->values()->all();
            $points = $sec->points->filter(fn ($p) => $p->is_active || ! empty($entries[(int) $p->id]))->values();

            $rows = [];
            if ($sec->layout === 'list') {
                foreach ($points as $p) {
                    foreach ($entries[(int) $p->id] ?? [] as $e) {
                        $rows[] = ['point_id' => (int) $p->id, 'name' => $p->name, 'shift' => $e['shift'], 'code' => $e['code'],
                            'sample_id' => $e['sample_id'], 'released' => $e['released'], 'source' => $e['source'],
                            'pending' => $e['pending'], 'values' => $this->pick($cols, $e['values'])];
                    }
                }
            } else {
                foreach ($points as $p) {
                    $list = $entries[(int) $p->id] ?? [];
                    $row = ['point_id' => (int) $p->id, 'name' => $p->name, 'daily' => (bool) $p->daily, 'active' => (bool) $p->is_active];
                    if ($sec->layout === 'shift') {
                        foreach ([1, 2] as $sh) {
                            $cell = array_values(array_filter($list, fn ($e) => $e['shift'] === $sh));
                            $row['shifts'][$sh] = $this->cell($cols, $cell);
                        }
                        $row['avg'] = [];
                        foreach ($cols as $c) {
                            $vals = array_values(array_filter([$row['shifts'][1]['values'][$c['element_id']] ?? null, $row['shifts'][2]['values'][$c['element_id']] ?? null], fn ($v) => $v !== null));
                            $row['avg'][$c['element_id']] = $vals ? round(array_sum($vals) / count($vals), 6) : null;
                        }
                    } else {
                        $row += $this->cell($cols, $list);
                    }
                    $rows[] = $row;
                }
            }

            $outSections[] = [
                'id' => (int) $sec->id, 'title' => $sec->title, 'layout' => $sec->layout, 'active' => (bool) $sec->is_active,
                'columns' => $cols, 'rows' => $rows,
            ];
        }

        $relInfo = fn ($r) => $r ? ['id' => (int) $r->id, 'revision' => (int) $r->revision, 'shift' => $r->shift,
            'released_at' => $r->released_at?->toDateTimeString()] : null;

        return [
            'department_id' => $deptId,
            'date'          => $date,
            'settings'      => [
                'release_mode' => $set->release_mode,
                'shift1_label' => $set->shift1_label, 'shift2_label' => $set->shift2_label,
                'shift1_hours' => $set->shift1_start . '–' . $set->shift2_start,
                'shift2_hours' => $set->shift2_start . '–' . $set->shift1_start,
                'title'        => $set->title,
            ],
            'periods'       => [
                1 => ['release' => $relInfo($rel[1])] + $periodFlags[1],
                2 => ['release' => $relInfo($rel[2])] + $periodFlags[2],
            ],
            'sections'      => $outSections,
            'unassigned'    => $unassigned,
        ];
    }

    /** Katak: barcha shifrlar; qiymat — oxirgi chiqarilgan namunadan */
    private function cell(array $cols, array $list): array
    {
        $values = null;
        foreach ($list as $e) {
            if ($e['values'] !== null) {
                $values = $e['values'];
            }
        }

        return [
            'codes'   => array_map(fn ($e) => ['code' => $e['code'], 'pending' => $e['pending'], 'sample_id' => $e['sample_id'],
                'released' => $e['released'], 'source' => $e['source']], $list),
            'values'  => $this->pick($cols, $values),
        ];
    }

    private function pick(array $cols, ?array $values): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[$c['element_id']] = $values[(string) $c['element_id']] ?? null;
        }

        return $out;
    }
}

<?php

namespace App\Services\Samples;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Buyurtmachi portalida nima ko'rinishi — admin sozlaydi («Portal sozlamalari»). [Paket 27]
 *
 * Yashirish SERVERDA bajariladi: o'chirilgan ma'lumot portal javobidan butunlay olib tashlanadi
 * (brauzerga umuman yuborilmaydi). Laboratoriya xodimining «ko'rib chiqish» rejimi ham xuddi
 * buyurtmachi ko'radigan holatni ko'rsatadi.
 */
class PortalVisibility
{
    public const KEY = 'portal.visibility';
    private const CACHE = 'portal.visibility.v1';

    /** Standart — avvalgi holat (hammasi ochiq); admin keragini o'chiradi */
    public const DEFAULTS = [
        'steps'            => true,   // namuna bosqichlari (qabul → tahlil → tekshiruv → tayyor)
        'final_results'    => true,   // tasdiqlangan natijalar
        'preliminary'      => true,   // dastlabki natijalar (tasdiqlanmagan ishchi varaqdan)
        'quantity'         => true,   // namuna miqdori: topshirilgan / sarflangan / qoldiq
        'analyses'         => true,   // o'tkazilgan tahlillar: varaq raqami, shablon, sana, holati
        'analysis_details' => true,   // varaq ichi: o'lchov qiymatlari (parallellar), parametrlar, bajarilgan bosqichlar
        'comparison'       => true,   // nazorat tahlili bilan solishtirish
        'totals'           => true,   // [P28] varaq bo'yicha jami (korotochkalar yig'indisi) — faqat o'z namunalari bo'yicha
        'export'           => true,   // natijalarni Excel'ga yuklab olish
    ];

    private ?array $cached = null;

    public function get(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        try {
            $stored = Cache::rememberForever(self::CACHE, fn () => $this->read());
        } catch (Throwable $e) {
            // kesh ishlamasa (masalan, cache jadvali yo'q) — to'g'ridan-to'g'ri bazadan; sozlama e'tiborsiz qolmasin
            report($e);
            $stored = $this->read();
        }
        $out = self::DEFAULTS;
        foreach ($stored as $k => $v) {
            if (array_key_exists($k, $out)) {
                $out[$k] = (bool) $v;
            }
        }

        return $this->cached = $out;
    }

    private function read(): array
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return [];
            }
            $raw = AppSetting::where('key', self::KEY)->value('value');
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    public function on(string $key): bool
    {
        return (bool) ($this->get()[$key] ?? false);
    }

    public function set(array $values, User $user): array
    {
        $current = $this->get();
        foreach (self::DEFAULTS as $k => $_) {
            if (array_key_exists($k, $values)) {
                $current[$k] = (bool) $values[$k];
            }
        }
        AppSetting::updateOrCreate(['key' => self::KEY], [
            'value' => json_encode($current), 'updated_by' => $user->id,
        ]);
        try {
            Cache::forget(self::CACHE);
        } catch (Throwable $e) {
            report($e);
        }
        $this->cached = null;

        return $this->get();
    }

    public function meta(): array
    {
        $row = Schema::hasTable('app_settings') ? AppSetting::where('key', self::KEY)->first() : null;

        return [
            'updated_at' => $row?->updated_at?->toDateTimeString(),
            'updated_by' => $row?->updated_by ? User::withTrashed()->find($row->updated_by)?->name : null,
        ];
    }

    /** Ro'yxat qatori (Mening probalarim) */
    public function filterListItem(array $item): array
    {
        if (! $this->on('steps')) {
            $item['stage'] = null;
            $item['steps'] = [];
            $item['worksheets'] = null;
        }
        // [P28] qatordagi natijalar
        if (isset($item['results'])) {
            $item['results'] = $this->filterResultChips($item['results']);
        }

        return $item;
    }

    /** [P28] Ro'yxatdagi natija belgilari: yopilgan qiymat yuborilmaydi */
    public function filterResultChips(array $chips): array
    {
        $v = $this->get();

        return array_map(function (array $c) use ($v) {
            if ($c['state'] === 'final' && ! $v['final_results']) {
                $c['value'] = null;
                $c['state'] = 'hidden';
            } elseif ($c['state'] === 'preliminary' && ! $v['preliminary']) {
                $c['value'] = null;
                $c['decimals'] = null;
                $c['worksheet'] = null;
                $c['state'] = 'waiting';
            }

            return $c;
        }, $chips);
    }

    /** [P28] Varaq bo'yicha jami: ko'rinadimi (sozlama + varaq holati bo'yicha) */
    public function totalsAllowed(array $t): bool
    {
        $v = $this->get();
        if (! $v['totals'] || ! $v['analyses']) {
            return false;
        }

        return ! empty($t['preliminary']) ? $v['preliminary'] : $v['final_results'];
    }

    /** [P28] Ro'yxat ostidagi «Varaqlar bo'yicha jami» */
    public function filterTotals(array $list): array
    {
        return array_values(array_filter($list, fn ($t) => $this->totalsAllowed($t)));
    }

    /** Bitta namuna sahifasi — CustomerPortalController::show() javobi */
    public function filterSample(array $p): array
    {
        $v = $this->get();

        if (! $v['steps']) {
            $p['steps'] = ['current' => null, 'steps' => [], 'worksheets' => null];
        }

        $p['results'] = collect($p['results'] ?? [])->map(function ($r) use ($v) {
            $r = is_array($r) ? $r : (array) $r;
            if (! $v['final_results'] && ($r['value'] ?? null) !== null) {
                $r['value'] = null;
                $r['entered_at'] = null;
                $r['hidden'] = true;      // natija bor, lekin ko'rsatilmaydi
            }
            if (! $v['preliminary']) {
                $r['preliminary'] = null;
            }

            return $r;
        })->values()->all();

        if (! $v['quantity']) {
            $p['quantity'] = null;
        }

        if (! $v['analyses']) {
            $p['analyses'] = [];
        } else {
            $p['analyses'] = array_map(function (array $b) use ($v) {
                if (! $v['analysis_details']) {
                    $b['rows'] = [];
                    $b['columns'] = [];
                    $b['params'] = [];
                    $b['stages'] = [];
                }
                $allowed = $b['preliminary'] ? $v['preliminary'] : $v['final_results'];
                if (! $allowed) {
                    $b['summary'] = (object) [];
                    $b['tolerance'] = (object) [];
                    $b['summary_columns'] = [];
                }
                // [P28] varaq bo'yicha jami
                if (array_key_exists('totals', $b) && ($b['totals'] === null || ! $this->totalsAllowed($b['totals']))) {
                    $b['totals'] = null;
                }

                return $b;
            }, $p['analyses'] ?? []);
        }

        if (! $v['comparison'] || ! $v['analyses']) {
            $p['comparison'] = [];
        } elseif (! $v['preliminary']) {
            // dastlabki qiymatlar ishtirok etgan solishtirishlar — faqat tasdiqlangan varaq va yakuniy natija bilan
            $p['comparison'] = array_values(array_filter(array_map(function ($c) {
                if (($c['status'] ?? null) !== 'approved') {
                    return null;
                }
                $c['items'] = array_values(array_filter($c['items'] ?? [], fn ($i) => ($i['main_source'] ?? null) === 'result'));

                return $c['items'] ? $c : null;
            }, $p['comparison'] ?? [])));
        }
        if (! $v['final_results'] && $p['comparison']) {
            $p['comparison'] = [];
        }

        $p['visibility'] = $v;

        return $p;
    }
}

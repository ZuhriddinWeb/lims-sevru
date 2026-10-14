<?php

namespace App\Services\Insights;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Hisobot davri va solishtirish davri.
 *
 *   shift       — joriy smena (smenalar sozlanmagan bo'lsa — bugun)
 *   prev_shift  — oldingi (tugagan) smena
 *   today, yesterday, week (dushanbadan), month, year
 *   custom      — date_from … date_to (kun bo'yicha, ikkalasi ham kiradi)
 *
 * Joriy davr hali tugamagan bo'lsa (masalan «shu oy»), solishtirish ADOLATLI bo'lishi uchun
 * oldingi davrning xuddi shuncha o'tgan qismi olinadi: 1–13 oktyabr ↔ 1–13 sentyabr.
 */
final class InsightPeriod
{
    public const PRESETS = ['shift', 'prev_shift', 'today', 'yesterday', 'week', 'month', 'year', 'custom'];

    public string $preset;
    public Carbon $from;
    public Carbon $to;          // davr oxiri (kelajakda bo'lishi mumkin)
    public Carbon $end;         // hisob oxiri = min(to, hozir)
    public Carbon $prevFrom;
    public Carbon $prevTo;
    public string $bucket;      // hour | day | month
    public ?array $shift = null;       // smena davrlari uchun: shu smena nusxasi
    public bool $shiftActive = false;  // hozir smena davom etyaptimi
    public bool $shiftMissing = false; // smena so'ralgan, lekin smenalar sozlanmagan

    public static function fromRequest(Request $request, ShiftCalendar $calendar, ?Carbon $now = null): self
    {
        $preset = (string) $request->input('period', 'month');
        if (! in_array($preset, self::PRESETS, true)) {
            $preset = 'month';
        }
        if ($preset === 'custom' && ! ($request->filled('date_from') && $request->filled('date_to'))) {
            $preset = 'month';
        }

        return self::make($preset, $calendar, $now, $request->input('date_from'), $request->input('date_to'));
    }

    public static function make(string $preset, ShiftCalendar $calendar, ?Carbon $now = null, ?string $dateFrom = null, ?string $dateTo = null): self
    {
        $now = ($now ?? now())->copy();
        $p = new self();
        $p->preset = $preset;

        if (in_array($preset, ['shift', 'prev_shift'], true)) {
            $current = $calendar->at($now);
            $instance = $preset === 'shift'
                ? ($current ?? $calendar->before($now))
                : $calendar->before($current ? $current['start'] : $now);

            if ($instance) {
                $p->shift = $instance;
                $p->shiftActive = $preset === 'shift' && $current !== null;
                $p->from = $instance['start']->copy();
                $p->to = $instance['end']->copy()->subSecond();
                $p->end = $p->to->lessThan($now) ? $p->to->copy() : $now->copy();

                // Solishtirish — xuddi shu smena bir kun oldin, shuncha o'tgan qismi (kunduzgi ↔ kechagi kunduzgi)
                $p->prevFrom = $p->from->copy()->subDay();
                $p->prevTo = $p->end->copy()->subDay();
                $p->bucket = 'hour';

                return $p;
            }

            $p->shiftMissing = true;
            $p->preset = $preset = $preset === 'shift' ? 'today' : 'yesterday';
        }

        switch ($preset) {
            case 'today':
                $p->from = $now->copy()->startOfDay();
                $p->to = $now->copy()->endOfDay();
                break;
            case 'yesterday':
                $p->from = $now->copy()->subDay()->startOfDay();
                $p->to = $now->copy()->subDay()->endOfDay();
                break;
            case 'week':
                $p->from = $now->copy()->startOfWeek(Carbon::MONDAY);
                $p->to = $p->from->copy()->addDays(6)->endOfDay();
                break;
            case 'year':
                $p->from = $now->copy()->startOfYear();
                $p->to = $now->copy()->endOfYear();
                break;
            case 'custom':
                try {
                    $p->from = Carbon::parse((string) $dateFrom)->startOfDay();
                    $p->to = Carbon::parse((string) $dateTo)->endOfDay();
                } catch (\Throwable) {
                    return self::make('month', $calendar, $now);
                }
                if ($p->to->lessThan($p->from)) {
                    [$p->from, $p->to] = [$p->to->copy()->startOfDay(), $p->from->copy()->endOfDay()];
                }
                // haddan oshiq oraliq (5 yildan ko'p) — qisqartiriladi
                if (self::seconds($p->from, $p->to) > 1830 * 86400) {
                    $p->from = $p->to->copy()->subDays(1830)->startOfDay();
                }
                break;
            default: // month
                $p->preset = 'month';
                $p->from = $now->copy()->startOfMonth();
                $p->to = $now->copy()->endOfMonth();
        }

        $p->end = $p->to->lessThan($now) ? $p->to->copy() : $now->copy();
        if ($p->end->lessThan($p->from)) {
            $p->end = $p->from->copy(); // kelajakdagi oraliq — bo'sh
        }

        // Solishtirish davri
        switch ($p->preset) {
            case 'today':
            case 'yesterday':
                $p->prevFrom = $p->from->copy()->subDay();
                $p->prevTo = $p->end->copy()->subDay();
                break;
            case 'week':
                $p->prevFrom = $p->from->copy()->subWeek();
                $p->prevTo = $p->end->copy()->subWeek();
                break;
            case 'month':
                $p->prevFrom = $p->from->copy()->subMonthNoOverflow();
                $p->prevTo = $p->end->copy()->subMonthNoOverflow();
                $limit = $p->prevFrom->copy()->endOfMonth();
                if ($p->prevTo->greaterThan($limit)) {
                    $p->prevTo = $limit;
                }
                break;
            case 'year':
                $p->prevFrom = $p->from->copy()->subYear();
                $p->prevTo = $p->end->copy()->subYearNoOverflow();
                break;
            default: // custom — xuddi shuncha uzunlikdagi oldingi oraliq
                [$p->prevFrom, $p->prevTo] = self::shiftBack($p->from, $p->end, self::seconds($p->from, $p->to) + 1);
        }

        $days = self::seconds($p->from, $p->to) / 86400;
        $p->bucket = $days < 2 ? 'hour' : ($days <= 93 ? 'day' : 'month');

        return $p;
    }

    /** Carbon 2 va 3 da bir xil: ikki vaqt orasidagi soniyalar (manfiy emas) */
    public static function seconds(Carbon $a, Carbon $b): int
    {
        return max(0, $b->getTimestamp() - $a->getTimestamp());
    }

    private static function shiftBack(Carbon $from, Carbon $end, int $lengthSeconds): array
    {
        return [
            $from->copy()->subSeconds($lengthSeconds),
            $end->copy()->subSeconds($lengthSeconds),
        ];
    }

    /** Davr ichidagi barcha bo'laklar kalitlari (grafik o'qi uchun) */
    public function bucketKeys(): array
    {
        $keys = [];
        $cursor = $this->from->copy();
        $guard = 0;
        if ($this->bucket === 'hour') {
            $cursor->minute(0)->second(0);
            while ($cursor->lessThanOrEqualTo($this->to) && $guard++ < 200) {
                $keys[] = $cursor->format('Y-m-d H');
                $cursor->addHour();
            }
        } elseif ($this->bucket === 'month') {
            $cursor->startOfMonth();
            while ($cursor->lessThanOrEqualTo($this->to) && $guard++ < 120) {
                $keys[] = $cursor->format('Y-m');
                $cursor->addMonthNoOverflow();
            }
        } else {
            $cursor->startOfDay();
            while ($cursor->lessThanOrEqualTo($this->to) && $guard++ < 400) {
                $keys[] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }
        }

        return $keys;
    }

    /** Bo'lak kelajakdami (grafikda bo'sh qoldiriladi, 0 emas) */
    public function bucketIsFuture(string $key): bool
    {
        $start = match ($this->bucket) {
            'hour'  => Carbon::createFromFormat('!Y-m-d H', $key),
            'month' => Carbon::createFromFormat('!Y-m', $key),
            default => Carbon::createFromFormat('!Y-m-d', $key),
        };

        return $start->greaterThan($this->end);
    }

    public function toArray(): array
    {
        return [
            'preset'        => $this->preset,
            'from'          => $this->from->format('Y-m-d H:i:s'),
            'to'            => $this->to->format('Y-m-d H:i:s'),
            'end'           => $this->end->format('Y-m-d H:i:s'),
            'prev_from'     => $this->prevFrom->format('Y-m-d H:i:s'),
            'prev_to'       => $this->prevTo->format('Y-m-d H:i:s'),
            'bucket'        => $this->bucket,
            'shift'         => ShiftCalendar::present($this->shift),
            'shift_active'  => $this->shiftActive,
            'shift_missing' => $this->shiftMissing,
        ];
    }
}

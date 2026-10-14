<?php

namespace App\Services\Insights;

use App\Models\LabShift;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratoriya smenalari jadvali.
 *
 *  - Smena «HH:MM — HH:MM». Tugash boshlanishdan kichik (yoki teng) bo'lsa smena yarim tundan o'tadi
 *    va u BOSHLANGAN kunga tegishli (13.10 20:00 — 14.10 08:00 → «13.10, Tungi smena»).
 *  - instance() — smenaning aniq sanadagi nusxasi: boshlanish/tugash vaqti bilan.
 *  - caseSql()  — SQL ichida vaqtni smenaga ajratish: id, yarim tundan keyingi qismi uchun −id, smenadan tashqari 0.
 */
class ShiftCalendar
{
    public const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private ?Collection $shifts = null;

    public function available(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('lab_shifts');
    }

    /** Faol smenalar (tartib bo'yicha) */
    public function shifts(): Collection
    {
        if ($this->shifts !== null) {
            return $this->shifts;
        }
        if (! $this->available()) {
            return $this->shifts = collect();
        }

        return $this->shifts = LabShift::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('start_time')->orderBy('id')
            ->get()
            ->filter(fn (LabShift $s) => self::validTime($s->start_time) && self::validTime($s->end_time))
            ->values();
    }

    public function forget(): void
    {
        $this->shifts = null;
    }

    public static function validTime(?string $v): bool
    {
        return is_string($v) && preg_match(self::TIME_RE, $v) === 1;
    }

    public static function toMinutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $h * 60 + $m;
    }

    /**
     * Ikki smena sutka ichida ustma-ust tushadimi (tugash vaqti kirmaydi).
     */
    public static function overlaps(string $a1, string $b1, string $a2, string $b2): bool
    {
        $segments = function (string $a, string $b): array {
            $s = self::toMinutes($a);
            $e = self::toMinutes($b);
            if ($e > $s) {
                return [[$s, $e]];
            }

            return array_values(array_filter([[$s, 1440], [0, $e]], fn ($x) => $x[1] > $x[0]));
        };
        foreach ($segments($a1, $b1) as $x) {
            foreach ($segments($a2, $b2) as $y) {
                if ($x[0] < $y[1] && $y[0] < $x[1]) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Smenaning $date kuni boshlangan nusxasi */
    public function instance(LabShift $shift, string $date): array
    {
        $start = Carbon::parse("{$date} {$shift->start_time}:00");
        $end = Carbon::parse("{$date} {$shift->end_time}:00");
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [
            'id'         => (int) $shift->id,
            'name'       => $shift->name,
            'start_time' => $shift->start_time,
            'end_time'   => $shift->end_time,
            'date'       => $date,
            'start'      => $start,
            'end'        => $end,
        ];
    }

    /** $t vaqtidagi smena nusxasi (yo'q bo'lsa null) */
    public function at(Carbon $t): ?array
    {
        foreach ($this->shifts() as $shift) {
            foreach ([$t->copy()->subDay()->toDateString(), $t->toDateString()] as $date) {
                $i = $this->instance($shift, $date);
                if ($t->greaterThanOrEqualTo($i['start']) && $t->lessThan($i['end'])) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** [from, to) oralig'i bilan kesishgan barcha smena nusxalari, boshlanish vaqti bo'yicha */
    public function instances(Carbon $from, Carbon $to): array
    {
        $out = [];
        if ($this->shifts()->isEmpty()) {
            return $out;
        }
        $day = $from->copy()->subDay()->startOfDay();
        $guard = 0;
        while ($day->lessThan($to) && $guard++ < 800) {
            foreach ($this->shifts() as $shift) {
                $i = $this->instance($shift, $day->toDateString());
                if ($i['end']->greaterThan($from) && $i['start']->lessThan($to)) {
                    $out[] = $i;
                }
            }
            $day->addDay();
        }
        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /** $pivot dan oldin TUGAGAN eng oxirgi smena */
    public function before(Carbon $pivot): ?array
    {
        $prev = null;
        foreach ($this->instances($pivot->copy()->subDays(3), $pivot) as $i) {
            if ($i['end']->lessThanOrEqualTo($pivot) && (! $prev || $i['end']->greaterThanOrEqualTo($prev['end']))) {
                $prev = $i;
            }
        }

        return $prev;
    }

    public function current(?Carbon $now = null): ?array
    {
        return $this->at($now ?? now());
    }

    public function previous(?Carbon $now = null): ?array
    {
        $now ??= now();
        $current = $this->at($now);

        return $this->before($current ? $current['start'] : $now);
    }

    /**
     * SQL: vaqt («HH:MM» ifodasi) → smena id; yarim tundan o'tgan smenaning ertangi qismi −id; smenadan tashqari 0.
     * Vaqtlar faqat bazadagi (tekshirilgan) qiymatlar — regex bilan qayta tekshiriladi.
     */
    public function caseSql(string $hmExpr): string
    {
        $parts = [];
        foreach ($this->shifts() as $shift) {
            $id = (int) $shift->id;
            $a = $shift->start_time;
            $b = $shift->end_time;
            if (! self::validTime($a) || ! self::validTime($b)) {
                continue;
            }
            if ($b > $a) {
                $parts[] = "WHEN {$hmExpr} >= '{$a}' AND {$hmExpr} < '{$b}' THEN {$id}";
            } else {
                $parts[] = "WHEN {$hmExpr} >= '{$a}' THEN {$id}";
                if ($b !== '00:00') {
                    $parts[] = "WHEN {$hmExpr} < '{$b}' THEN -{$id}";
                }
            }
        }

        return $parts ? 'CASE ' . implode(' ', $parts) . ' ELSE 0 END' : '0';
    }

    /** caseSql bilan bir xil qoida, PHP tomonda (sinov va bitta yozuv uchun) */
    public function codeFor(string $hm): int
    {
        foreach ($this->shifts() as $shift) {
            $a = $shift->start_time;
            $b = $shift->end_time;
            if ($b > $a) {
                if ($hm >= $a && $hm < $b) {
                    return (int) $shift->id;
                }
            } elseif ($hm >= $a) {
                return (int) $shift->id;
            } elseif ($b !== '00:00' && $hm < $b) {
                return -(int) $shift->id;
            }
        }

        return 0;
    }

    /** JSON uchun */
    public static function present(?array $i): ?array
    {
        if (! $i) {
            return null;
        }

        return [
            'id'         => $i['id'],
            'name'       => $i['name'],
            'start_time' => $i['start_time'],
            'end_time'   => $i['end_time'],
            'date'       => $i['date'],
            'start'      => $i['start']->format('Y-m-d H:i:s'),
            'end'        => $i['end']->format('Y-m-d H:i:s'),
        ];
    }

    public function list(): array
    {
        return $this->shifts()->map(fn (LabShift $s) => [
            'id'         => (int) $s->id,
            'name'       => $s->name,
            'start_time' => $s->start_time,
            'end_time'   => $s->end_time,
            'crosses_midnight' => $s->crossesMidnight(),
        ])->all();
    }
}

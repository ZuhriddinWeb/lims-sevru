<?php

namespace App\Services\Samples;

use App\Models\ActivityLog;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * [Paket 34] Buyurtmachi bo'linmasi xodimlari ma'lumotnomasi — «Kim olib keladi» va telefon.
 *
 *   - F.I.Sh. tozalanadi: ortiqcha probel, nuqtadan keyingi probel, apostrof turlari, bosh harflar;
 *   - solishtirish kaliti: kirill → lotin, kichik harf, tinish belgilarisiz («Рахимов Акмал» = «Rahimov Akmal»);
 *   - o'xshash yozuvlar (bosh harf / to'liq ism, so'zlar o'rni, 1–2 harf farqi, x/h, q/k, w/v …) — taklif sifatida
 *     qaytariladi, foydalanuvchi o'zi tanlaydi (server o'zi birlashtirmaydi);
 *   - telefon: mobil/shahar → +998 90 123-45-67; ichki raqam (2–7 raqam) — yozilganicha; boshqasi — ogohlantirish;
 *   - buyurtma yuborilganda va laboratoriya qabul qilganda remember(): yangi xodim qo'shiladi, telefon o'zgargan
 *     bo'lsa — yangilanadi (eski raqam jurnalga yoziladi).
 */
class CustomerContacts
{
    private const CYR = [
        'щ' => 'sh', 'ш' => 'sh', 'ч' => 'ch', 'ж' => 'j', 'ц' => 'ts', 'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya',
        'ў' => "o'", 'ғ' => "g'", 'қ' => 'q', 'ҳ' => 'h', 'х' => 'x', 'ъ' => '', 'ь' => '', 'ы' => 'i', 'э' => 'e', 'й' => 'y',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'з' => 'z', 'и' => 'i', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
    ];

    /** Kichik harfda qoladigan qo'shimchalar (otasining ismidan keyin) */
    private const LOWER_WORDS = ["o'g'li", "og'li", 'ogli', 'ugli', 'qizi', 'кизи', 'қизи', 'угли', 'ўғли'];

    public static function enabled(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('customer_contacts');
    }

    /* ================================================================ normalizatsiya */

    /** F.I.Sh. ni tozalash (ma'no o'zgarmaydi — faqat yozilish) */
    public static function cleanName(?string $s): string
    {
        $s = (string) $s;
        $s = str_replace(["\u{02BB}", "\u{02BC}", "\u{2018}", "\u{2019}", '`', "\u{00B4}"], "'", $s);
        $s = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($s)) ?? '';
        $s = preg_replace('/\s*\.\s*/u', '. ', $s) ?? '';          // «A.A.Rahimov» → «A. A. Rahimov»
        $s = preg_replace("/\s*'\s*/u", "'", $s) ?? '';            // «O ' rinov» → «O'rinov»
        $s = preg_replace('/\s*-\s*/u', '-', $s) ?? '';
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        $words = array_map(function (string $w) {
            if (in_array(mb_strtolower($w), self::LOWER_WORDS, true)) {
                return mb_strtolower($w);
            }

            return implode('-', array_map(function (string $part) {
                $low = mb_strtolower($part);
                $up = mb_strtoupper($part);
                if ($part !== $low && $part !== $up) {
                    return $part; // aralash yozuv (masalan «McLaren») — tegilmaydi
                }

                return mb_strtoupper(mb_substr($low, 0, 1)) . mb_substr($low, 1);
            }, explode('-', $w)));
        }, explode(' ', $s));

        return mb_substr(implode(' ', $words), 0, 191);
    }

    /** Lotin yozuviga (o'zbek imlosi), kichik harf */
    public static function translit(string $s): string
    {
        $s = mb_strtolower($s);

        return strtr($s, self::CYR);
    }

    /** So'zlar (kalit uchun): lotin, kichik harf, apostrof va nuqtasiz */
    public static function tokens(string $name): array
    {
        $s = self::translit(self::cleanName($name));
        $s = str_replace(["'", '.'], '', $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';

        return array_values(array_filter(explode(' ', trim($s)), fn ($w) => $w !== ''));
    }

    /** Solishtirish kaliti: bo'linma ichida yagona */
    public static function nameKey(?string $name): string
    {
        return mb_substr(implode(' ', self::tokens((string) $name)), 0, 191);
    }

    /** Yozilish kaliti (yozuv o'zgarmagan): faqat probel/harf kattaligi/nuqta farqi — avtomatik tuzatiladi */
    public static function formatKey(?string $name): string
    {
        $s = mb_strtolower(self::cleanName($name));

        return trim(preg_replace('/\s+/u', ' ', str_replace(["'", '.'], '', $s)) ?? '');
    }

    /** O'xshashlik uchun «tovush» ko'rinishi: x/kh/h, q/k, w/v, o'→u, iy→i, qo'sh harflar */
    public static function fold(string $token): string
    {
        $t = strtr($token, ['kh' => 'h', 'x' => 'h', 'q' => 'k', 'w' => 'v', 'ts' => 's', 'iy' => 'i', 'yo' => 'o', 'yu' => 'u', 'ya' => 'a']);

        return preg_replace('/(.)\1+/u', '$1', $t) ?? $t;
    }

    /**
     * Telefon: ['display' => ko'rinishi, 'digits' => raqamlar, 'kind' => mobile|internal|other|'']
     *   9 raqam / 998… / 8… (10 raqam) → +998 90 123-45-67;  2–7 raqam → ichki (yozilganicha);  boshqasi → other
     */
    public static function phone(?string $s): array
    {
        $t = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $s) ?? '');
        if ($t === '') {
            return ['display' => '', 'digits' => '', 'kind' => ''];
        }
        $d = preg_replace('/\D+/', '', $t) ?? '';
        $n = null;
        if (strlen($d) === 12 && str_starts_with($d, '998')) {
            $n = substr($d, 3);
        } elseif (strlen($d) === 9) {
            $n = $d;
        } elseif (strlen($d) === 10 && $d[0] === '8') {
            $n = substr($d, 1);
        }
        if ($n !== null && ! preg_match('/[^\d\s\-+()]/', $t)) {
            return [
                'display' => sprintf('+998 %s %s-%s-%s', substr($n, 0, 2), substr($n, 2, 3), substr($n, 5, 2), substr($n, 7, 2)),
                'digits'  => '998' . $n,
                'kind'    => 'mobile',
            ];
        }
        if (strlen($d) >= 2 && strlen($d) <= 7 && preg_match('/^[\d\s\-()]+$/', $t)) {
            return ['display' => mb_substr($t, 0, 64), 'digits' => $d, 'kind' => 'internal'];
        }

        return ['display' => mb_substr($t, 0, 64), 'digits' => $d, 'kind' => 'other'];
    }

    /** Saqlash uchun telefon ko'rinishi (bo'sh — null) */
    public static function phoneValue(?string $s): ?string
    {
        $p = self::phone($s)['display'];

        return $p === '' ? null : $p;
    }

    /* ================================================================ o'xshashlik */

    /**
     * Yozilgan F.I.Sh. ni ma'lumotnoma bilan solishtirish.
     * @return array{name: string, exact: ?CustomerContact, same_format: bool, similar: array<int, array{contact: CustomerContact, reason: string}>}
     */
    public function match(int $deptId, ?string $name): array
    {
        $clean = self::cleanName($name);
        $out = ['name' => $clean, 'exact' => null, 'same_format' => false, 'similar' => []];
        $tok = self::tokens($clean);
        if (! $tok || ! self::enabled()) {
            return $out;
        }
        $key = implode(' ', $tok);
        $contacts = CustomerContact::where('customer_department_id', $deptId)->get();

        $exact = $contacts->firstWhere('name_key', $key);
        if ($exact) {
            $out['exact'] = $exact;
            $out['same_format'] = self::formatKey($exact->full_name) === self::formatKey($clean);
        }

        $found = [];
        foreach ($contacts as $c) {
            if ($exact && $c->id === $exact->id) {
                continue;
            }
            $reason = self::similarity($tok, self::tokens($c->full_name));
            if ($reason !== null) {
                $found[] = ['contact' => $c, 'reason' => $reason, 'rank' => [$reason === 'initials' ? 1 : 0, -$c->use_count]];
            }
        }
        usort($found, fn ($a, $b) => $a['rank'] <=> $b['rank']);
        $out['similar'] = array_map(fn ($f) => ['contact' => $f['contact'], 'reason' => $f['reason']], array_slice($found, 0, 3));

        return $out;
    }

    /** Ikki F.I.Sh. o'xshashmi: 'order' | 'spelling' | 'initials' | null */
    public static function similarity(array $a, array $b): ?string
    {
        if (! $a || ! $b || $a === $b) {
            return null;
        }
        $fa = array_map([self::class, 'fold'], $a);
        $fb = array_map([self::class, 'fold'], $b);

        $sa = $fa;
        $sb = $fb;
        sort($sa);
        sort($sb);
        if (count($a) === count($b) && $sa === $sb) {
            return $fa === $fb ? 'spelling' : 'order';
        }

        // har so'zda ko'pi bilan 1 harf farqi (5+ harfli so'zlarda), jami ko'pi bilan 2 — «Rahimow», «Aliev»;
        // «Karimov» ↔ «Rahimov» (2 harf) — boshqa odam
        if (count($a) === count($b)) {
            $total = 0;
            foreach ($fa as $i => $x) {
                $y = $fb[$i];
                if ($x === $y) {
                    continue;
                }
                $d = (strlen($x) < 64 && strlen($y) < 64) ? levenshtein($x, $y) : 99;
                if ($d > 1 || min(strlen($x), strlen($y)) < 5) {
                    $total = 99;
                    break;
                }
                $total += $d;
            }
            if ($total <= 2) {
                return 'spelling';
            }
        }

        // bosh harf ↔ to'liq ism: «Rahimov A.» ↔ «Rahimov Akmal», «Rahimov» ↔ «Rahimov Akmal», «A. Rahimov» ↔ «Rahimov Akmal»
        foreach ([$fa, self::rotate($fa)] as $x) {
            if (self::initialsMatch($x, $fb)) {
                return 'initials';
            }
        }

        return null;
    }

    private static function rotate(array $t): array
    {
        if (count($t) < 2) {
            return $t;
        }
        $last = array_pop($t);
        array_unshift($t, $last);

        return $t;
    }

    private static function initialsMatch(array $a, array $b): bool
    {
        if ($a[0] !== $b[0] && levenshtein($a[0], $b[0]) > (strlen($a[0]) >= 7 ? 1 : 0)) {
            return false; // familiya mos emas
        }
        $n = min(count($a), count($b));
        $abbrev = count($a) !== count($b);
        for ($i = 1; $i < $n; $i++) {
            $x = $a[$i];
            $y = $b[$i];
            if ($x === $y) {
                continue;
            }
            $short = strlen($x) <= strlen($y) ? $x : $y;
            $long = strlen($x) <= strlen($y) ? $y : $x;
            if (mb_strlen($short) === 1 && str_starts_with($long, $short)) {
                $abbrev = true;
                continue;
            }

            return false;
        }

        return $abbrev;
    }

    /* ================================================================ ro'yxat va yozish */

    public function list(int $deptId): Collection
    {
        if (! self::enabled()) {
            return collect();
        }

        return CustomerContact::where('customer_department_id', $deptId)
            ->orderByDesc('use_count')->orderByDesc('last_used_at')->orderBy('full_name')
            ->limit(1000)->get();
    }

    public static function present(CustomerContact $c): array
    {
        return [
            'id'           => $c->id,
            'name'         => $c->full_name,
            'key'          => $c->name_key,
            'phone'        => $c->phone,
            'use_count'    => $c->use_count,
            'last_used_at' => $c->last_used_at?->toIso8601String(),
        ];
    }

    /**
     * Buyurtma yuborilganda / qabulda: xodimni eslab qolish.
     *   yangi F.I.Sh. → qo'shiladi; bor → ishlatilish soni; telefon o'zgargan → yangilanadi (eski raqam jurnalga).
     * @return array{contact: CustomerContact, created: bool, phone_changed: bool, old_phone: ?string}|null
     */
    public function remember(int $deptId, ?string $name, ?string $phone, ?User $by, string $source = 'request'): ?array
    {
        if (! $deptId || ! self::enabled()) {
            return null;
        }
        $clean = self::cleanName($name);
        $key = self::nameKey($clean);
        if ($key === '') {
            return null;
        }
        $p = self::phone($phone);

        try {
            $c = CustomerContact::where('customer_department_id', $deptId)->where('name_key', $key)->first();
            if (! $c) {
                try {
                    $c = CustomerContact::create([
                        'customer_department_id' => $deptId,
                        'full_name'              => $clean,
                        'name_key'               => $key,
                        'phone'                  => $p['display'] ?: null,
                        'use_count'              => 1,
                        'last_used_at'           => now(),
                        'created_by'             => $by?->id,
                        'updated_by'             => $by?->id,
                    ]);
                    $this->log($by, $c, "Bo'linma xodimi qo'shildi: {$clean}" . ($p['display'] ? " ({$p['display']})" : '') . " [{$source}]");

                    return ['contact' => $c, 'created' => true, 'phone_changed' => false, 'old_phone' => null];
                } catch (QueryException $e) {
                    // bir vaqtda ikki so'rov — ikkinchisi mavjudini oladi
                    $c = CustomerContact::where('customer_department_id', $deptId)->where('name_key', $key)->first();
                    if (! $c) {
                        throw $e;
                    }
                }
            }

            $old = $c->phone;
            $changed = $p['display'] !== '' && self::phone($old)['digits'] !== $p['digits'];
            $c->forceFill([
                'use_count'    => $c->use_count + 1,
                'last_used_at' => now(),
                'updated_by'   => $by?->id ?? $c->updated_by,
            ] + ($changed ? ['phone' => $p['display']] : []))->save();
            if ($changed) {
                $this->log($by, $c, "Bo'linma xodimi telefoni yangilandi: {$c->full_name}: " . ($old ?: '—') . " → {$p['display']} [{$source}]");
            }

            return ['contact' => $c, 'created' => false, 'phone_changed' => $changed, 'old_phone' => $changed ? $old : null];
        } catch (Throwable $e) {
            report($e); // ma'lumotnoma yozilmasa ham buyurtma ishlayversin

            return null;
        }
    }

    public function log(?User $by, CustomerContact $c, string $description): void
    {
        try {
            ActivityLog::create([
                'user_id'     => $by?->id,
                'action'      => 'updated',
                'model_type'  => 'CustomerContact',
                'model_id'    => $c->id,
                'description' => mb_substr($description, 0, 191),
                'properties'  => null,
                'ip_address'  => app()->runningInConsole() ? null : request()->ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\Services\Help;

use App\Models\HelpEntry;
use App\Models\HelpUnanswered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Yordamchi (lokal, internetsiz): bazadagi savol-javoblardan eng mosini topadi.
 * Qidiruv algoritmi — HelpMatcher/HelpText (Laravelga bog'liq emas); bu klass baza, kesh, ruxsat va jurnalni boshqaradi.
 * Foydalanuvchi $user = null bo'lsa (konsoldan sinash) — ruxsat tekshirilmaydi.
 */
class HelpAssistant
{
    public const CACHE_KEY = 'help.index.v1';
    public const LOCALES = ['uz', 'ru', 'en'];

    private const GREETINGS = [
        'salom', 'assalom', 'assalomu alaykum', 'assalomu aleykum', 'hayrli kun', 'hayrli tong', 'hello', 'hi', 'hey',
        'good morning', 'good day', 'privet', 'zdravstvuyte', 'zdravstvuy', 'dobriy den', 'dobroe utro',
    ];

    private const THANKS = [
        'rahmat', 'raxmat', 'katta rahmat', 'rahmat sizga', 'tashakkur', 'thanks', 'thank you', 'thx', 'ty',
        'spasibo', 'blagodaryu', 'bolshoe spasibo',
    ];

    /** Qidiruv indeksi (keshda). Yozuv o'zgarganda forget() chaqiriladi. */
    public function data(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $entries = [];
            $meta = [];
            foreach (HelpEntry::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $r) {
                $entry = ['id' => $r->id, 'keywords' => $r->keywords ?? []];
                foreach (self::LOCALES as $l) {
                    $entry[$l] = ['q' => (string) $r->{'q_'.$l}, 'alt' => $r->{'alt_'.$l} ?? [], 'a' => (string) $r->{'a_'.$l}];
                }
                $entries[] = $entry;
                $meta[$r->id] = ['route' => $r->route, 'permission' => $r->permission, 'top' => (bool) $r->is_top];
            }

            return ['index' => HelpMatcher::buildIndex($entries), 'meta' => $meta];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Foydalanuvchiga ruxsat etilgan yozuvlarni qoldiruvchi funksiya */
    private function allowFor($user, array $meta): callable
    {
        $memo = [];

        return function (int $id) use ($user, $meta, &$memo): bool {
            $perm = $meta[$id]['permission'] ?? null;
            if (! $perm || ! $user) {
                return true;
            }

            // «a,b» — ro'yxatdagilardan bittasi bo'lsa yetarli
            return $memo[$perm] ??= (function () use ($user, $perm): bool {
                foreach (explode(',', $perm) as $p) {
                    if ($p !== '' && $user->hasPermission($p)) {
                        return true;
                    }
                }

                return false;
            })();
        };
    }

    private function smalltalk(string $folded): ?string
    {
        if (in_array($folded, self::GREETINGS, true)) {
            return 'greeting';
        }
        if (in_array($folded, self::THANKS, true)) {
            return 'thanks';
        }

        return null;
    }

    /**
     * @return array{status:string, kind?:string, answer:?array, related:array<int,array>}
     */
    public function ask($user, string $question, string $locale, ?string $route = null): array
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'uz';
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        $folded = HelpText::fold($question);

        if ($kind = $this->smalltalk($folded)) {
            return ['status' => 'smalltalk', 'kind' => $kind, 'answer' => null, 'related' => []];
        }

        $data = $this->data();
        $ranked = HelpMatcher::search($data['index'], $question, $route, $data['meta'], $this->allowFor($user, $data['meta']), 6);
        $verdict = HelpMatcher::verdict($ranked);

        $answerId = null;
        $relatedIds = [];
        if ($verdict === 'answer') {
            $answerId = $ranked[0]['id'];
            foreach (array_slice($ranked, 1) as $r) {
                if ($r['score'] >= 0.45 && count($relatedIds) < 3) {
                    $relatedIds[] = $r['id'];
                }
            }
        } elseif ($verdict === 'suggest') {
            foreach ($ranked as $r) {
                if ($r['score'] >= HelpMatcher::MIN_SUGGEST && count($relatedIds) < 4) {
                    $relatedIds[] = $r['id'];
                }
            }
        }

        if ($verdict !== 'answer') {
            $this->logUnanswered($user, $question, $folded, $locale, $route, $ranked[0] ?? null);
        }

        $rows = HelpEntry::whereIn('id', array_filter(array_merge([$answerId], $relatedIds)))->get()->keyBy('id');

        return [
            'status' => $verdict,
            'answer' => $answerId && isset($rows[$answerId]) ? $this->present($rows[$answerId], $locale) : null,
            'related' => collect($relatedIds)->filter(fn ($id) => isset($rows[$id]))
                ->map(fn ($id) => ['id' => $id, 'question' => $rows[$id]->{'q_'.$locale}])->values()->all(),
        ];
    }

    /** Tanlangan taklif (chip) yoki «yana shu haqida» — to'liq javob; ruxsat bo'lmasa null */
    public function entry($user, int $id, string $locale): ?array
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'uz';
        $meta = $this->data()['meta'];
        if (! isset($meta[$id]) || ! ($this->allowFor($user, $meta))($id)) {
            return null;
        }
        $row = HelpEntry::where('is_active', true)->find($id);

        return $row ? $this->present($row, $locale) : null;
    }

    /**
     * Boshlang'ich takliflar: joriy sahifaga oid yozuvlar, keyin umumiy «top» savollar.
     *
     * @return array<int,array{id:int,question:string}>
     */
    public function suggestions($user, string $locale, ?string $route = null, int $limit = 6): array
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'uz';
        $meta = $this->data()['meta'];
        $allow = $this->allowFor($user, $meta);

        $here = [];
        $top = [];
        foreach ($meta as $id => $m) {
            if (! $allow($id)) {
                continue;
            }
            if ($route && $m['route'] === $route) {
                $here[] = $id;
            }
            if ($m['top']) {
                $top[] = $id;
            }
        }
        // sahifaga oid savollardan ko'pi bilan 4 tasi, qolganini umumiylar to'ldiradi
        $ids = array_slice(array_values(array_unique(array_merge(array_slice($here, 0, 4), $top))), 0, $limit);
        $rows = HelpEntry::whereIn('id', $ids)->get(['id', 'q_'.$locale])->keyBy('id');

        return collect($ids)->filter(fn ($id) => isset($rows[$id]))
            ->map(fn ($id) => ['id' => $id, 'question' => $rows[$id]->{'q_'.$locale}])->values()->all();
    }

    public function feedback($user, int $entryId, bool $helpful, ?string $question, string $locale): void
    {
        DB::table('help_feedback')->insert([
            'entry_id' => $entryId,
            'user_id' => $user?->id,
            'helpful' => $helpful,
            'question' => $question ? mb_substr($question, 0, 400) : null,
            'locale' => $locale,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function present(HelpEntry $e, string $locale): array
    {
        return [
            'id' => $e->id,
            'slug' => $e->slug,
            'section' => $e->section,
            'route' => $e->route,
            'type' => $e->type,
            'question' => $e->{'q_'.$locale},
            // eski import fayllaridagi «\n» belgilari ham qator uzilishi bo'lsin
            'text' => str_replace('\\n', "\n", (string) $e->{'a_'.$locale}),
        ];
    }

    private function logUnanswered($user, string $question, string $folded, string $locale, ?string $route, ?array $best): void
    {
        if (mb_strlen($folded) < 3) {
            return;
        }
        try {
            $row = HelpUnanswered::firstOrNew(['question_hash' => sha1($folded)]);
            $row->fill([
                'question' => mb_substr($question, 0, 400),
                'locale' => $locale,
                'route' => $route ? mb_substr($route, 0, 80) : null,
                'user_id' => $user?->id,
                'best_entry_id' => $best['id'] ?? null,
                'best_score' => $best['score'] ?? 0,
                'last_asked_at' => now(),
            ]);
            if ($row->exists) {
                $row->asked_count = (int) $row->asked_count + 1;
                if ($row->status === 'resolved') {
                    $row->status = 'new'; // javob bergan bo'lsak ham yana topilmadi — qayta ko'rib chiqish kerak
                }
            } else {
                $row->asked_count = 1;
                $row->status = 'new';
            }
            $row->save();
        } catch (\Throwable $e) {
            report($e); // jurnal yozilmasa ham foydalanuvchi javob oladi
        }
    }
}

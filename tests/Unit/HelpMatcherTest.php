<?php

namespace Tests\Unit;

use App\Services\Help\HelpMatcher;
use App\Services\Help\HelpText;
use PHPUnit\Framework\TestCase;

/** Yordamchi qidiruvi: bazasiz, faqat database/help/help_qa.json bilan. */
class HelpMatcherTest extends TestCase
{
    private static array $entries = [];
    private static array $slugs = [];
    private static array $meta = [];
    private static array $index = [];

    public static function setUpBeforeClass(): void
    {
        $raw = json_decode(file_get_contents(__DIR__.'/../../database/help/help_qa.json'), true);
        foreach ($raw as $i => $r) {
            $id = $i + 1;
            self::$slugs[$id] = $r['id'];
            $r['id'] = $id;
            self::$entries[] = $r;
            self::$meta[$id] = ['route' => $r['route'] ?? null];
        }
        self::$index = HelpMatcher::buildIndex(self::$entries);
    }

    private function top(string $q): ?string
    {
        $res = HelpMatcher::search(self::$index, $q, null, self::$meta, null, 3);

        return $res ? self::$slugs[$res[0]['id']] : null;
    }

    public function test_fold_unifies_scripts_and_apostrophes(): void
    {
        $this->assertSame(HelpText::fold("Qo'shish"), HelpText::fold('қўшиш'));
        $this->assertSame(HelpText::fold('ma’lumot'), HelpText::fold('маълумот'));
        $this->assertSame('parol', implode(' ', HelpText::tokens('Пароль')));
    }

    public function test_every_own_question_finds_its_entry(): void
    {
        $total = $hit = 0;
        foreach (self::$entries as $e) {
            foreach (HelpMatcher::LANGS as $lang) {
                foreach (array_merge([$e[$lang]['q']], $e[$lang]['alt'] ?? []) as $q) {
                    $res = HelpMatcher::search(self::$index, $q, null, self::$meta, null, 3);
                    $total++;
                    if (in_array($e['id'], array_column($res, 'id'), true)) {
                        $hit++;
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(0.99, $hit / $total);
    }

    public function test_real_world_phrasings(): void
    {
        $this->assertSame('general.forgot_password', $this->top('parolimni unutib qoldim'));
        $this->assertSame('samples.register', $this->top('как зарегистрировать пробу'));
        $this->assertSame('samples.register', $this->top('how to add a sample'));
        $this->assertSame('dep.not_linked', $this->top('buyurtmachi namunani ko‘rmayapti'));
        $this->assertSame('user.create', $this->top('новый пользователь как создать'));
    }

    public function test_unrelated_question_is_not_answered(): void
    {
        $res = HelpMatcher::search(self::$index, 'купить пиццу', null, self::$meta, null, 3);
        $this->assertSame('none', HelpMatcher::verdict($res));
    }

    public function test_permission_filter_hides_entries(): void
    {
        $res = HelpMatcher::search(self::$index, 'rolga ruxsat berish', null, self::$meta, fn () => false, 3);
        $this->assertSame([], $res);
    }
}

<?php
/**
 * Yordamchi matcherini Laravelsiz sinash:  php tests/help_eval.php [savol ...]
 *  - argument bo'lmasa: har yozuvning savoli va qo'shimcha so'rovlari (3 tilda) to'g'ri yozuvni topishini tekshiradi;
 *  - argument bo'lsa: shu savolga javob beradi.
 */
require __DIR__.'/../app/Services/Help/HelpText.php';
require __DIR__.'/../app/Services/Help/HelpMatcher.php';

use App\Services\Help\HelpMatcher;

$raw = json_decode(file_get_contents(__DIR__.'/../database/help/help_qa.json'), true);
$entries = [];
$meta = [];
$ids = [];
foreach ($raw as $i => $r) {
    $id = $i + 1;
    $ids[$id] = $r['id'];
    $r['id'] = $id;
    $entries[] = $r;
    $meta[$id] = ['route' => $r['route'] ?? null];
}
$t0 = microtime(true);
$index = HelpMatcher::buildIndex($entries);
printf("indeks: %d yozuv, %d so'z, %.0f ms\n", count($entries), count($index['idf']), (microtime(true) - $t0) * 1000);

if ($argc > 1) {
    foreach (array_slice($argv, 1) as $q) {
        $t0 = microtime(true);
        $res = HelpMatcher::search($index, $q, null, $meta, null, 5);
        printf("\n? %s  [%s, %.1f ms]\n", $q, HelpMatcher::verdict($res), (microtime(true) - $t0) * 1000);
        foreach ($res as $r) {
            printf("   %.2f  %s\n", $r['score'], $ids[$r['id']]);
        }
    }
    exit;
}

$tot = $ok = $okTop3 = 0;
$bad = [];
foreach ($entries as $e) {
    foreach (HelpMatcher::LANGS as $lang) {
        foreach (array_merge([$e[$lang]['q']], $e[$lang]['alt'] ?? []) as $q) {
            $res = HelpMatcher::search($index, $q, null, $meta, null, 3);
            $tot++;
            $top = $res[0]['id'] ?? null;
            $top3 = array_column($res, 'id');
            if ($top === $e['id']) { $ok++; }
            if (in_array($e['id'], $top3, true)) { $okTop3++; }
            else { $bad[] = [$ids[$e['id']], $lang, $q, $ids[$top] ?? '-']; }
        }
    }
}
printf("\nO'z so'rovlari: %d ta; 1-o'rin %.1f%%; 3 talikda %.1f%%\n", $tot, 100 * $ok / $tot, 100 * $okTop3 / $tot);
foreach (array_slice($bad, 0, 25) as $b) { printf("  XATO %-28s [%s] %s  -> %s\n", ...$b); }

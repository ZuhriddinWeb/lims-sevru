<?php

namespace App\Services\Worksheets;

use App\Models\Worksheet;
use App\Models\WorksheetTemplate;
use Throwable;

/**
 * «Varaq bo'yicha jami» — KO'RSATISH uchun. [Paket 28; shablon ustuvorligi — tuzatish]
 *
 * Varaq nusxasida (template_snapshot) jami ustuni bo'lmasa — u Paket 23 dan oldin yaratilgan yoki
 * tasdiqlangan (hech qachon o'zgartirilmaydi). Bunday varaqda jami ekranda, chop etishda va portalda
 * baribir ko'rinsin: nusxaga «virtual» jami ustuni qo'shiladi (bazadagi nusxa va natijalar O'ZGARMAYDI).
 *
 * QAYSI jami — admin shablonda belgilagani ustuvor:
 *   - nusxada jami bor              → nusxadagisi (varaq yaratilgan paytdagi qoida), hech narsa qo'shilmaydi;
 *   - shablonda hozir jami bor      → o'sha ustunlar (nomi, formulasi, birligi — admin qanday qilgan bo'lsa),
 *                                     faqat formulasi shu varaqdagi ustunlar bilan hisoblanadiganlari;
 *   - shablonda jami yo'q           → admin olib tashlagan: qo'shilmaydi;
 *   - shablon topilmasa             → standart: «kortochka» bo'lsa sum(kortochka).
 */
final class DisplayTotals
{
    public const KEY = 'jami_kortochka';
    public const SOURCE = 'kortochka';

    /** @var array<int, array|null> shablon ta'riflari (so'rov davomida) */
    private static array $templates = [];

    public static function column(): array
    {
        return [
            'scope'         => 'total',
            'key'           => self::KEY,
            'type'          => 'computed',
            'stage'         => null,
            'label_uz'      => "Korotochkalar yig'indisi, mg",
            'label_ru'      => 'Сумма корточек, мг',
            'label_uz_cyrl' => 'Корточкалар йиғиндиси, мг',
            'unit'          => 'mg',
            'decimals'      => 3,
            'rounding'      => 'round',
            'formula'       => 'sum(' . self::SOURCE . ')',
            'default_value' => null,
            'required'      => false,
            'element_id'    => null,
            'tolerance_pct' => null,
            'tolerance_source' => null,
            'tolerance_mode'   => 'mean',
            'width'         => null,
            'virtual'       => true,   // nusxada yo'q — faqat ko'rsatish uchun qo'shilgan
        ];
    }

    /** Varaq uchun: uning shabloni (o'chirilgan bo'lsa ham) hozirgi holatiga qarab */
    public static function forWorksheet(Worksheet $w, ?array $def = null): array
    {
        return self::augment($def ?? $w->definition(), self::templateDefinition((int) $w->template_id));
    }

    public static function templateDefinition(int $templateId): ?array
    {
        if ($templateId <= 0) {
            return null;
        }
        if (! array_key_exists($templateId, self::$templates)) {
            try {
                self::$templates[$templateId] = WorksheetTemplate::withTrashed()->with('columns')->find($templateId)?->toDefinition();
            } catch (Throwable $e) {
                report($e);
                self::$templates[$templateId] = null;
            }
        }

        return self::$templates[$templateId];
    }

    public static function forget(): void
    {
        self::$templates = [];
    }

    /**
     * @param  array       $def          varaq nusxasi
     * @param  array|null  $templateDef  shablonning hozirgi ta'rifi (null — shablon noma'lum)
     */
    public static function augment(array $def, ?array $templateDef = null): array
    {
        $cols = array_values($def['columns'] ?? []);
        $keys = [];
        $numeric = [];
        foreach ($cols as $c) {
            if (($c['scope'] ?? 'row') === 'total') {
                return $def;                       // nusxada jami bor — o'sha
            }
            $keys[$c['key'] ?? ''] = true;
            if (($c['scope'] ?? 'row') === 'row' && ($c['type'] ?? 'input') !== 'text') {
                $numeric[$c['key']] = true;
            }
        }
        foreach ($def['params'] ?? [] as $p) {
            $numeric[$p['key'] ?? ''] = true;
        }

        if ($templateDef !== null) {
            $add = [];
            foreach ($templateDef['columns'] ?? [] as $c) {
                if (($c['scope'] ?? 'row') !== 'total' || isset($keys[$c['key'] ?? ''])) {
                    continue;
                }
                try {
                    $ids = Formula::identifiers(Formula::parse((string) ($c['formula'] ?? '')));
                } catch (Throwable $e) {
                    continue;
                }
                $ok = true;
                foreach ($ids as $id) {
                    if (! isset($numeric[$id]) && ! isset($add[$id])) {
                        $ok = false;                // bu varaqda yo'q ustunga tayanadi — qo'shilmaydi
                        break;
                    }
                }
                if ($ok) {
                    $add[$c['key']] = ['virtual' => true] + $c;
                }
            }
            if ($add) {
                $def['columns'] = array_merge($cols, array_values($add));
            }

            return $def;                           // shablonda jami yo'q — admin olib tashlagan: qo'shilmaydi
        }

        // shablon noma'lum — standart: «kortochka» yig'indisi
        if (! isset($numeric[self::SOURCE]) || isset($keys[self::KEY])) {
            return $def;
        }
        $def['columns'] = $cols;
        $def['columns'][] = self::column();

        return $def;
    }

    /** Ta'rifdagi jami ustunlari (virtual bilan) */
    public static function columns(array $def, ?array $templateDef = null): array
    {
        return array_values(array_filter(self::augment($def, $templateDef)['columns'] ?? [], fn ($c) => ($c['scope'] ?? 'row') === 'total'));
    }
}

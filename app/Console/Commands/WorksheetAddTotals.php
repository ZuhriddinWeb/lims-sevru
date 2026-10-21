<?php

namespace App\Console\Commands;

use App\Models\Worksheet;
use App\Models\WorksheetTemplate;
use App\Models\WorksheetTemplateColumn;
use App\Services\Worksheets\TemplateValidator;
use App\Services\Worksheets\WorksheetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * «Varaq bo'yicha jami» (korotochkalar yig'indisi) ustunini u yo'q shablonlarga qo'shadi.
 *
 *   php artisan worksheets:add-totals --dry-run   — nima o'zgarishini ko'rsatadi, hech narsa yozmaydi
 *   php artisan worksheets:add-totals             — faqat shablonlar (yangi varaqlar uchun)
 *   php artisan worksheets:add-totals --open      — + tasdiqlanmagan mavjud varaqlar ham
 *
 * Qoidalar:
 *   - shablonda «total» ko'lamli ustun allaqachon bo'lsa — tegilmaydi (admin qo'shgan bo'lishi mumkin);
 *   - shablonda «kortochka» qator ustuni bo'lmasa — o'tkazib yuboriladi (muharrirda qo'lda qo'shiladi);
 *   - TASDIQLANGAN varaqlarning nusxasi (template_snapshot) hech qachon o'zgartirilmaydi.
 * Jami faqat ko'rsatish uchun: proba natijalari va o'rtacha qiymatlar o'zgarmaydi.
 * Qayta ishga tushirish xavfsiz.
 */
class WorksheetAddTotals extends Command
{
    protected $signature = 'worksheets:add-totals
        {--open : Tasdiqlanmagan mavjud varaqlarga ham qo\'shish}
        {--dry-run : Faqat ko\'rsatish, yozmaslik}';

    protected $description = "Ishchi varaq shablonlariga «Korotochkalar yig'indisi» jami ustunini qo'shadi";

    public const KEY = 'jami_kortochka';
    public const SOURCE = 'kortochka';

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
        ];
    }

    /** Ta'rifga jami qo'shish kerakmi: null — kerak; aks holda sabab */
    public static function reasonToSkip(array $def): ?string
    {
        $cols = $def['columns'] ?? [];
        foreach ($cols as $c) {
            if (($c['scope'] ?? 'row') === 'total') {
                return 'jami allaqachon bor';
            }
        }
        foreach ($cols as $c) {
            if (($c['scope'] ?? 'row') === 'row' && ($c['key'] ?? '') === self::SOURCE) {
                return null;
            }
        }

        return "«" . self::SOURCE . "» ustuni yo'q — muharrirda qo'lda qo'shing";
    }

    public function handle(WorksheetService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $open = (bool) $this->option('open');
        $changedTemplates = 0;
        $changedSheets = 0;

        foreach (WorksheetTemplate::with('columns')->orderBy('code')->get() as $tpl) {
            $def = $tpl->toDefinition();
            $skip = self::reasonToSkip($def);
            if ($skip !== null) {
                $this->line("  - {$tpl->code} {$tpl->name_uz}: {$skip}");
                continue;
            }

            $def['columns'][] = self::column();
            $errors = TemplateValidator::validate($def);
            if ($errors) {
                $this->warn("  ! {$tpl->code}: shablon tekshiruvdan o'tmadi — " . $errors[0]['message']);
                continue;
            }

            $this->info("  + {$tpl->code} {$tpl->name_uz}: jami qo'shiladi (sum(kortochka), mg)");
            $changedTemplates++;
            if (! $dry) {
                $col = self::column();
                unset($col['tolerance_pct']);
                WorksheetTemplateColumn::create($col + [
                    'template_id' => $tpl->id,
                    'sort_order'  => (int) $tpl->columns->max('sort_order') + 1,
                ]);
            }
        }

        // Mavjud varaqlar: shablondan qat'i nazar, nusxasida jami yo'q va kortochka bor bo'lsa
        $candidates = Worksheet::where('status', '!=', 'approved')->orderBy('id')->get();
        $eligible = $candidates->filter(fn (Worksheet $w) => self::reasonToSkip($w->definition()) === null);
        $approved = Worksheet::where('status', 'approved')->count();

        if (! $open) {
            $this->line('');
            $this->line("Tasdiqlanmagan varaqlardan jami yo'qlari: {$eligible->count()} ta. Ularga ham qo'shish uchun: --open");
        } else {
            foreach ($eligible as $w) {
                $changedSheets++;
                if ($dry) {
                    continue;
                }
                DB::transaction(function () use ($w, $service) {
                    $snap = $w->definition();
                    $snap['columns'][] = self::column();
                    $w->template_snapshot = $snap;
                    $w->save();
                    $service->recalculate($w->fresh('rows'));
                });
            }
            $this->line('');
            $this->line("Varaqlar: {$changedSheets} ta" . ($dry ? " o'zgaradi" : ' yangilandi'));
        }
        $this->line("Tasdiqlangan varaqlar ({$approved} ta) o'zgartirilmadi.");
        $this->info(($dry ? '[sinov] ' : '') . "Shablonlar: {$changedTemplates} ta.");

        return self::SUCCESS;
    }
}

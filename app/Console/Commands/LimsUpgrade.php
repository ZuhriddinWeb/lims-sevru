<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mavjud serverni yangilash — migratsiyadan KEYINGI qadamlar, server qaysi holatda bo'lishidan qat'i nazar.
 *
 *   php artisan lims:upgrade --dry-run   — nima bajarilishini ko'rsatadi, hech narsa yozmaydi
 *   php artisan lims:upgrade             — bajaradi
 *
 * Faqat QO'SHADI. Mavjud namuna, natija, varaq, foydalanuvchi, rol sozlamalari va menyu nomlariga tegmaydi:
 *   - WorksheetModuleSeeder FAQAT ishchi varaqlar moduli hali o'rnatilmagan bo'lsa ishlaydi
 *     (u qayta ishga tushsa admin o'zgartirgan menyu nomi va rol ruxsatlarini qaytarib yozadi);
 *   - Interlab / SampleIntake / Insights / SampleRequest / Svodka seederlari — faqat yo'q ruxsat, menyu, rolni yaratadi;
 *   - help:import — yordamchi javoblari (qo'lda tahrirlanganlari o'zgarmaydi);
 *   - worksheets:add-totals — shablonlarga «varaq bo'yicha jami» (mavjud varaqlarga emas);
 *   - worksheets:eritish-stages --open — barcha shablonlarda «Eritdi» + «Qaynatib eritdi» (tasdiqlangan varaqlarga emas).
 *   Shablonga tegadigan bu ikki qadam BIR MARTALIK: bajarilgach app_settings da belgi qoladi va keyingi
 *   yangilashlarda qayta ishlamaydi — admin keyin shablonni qanday tahrirlasa (jamini olib tashlasa,
 *   bosqichni o'zgartirsa), yangilash uni qaytarib yozmaydi.
 * DatabaseSeeder / UserSeeder HECH QACHON chaqirilmaydi (ular foydalanuvchi parollarini qayta yozadi).
 * Bajarilmagan migratsiya bo'lsa — to'xtaydi: avval  php artisan migrate --force.
 * Qayta ishga tushirish xavfsiz.
 */
class LimsUpgrade extends Command
{
    protected $signature = 'lims:upgrade {--dry-run : Faqat ko\'rsatish, yozmaslik}';

    protected $description = "Yangilashdan keyingi xavfsiz qadamlar: modullar (ruxsat, menyu), yordamchi, varaq jamilari";

    /** Har doim xavfsiz (faqat yo'q narsani yaratadi) — tartib muhim */
    private const SAFE_SEEDERS = [
        'InterlabModuleSeeder'      => 'Laboratoriyalararo tekshiruv',
        'SampleIntakeModuleSeeder'  => "Buyurtmachi bo'limlar, portal, namuna miqdori",
        'InsightsModuleSeeder'      => 'Smenalar, xodimlar faoliyati, audit, savatcha',
        'SampleRequestModuleSeeder' => 'Qoralama buyurtma',
        'SvodkaModuleSeeder'        => 'Jamlanma hisobot (svodka), natijalar tahlili, buyurtma shablonlari — ruxsat va menyu',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 0) Migratsiyalar to'liq bajarilganmi
        $pending = $this->pendingMigrations();
        if ($pending) {
            $this->error('Bajarilmagan migratsiyalar bor — avval:  php artisan migrate --force');
            foreach ($pending as $m) {
                $this->line("   - $m");
            }

            return self::FAILURE;
        }

        // SQL Server id turi tuzatishi (natija kiritishdagi 404) ulanganmi — u yo'qolsa natija kiritish buziladi
        $dbTypes = \App\Providers\DatabaseTypesServiceProvider::class;
        if (! class_exists($dbTypes) || ! app()->getProvider($dbTypes)) {
            $this->error("DatabaseTypesServiceProvider ulanmagan — SQL Server'da natija kiritish 404 beradi.");
            $this->line("app/Providers/SampleIntakeServiceProvider.php da uni ro'yxatdan o'tkazadigan qator bo'lishi kerak");
            $this->line("(tuzatish_natija_kiritish + Paket 24 tuzatish 3). Hech narsa o'zgarmadi.");

            return self::FAILURE;
        }

        // Kod to'liqmi: kerakli seeder fayli yo'q bo'lsa — jimgina o'tkazib yubormaymiz, to'xtaymiz
        $wsInstalled = Permission::where('name', 'worksheets.view')->exists();
        $required = array_merge($wsInstalled ? [] : ['WorksheetModuleSeeder'], array_keys(self::SAFE_SEEDERS));
        $missing = array_values(array_filter($required, fn ($c) => ! class_exists("Database\\Seeders\\$c")));
        if ($missing) {
            $this->error("Kod to'liq kelmagan — seeder fayllari yo'q:");
            foreach ($missing as $c) {
                $this->line("   - database/seeders/$c.php");
            }
            $this->line("Lokalda: git add database/seeders → commit → push; serverda: git pull. Hech narsa o'zgarmadi.");

            return self::FAILURE;
        }

        $before = $this->counts();
        $this->info($dry ? '[SINOV] hech narsa yozilmaydi' : 'Yangilash');

        // 1) Ishchi varaqlar — faqat birinchi marta
        if ($wsInstalled) {
            $this->line("  = Ishchi varaqlar: o'rnatilgan — seeder ishlatilmaydi (menyu nomi va rol sozlamalari saqlanadi)");
        } else {
            $this->line("  + Ishchi varaqlar: birinchi o'rnatish — WorksheetModuleSeeder");
            if (! $dry && ! $this->seed('WorksheetModuleSeeder')) {
                return self::FAILURE;
            }
        }

        // 2) Qolgan modullar — doim xavfsiz
        foreach (self::SAFE_SEEDERS as $class => $label) {
            $this->line("  + $label: $class (faqat yo'q ruxsat/menyu)");
            if (! $dry && ! $this->seed($class)) {
                return self::FAILURE;
            }
        }

        // 3) Yordamchi
        if (Schema::hasTable('help_entries') && is_file(database_path('help/help_qa.json'))) {
            $this->line("  + Yordamchi: help:import (qo'lda tahrirlangan javoblar o'zgarmaydi)");
            if (! $dry) {
                $this->call('help:import');
            }
        } else {
            $this->warn("  ! Yordamchi: help_entries jadvali yoki database/help/help_qa.json yo'q — o'tkazildi");
        }

        // 4) Varaq bo'yicha jami — faqat shablonlar (BIR MARTALIK)
        $this->once('worksheets_add_totals', "Ishchi varaq shablonlari: «varaq bo'yicha jami» (mavjud varaqlarga emas)",
            'worksheets:add-totals', [], $dry);

        // 5) Bosqichlar: «Eritdi» + Kupelyatsiyadan keyin «Qaynatib eritdi» — barcha shablon + tasdiqlanmagan varaqlar.
        //    Faqat standart holatdagi (admin o'zgartirmagan) bosqichlarga; tasdiqlangan varaqlar o'zgarmaydi. (BIR MARTALIK)
        $this->once('worksheets_eritish_stages', 'Bosqichlar: … → Eritdi → Kupelyatsiya → Qaynatib eritdi → … (barcha shablonlar)',
            'worksheets:eritish-stages', ['--open' => true], $dry);

        // Natija
        $after = $this->counts();
        $this->line('');
        $this->info('Natija (oldin → keyin):');
        foreach ($after as $k => $v) {
            $b = $before[$k] ?? 0;
            $this->line(sprintf('  %-26s %5d → %5d%s', $k, $b, $v, $v === $b ? '' : sprintf('   (+%d)', $v - $b)));
        }
        if ($after['namunalar'] !== $before['namunalar'] || $after['foydalanuvchilar'] !== $before['foydalanuvchilar']) {
            $this->error("DIQQAT: namuna yoki foydalanuvchi soni o'zgardi — bu kutilmagan. Xabar bering.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Shablonga tegadigan bir martalik qadam: bajarilgan bo'lsa — o'tkaziladi.
     * Belgi: app_settings.key = «upgrade.<nom>» (jadval bo'lmasa — har safar ishlaydi, avvalgidek).
     */
    private function once(string $name, string $label, string $command, array $args, bool $dry): void
    {
        if (! $this->getApplication()->has($command)) {
            return;
        }
        $key = 'upgrade.' . $name;
        $hasTable = Schema::hasTable('app_settings');
        $doneAt = $hasTable ? DB::table('app_settings')->where('key', $key)->value('updated_at') : null;
        if ($doneAt) {
            $this->line("  = {$label}: bir martalik — bajarilgan ({$doneAt}); shablonlardagi keyingi o'zgarishlar adminniki");

            return;
        }
        $this->line("  + {$label}");
        $code = $this->call($command, $args + ($dry ? ['--dry-run' => true] : []));
        if (! $dry && $code === 0 && $hasTable) {
            DB::table('app_settings')->insert([
                'key' => $key, 'value' => json_encode(['command' => $command]), 'updated_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seed(string $class): bool
    {
        try {
            $code = $this->call('db:seed', ['--class' => $class, '--force' => true]);
        } catch (Throwable $e) {
            $this->error("$class: " . $e->getMessage());

            return false;
        }
        if ($code !== 0) {
            $this->error("$class xato bilan tugadi");

            return false;
        }

        return true;
    }

    /** @return string[] */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['(migrations jadvali yo\'q — bu yangi baza; SERVERGA_NOLDAN_ORNATISH.txt ga qarang)'];
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    private function counts(): array
    {
        $n = fn (string $table) => Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;

        return [
            'namunalar'          => $n('samples'),
            'foydalanuvchilar'   => $n('users'),
            'ruxsatlar'          => Permission::count(),
            'rollar'             => Role::count(),
            'rol-ruxsat'         => $n('permission_role'),
            'menyu'              => MenuItem::count(),
            'varaq shablonlari'  => $n('worksheet_templates'),
            'shablon ustunlari'  => $n('worksheet_template_columns'),
            'yordamchi javoblari'=> $n('help_entries'),
        ];
    }
}

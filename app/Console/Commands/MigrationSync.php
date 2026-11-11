<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * «migrations» jadvali bazadagi haqiqiy holatdan orqada qolgan bo'lsa (jadvallar bor, lekin migratsiya
 * «Pending» — masalan baza boshqa joydan tiklangan) — shuni aniqlaydi va to'g'rilaydi.
 *
 *   php artisan lims:migratsiya-tuzat          — faqat hisobot, HECH NARSA O'ZGARMAYDI
 *   php artisan lims:migratsiya-tuzat --yoz    — «BOR» deb topilganlarni migrations jadvaliga «bajarilgan» deb yozadi
 *
 * Har «Pending» migratsiyaning up() qismi o'qiladi: yaratadigan jadvallari va qo'shadigan ustunlari bazada
 * bormi — tekshiriladi.
 *   BOR       — hammasi bazada bor: migratsiya aslida bajarilgan → --yoz bilan belgilanadi
 *   YO'Q      — hech biri yo'q: oddiy  php artisan migrate  bajaradi
 *   QISMAN    — bir qismi bor: belgilanmaydi, qo'lda ko'rish kerak (ro'yxat chiqadi)
 *   NOMA'LUM  — tekshiriladigan jadval/ustun topilmadi (masalan faqat indeks): belgilanmaydi
 *
 * Bazadagi jadval va ma'lumotlarga TEGMAYDI — faqat migrations jadvaliga qator qo'shadi.
 */
class MigrationSync extends Command
{
    protected $signature = 'lims:migratsiya-tuzat {--yoz : BOR deb topilganlarni bajarilgan deb belgilash}';

    protected $description = "Pending migratsiyalar bazada aslida bormi — tekshirish (va --yoz bilan belgilash)";

    private const COLUMN_TYPES = [
        'string', 'char', 'text', 'mediumText', 'longText', 'integer', 'tinyInteger', 'smallInteger', 'mediumInteger',
        'bigInteger', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger',
        'unsignedBigInteger', 'boolean', 'decimal', 'unsignedDecimal', 'float', 'double', 'date', 'dateTime',
        'dateTimeTz', 'time', 'timestamp', 'timestampTz', 'json', 'jsonb', 'enum', 'foreignId', 'uuid', 'ulid',
        'binary', 'year', 'ipAddress',
    ];

    public function handle(): int
    {
        $table = config('database.migrations.table', 'migrations');
        if (is_array(config('database.migrations'))) {
            $table = config('database.migrations.table') ?: 'migrations';
        } elseif (is_string(config('database.migrations'))) {
            $table = config('database.migrations');
        }

        try {
            if (! Schema::hasTable($table)) {
                $this->error("«{$table}» jadvali yo'q — bu buyruq kerak emas. Hech narsa o'zgarmadi.");

                return self::FAILURE;
            }
            $ran = DB::table($table)->pluck('migration')->all();
        } catch (Throwable $e) {
            $this->error("Bazaga ulanib bo'lmadi: " . $e->getMessage());

            return self::FAILURE;
        }

        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);
        $rows = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $ran, true)) {
                continue;
            }
            [$status, $have, $miss] = $this->inspect((string) file_get_contents($file));
            $rows[] = compact('name', 'status', 'have', 'miss');
        }

        if (! $rows) {
            $this->info("Pending migratsiya yo'q — hammasi joyida.");

            return self::SUCCESS;
        }

        $this->line('');
        foreach ($rows as $r) {
            $tag = match ($r['status']) { 'BOR' => 'info', "YO'Q" => 'comment', default => 'error' };
            $this->line(sprintf('  <%s>%-9s</%s> %s', $tag, $r['status'], $tag, $r['name']));
            if ($r['status'] === 'QISMAN') {
                $this->line('            bor:  ' . implode(', ', $r['have']));
                $this->line('            yo\'q: ' . implode(', ', $r['miss']));
            }
        }

        $count = fn ($s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));
        $this->line('');
        $this->line(sprintf("BOR (aslida bajarilgan): %d   YO'Q (migrate bajaradi): %d   QISMAN: %d   NOMA'LUM: %d",
            $count('BOR'), $count("YO'Q"), $count('QISMAN'), $count("NOMA'LUM")));

        $mark = array_values(array_filter($rows, fn ($r) => $r['status'] === 'BOR'));
        if (! $this->option('yoz')) {
            $this->line('');
            $this->line("Bu faqat hisobot — hech narsa o'zgarmadi.");
            if ($mark) {
                $this->line("BOR qatorlarini bajarilgan deb belgilash:  php artisan lims:migratsiya-tuzat --yoz");
            }

            return self::SUCCESS;
        }

        if (! $mark) {
            $this->line("Belgilanadigan migratsiya yo'q.");

            return self::SUCCESS;
        }

        $batch = (int) DB::table($table)->max('batch') + 1;
        DB::table($table)->insert(array_map(fn ($r) => ['migration' => $r['name'], 'batch' => $batch], $mark));
        $this->info(sprintf('%d ta migratsiya bajarilgan deb belgilandi (batch %d).', count($mark), $batch));
        $left = count($rows) - count($mark);
        if ($left) {
            $this->line("Qolgan {$left} tasi:  php artisan migrate:status  — keyin  php artisan migrate");
            $this->line("(QISMAN yoki NOMA'LUM bo'lsa — avval ro'yxatni yuboring.)");
        }

        return self::SUCCESS;
    }

    /** @return array{0:string,1:array<int,string>,2:array<int,string>} */
    private function inspect(string $src): array
    {
        $up = $src;
        if (preg_match('/function\s+up\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $up = substr($src, $m[0][1]);
            if (preg_match('/function\s+down\s*\(/', $up, $d, PREG_OFFSET_CAPTURE)) {
                $up = substr($up, 0, $d[0][1]);
            }
        }

        $types = implode('|', self::COLUMN_TYPES);
        $re = "/Schema::(create|table)\\(\\s*'(\\w+)'"
            . "|\\$\\w+->({$types})\\(\\s*'(\\w+)'"
            . "|::foreignId\\(\\s*\\$\\w+\\s*,\\s*'(\\w+)'/";
        preg_match_all($re, $up, $all, PREG_SET_ORDER);

        $checks = [];      // ['t' => table, 'c' => column|null]
        $current = null;
        $mode = null;
        foreach ($all as $m) {
            if (! empty($m[1])) {
                $mode = $m[1];
                $current = $m[2];
                if ($mode === 'create') {
                    $checks["t:{$current}"] = ['t' => $current, 'c' => null];
                }
                continue;
            }
            $col = ($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? '');
            if ($current && $mode === 'table' && $col !== '') {
                $checks["c:{$current}.{$col}"] = ['t' => $current, 'c' => $col];
            }
        }

        if (! $checks) {
            return ["NOMA'LUM", [], []];
        }

        $have = [];
        $miss = [];
        foreach ($checks as $c) {
            $label = $c['c'] ? "{$c['t']}.{$c['c']}" : $c['t'];
            $ok = $c['c'] ? Schema::hasTable($c['t']) && Schema::hasColumn($c['t'], $c['c']) : Schema::hasTable($c['t']);
            $ok ? $have[] = $label : $miss[] = $label;
        }

        $status = ! $miss ? 'BOR' : (! $have ? "YO'Q" : 'QISMAN');

        return [$status, $have, $miss];
    }
}

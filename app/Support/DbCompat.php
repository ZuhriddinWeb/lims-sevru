<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Baza-mustaqil migratsiya yordamchisi.
 *
 * Maqsad: bitta loyiha MySQL va SQL Server'da ham ishlashi.
 * SQL Server ikkita cheklovga ega:
 *   1. Bitta jadvalga bir nechta "ON DELETE SET NULL / CASCADE" yo'li bo'lmaydi.
 *   2. Unique indeksda faqat bitta NULL qiymatга ruxsat beradi (MySQL cheksiz).
 *
 * Shuning uchun SQL Server'da ba'zi foreign key'lar oddiy indeksga aylanadi
 * (bog'lanish Eloquent darajasida saqlanadi), va NULL-mumkin unique maydonlar
 * filtrlangan indeks bilan yaratiladi.
 */
class DbCompat
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function isSqlServer(): bool
    {
        return self::driver() === 'sqlsrv';
    }

    public static function isMySql(): bool
    {
        return in_array(self::driver(), ['mysql', 'mariadb'], true);
    }

    /**
     * Foreign key qo'shish — SQL Server xavfli bo'lsa, faqat indeks.
     *
     * @param  bool  $safeForSqlServer  true bo'lsa SQL Server'da ham FK qo'yiladi
     *                                   (jadvalga yagona SET NULL/CASCADE yo'li bo'lganda).
     */
    public static function foreignId(
        Blueprint $table,
        string $column,
        string $references = 'id',
        string $on = '',
        string $onDelete = 'nullOnDelete',
        bool $nullable = true,
        bool $safeForSqlServer = false
    ): void {
        // Ustunni yaratish
        $col = $table->unsignedBigInteger($column);
        if ($nullable) {
            $col->nullable();
        }

        // SQL Server'da xavfli FK — faqat indeks
        if (self::isSqlServer() && ! $safeForSqlServer) {
            $table->index($column);
            return;
        }

        // Boshqa bazalarda yoki xavfsiz holatda — to'liq FK
        $table->index($column);
        $fk = $table->foreign($column)->references($references)->on($on);

        match ($onDelete) {
            'cascadeOnDelete' => $fk->cascadeOnDelete(),
            'nullOnDelete'    => $fk->nullOnDelete(),
            'restrictOnDelete'=> $fk->restrictOnDelete(),
            default           => $fk->nullOnDelete(),
        };
    }

    /**
     * NULL-mumkin unique indeks.
     * SQL Server: filtrlangan indeks (WHERE col IS NOT NULL).
     * MySQL: oddiy unique (u bir nechta NULL'ni baribir qabul qiladi).
     */
    public static function nullableUnique(string $tableName, string $column): void
    {
        $indexName = "{$tableName}_{$column}_unique";

        if (self::isSqlServer()) {
            DB::statement(
                "CREATE UNIQUE INDEX {$indexName} ON {$tableName} ({$column}) WHERE {$column} IS NOT NULL"
            );
        } else {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->unique($column);
            });
        }
    }

    /**
     * FK'ni xavfsiz o'chirish (down() uchun) — faqat FK mavjud bo'lsa.
     */
    public static function dropForeignIfExists(string $tableName, string $column): void
    {
        if (self::isSqlServer()) {
            // SQL Server'da FK yaratilmagan bo'lishi mumkin — indeks o'chiriladi
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
            });
        } catch (\Throwable $e) {
            // FK yo'q bo'lsa — e'tiborsiz
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [P19] Audit jurnali tez ochilishi uchun indekslar (yozuvlar ko'payganda).
 *   activity_logs(created_at)          — sana bo'yicha tartib va davr filtri
 *   activity_logs(user_id, created_at) — xodim bo'yicha filtr
 * Faqat INDEKS qo'shiladi — ma'lumotga tegilmaydi. Indeks bor bo'lsa — o'tkazib yuboriladi.
 *
 * SQL Server'da mavjud indekslar sys.indexes dan o'qiladi: Laravel'ning Schema::getIndexes()
 * STRING_AGG ... WITHIN GROUP ishlatadi, u bazaning eski moslik darajasida (compatibility level) xato beradi.
 */
return new class extends Migration
{
    private const TABLE = 'activity_logs';

    /** @return array<string, string[]> indeks nomi => ustunlar (kichik harf, tartib bilan) */
    private function indexes(): array
    {
        $conn = Schema::getConnection();
        $table = $conn->getTablePrefix() . self::TABLE;

        if ($conn->getDriverName() === 'sqlsrv') {
            $rows = $conn->select(
                'select i.name as index_name, c.name as column_name, ic.key_ordinal as pos '
                . 'from sys.indexes i '
                . 'join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id '
                . 'join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id '
                . 'where i.object_id = object_id(?) and i.name is not null and ic.is_included_column = 0 '
                . 'order by i.name, ic.key_ordinal',
                [$table]
            );
            $out = [];
            foreach ($rows as $r) {
                $out[strtolower($r->index_name)][] = strtolower($r->column_name);
            }

            return $out;
        }

        $out = [];
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $out[strtolower($index['name'])] = array_map('strtolower', $index['columns']);
        }

        return $out;
    }

    private function has(array $existing, string $name, array $columns): bool
    {
        return isset($existing[$name]) || in_array($columns, array_values($existing), true);
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $existing = $this->indexes();
        $created = ! $this->has($existing, 'activity_logs_created_at_index', ['created_at']);
        $user = ! $this->has($existing, 'activity_logs_user_created_index', ['user_id', 'created_at']);

        if ($created || $user) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($created, $user) {
                if ($created) {
                    $table->index('created_at', 'activity_logs_created_at_index');
                }
                if ($user) {
                    $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $existing = $this->indexes();
        Schema::table(self::TABLE, function (Blueprint $table) use ($existing) {
            if (isset($existing['activity_logs_created_at_index'])) {
                $table->dropIndex('activity_logs_created_at_index');
            }
            if (isset($existing['activity_logs_user_created_index'])) {
                $table->dropIndex('activity_logs_user_created_index');
            }
        });
    }
};

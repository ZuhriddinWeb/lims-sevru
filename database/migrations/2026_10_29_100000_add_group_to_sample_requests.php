<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paket 30 — buyurtma guruhga yuboriladi.
 *
 *   sample_requests.laboratory_group_id — buyurtmachi tanlagan laboratoriya guruhi (namunani o'sha guruh qabul qiladi)
 *   sample_request_items.group_code    — buyurtmachining «guruh shifri» (faqat buyurtmachi va tanlangan guruhga ko'rinadi)
 *   samples.group_code                 — qabulda probaga ko'chiriladi (proba kartochkasida — faqat o'sha guruhga)
 *
 * Faqat bo'sh (nullable) ustunlar qo'shadi; mavjud ma'lumotga tegmaydi. Eski buyurtmalarda guruh yo'q —
 * ular avvalgidek barcha xodimlarga ko'rinadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sample_requests') && ! Schema::hasColumn('sample_requests', 'laboratory_group_id')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('laboratory_group_id')->nullable()->index();
            });
        }
        if (Schema::hasTable('sample_request_items') && ! Schema::hasColumn('sample_request_items', 'group_code')) {
            Schema::table('sample_request_items', function (Blueprint $table) {
                $table->string('group_code', 64)->nullable();
            });
        }
        if (Schema::hasTable('samples') && ! Schema::hasColumn('samples', 'group_code')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->string('group_code', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('samples', 'group_code')) {
            Schema::table('samples', fn (Blueprint $table) => $table->dropColumn('group_code'));
        }
        if (Schema::hasColumn('sample_request_items', 'group_code')) {
            Schema::table('sample_request_items', fn (Blueprint $table) => $table->dropColumn('group_code'));
        }
        if (Schema::hasColumn('sample_requests', 'laboratory_group_id')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->dropIndex(['laboratory_group_id']);
                $table->dropColumn('laboratory_group_id');
            });
        }
    }
};

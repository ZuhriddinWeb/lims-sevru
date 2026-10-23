<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtma: namuna qaysi SMENADA olib kelinadi (Paket 28).
 *   delivery_shift_id — lab_shifts.id (faqat indeks; bog'lanish Eloquent darajasida)
 *   delivery_shift    — tanlangan paytdagi nomi va vaqti («Kunduzgi 08:00–20:00»): smena keyin
 *                       o'zgartirilsa yoki o'chirilsa ham buyurtmada yozilgani saqlanadi
 * Faqat ustun qo'shadi; mavjud buyurtmalarga tegmaydi (ular uchun bo'sh).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sample_requests')) {
            return;
        }
        if (! Schema::hasColumn('sample_requests', 'delivery_shift_id')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_shift_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('sample_requests', 'delivery_shift')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->string('delivery_shift', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sample_requests', 'delivery_shift')) {
            Schema::table('sample_requests', fn (Blueprint $t) => $t->dropColumn('delivery_shift'));
        }
        if (Schema::hasColumn('sample_requests', 'delivery_shift_id')) {
            Schema::table('sample_requests', function (Blueprint $t) {
                $t->dropIndex(['delivery_shift_id']);
                $t->dropColumn('delivery_shift_id');
            });
        }
    }
};

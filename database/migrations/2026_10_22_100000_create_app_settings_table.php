<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tizim sozlamalari (kalit → qiymat) — Paket 27.
 * Hozircha: portal.visibility — buyurtmachi portalida nima ko'rinishi (admin belgilaydi).
 * Faqat yangi jadval; mavjud ma'lumotga tegmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->text('value')->nullable();               // JSON
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};

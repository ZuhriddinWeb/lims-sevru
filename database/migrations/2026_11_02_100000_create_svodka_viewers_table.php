<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 31] Svodkani buyurtmachidek ko'radigan xodimlar — admin har bir foydalanuvchiga alohida beradi
 * (rol yaratish shart emas). Faqat yangi jadval; mavjud ma'lumotga tegmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('svodka_viewers')) {
            return;
        }
        Schema::create('svodka_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('granted_by')->nullable();   // FK yo'q: SQL Server'da bir nechta kaskad yo'li bo'lmasin
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('svodka_viewers');
    }
};

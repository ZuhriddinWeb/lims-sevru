<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smenalar va ishchi varaq imzosiga soat.
 *
 * Faqat QO'SHADI — mavjud ma'lumot o'zgarmaydi:
 *   lab_shifts                         — laboratoriya smenalari (nomi, boshlanish va tugash soati «HH:MM»).
 *                                        Tugash boshlanishdan kichik bo'lsa — smena yarim tundan o'tadi
 *                                        (masalan 20:00–08:00) va u boshlangan kunga tegishli.
 *   worksheet_signoffs.performed_time  — bosqich bajarilgan soat «HH:MM» (ixtiyoriy; sana avvalgidek performed_at da)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_shifts')) {
            Schema::create('lab_shifts', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64);
                $table->string('start_time', 5);   // HH:MM
                $table->string('end_time', 5);     // HH:MM
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('worksheet_signoffs') && ! Schema::hasColumn('worksheet_signoffs', 'performed_time')) {
            Schema::table('worksheet_signoffs', function (Blueprint $table) {
                $table->string('performed_time', 5)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('worksheet_signoffs', 'performed_time')) {
            Schema::table('worksheet_signoffs', function (Blueprint $table) {
                $table->dropColumn('performed_time');
            });
        }
        Schema::dropIfExists('lab_shifts');
    }
};

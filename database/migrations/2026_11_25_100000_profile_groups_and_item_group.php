<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Paket 42] Mas'ul guruh — sinov profilida; buyurtmada har namunaga guruh; grafikda hisobot kuni.
 *
 *   test_profile_groups                         — profilga mas'ul guruhlar (tartib bilan; 1-si — sukut bo'yicha)
 *   sample_request_items.laboratory_group_id    — namuna qaysi guruhga (bo'sh — profilning 1-guruhi)
 *   sample_requests.split_key                   — bir formadan guruhlar bo'yicha bo'lingan buyurtmalar
 *   sample_requests.schedule_run_id             — grafik partiyasi (bo'lingan buyurtmalar ham bog'lanadi)
 *   customer_departments.schedule_svodka_manual — grafikda hisobot kuni/navbatchiligini buyurtmachi belgilaydi
 *
 * Faqat qo'shadi; mavjud ma'lumot o'zgarmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('test_profile_groups')) {
            Schema::create('test_profile_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('test_profile_id')->index();
                $table->unsignedBigInteger('laboratory_group_id')->index();
                $table->unsignedInteger('sort_order')->default(1);
                $table->timestamps();
                $table->unique(['test_profile_id', 'laboratory_group_id']);
            });
        }
        if (! Schema::hasColumn('sample_request_items', 'laboratory_group_id')) {
            Schema::table('sample_request_items', function (Blueprint $table) {
                $table->unsignedBigInteger('laboratory_group_id')->nullable()->index();
            });
        }
        Schema::table('sample_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('sample_requests', 'split_key')) {
                $table->string('split_key', 36)->nullable()->index();
            }
            if (! Schema::hasColumn('sample_requests', 'schedule_run_id')) {
                $table->unsignedBigInteger('schedule_run_id')->nullable()->index();
            }
        });
        if (! Schema::hasColumn('customer_departments', 'schedule_svodka_manual')) {
            Schema::table('customer_departments', function (Blueprint $table) {
                $table->boolean('schedule_svodka_manual')->default(false);
            });
        }
    }

    public function down(): void
    {
        // Serverlarda rollback ishlatilmaydi (faqat oldinga)
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtmachi svodkasi — Paket 29.
 *
 *   svodka_settings     — bo'lim uchun svodka rejimi (laboratoriya yoqadi): navbatchilik soatlari, chiqarish birligi
 *   svodka_sections     — buyurtmachi spravochnigi: bloklar (asosiy jadval, «Boyitilgan ko'mir» …) va ustunlari
 *   svodka_points       — buyurtmachi spravochnigi: namuna nomlari (nuqtalar)
 *   svodka_assignments  — namuna ↔ nuqta (buyurtmachi qo'lda bog'lagani; buyurtma orqali kelgani — so'rov qatorida)
 *   svodka_releases     — laboratoriya boshlig'i chiqargan davr (sutka yoki navbatchilik) va o'sha paytdagi natijalar nusxasi
 *   sample_request_items.svodka_point_id, sample_requests.svodka_date/svodka_shift — buyurtmadagi tanlov
 *
 * Nuqta nomlari laboratoriyaga hech qayerda ko'rsatilmaydi (ko'r tahlil).
 * Faqat yangi jadval va bo'sh ustunlar qo'shadi; mavjud ma'lumotga tegmaydi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('svodka_settings')) {
            Schema::create('svodka_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('release_mode', 10)->default('day');          // day | shift
                $table->string('shift1_label', 60)->default('I-navbatchilik');
                $table->string('shift2_label', 60)->default('II-navbatchilik');
                $table->string('shift1_start', 5)->default('20:00');         // I — oldingi kun 20:00 dan
                $table->string('shift2_start', 5)->default('08:00');         // II — shu kun 08:00 dan
                $table->string('title', 150)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('svodka_sections')) {
            Schema::create('svodka_sections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->string('title', 150);
                $table->string('layout', 10)->default('shift');              // shift | day | list
                $table->text('columns')->nullable();                         // JSON: [{element_id, label}]
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('svodka_points')) {
            Schema::create('svodka_points', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->unsignedBigInteger('section_id')->index();
                $table->string('name', 150);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('daily')->default(false);                    // sutkalik — kuniga bir marta
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('svodka_assignments')) {
            Schema::create('svodka_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->unsignedBigInteger('sample_id')->unique();
                $table->unsignedBigInteger('point_id')->index();
                $table->date('production_date')->index();
                $table->unsignedTinyInteger('shift');                        // 1 | 2
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('svodka_releases')) {
            Schema::create('svodka_releases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_department_id')->index();
                $table->date('production_date')->index();
                $table->unsignedTinyInteger('shift')->nullable();            // null — butun sutka
                $table->unsignedSmallInteger('revision')->default(1);
                $table->text('payload')->nullable();                         // JSON: {sample_id: {element_id: [value, unit]}}
                $table->unsignedInteger('samples_count')->default(0);
                $table->unsignedInteger('values_count')->default(0);
                $table->unsignedBigInteger('released_by')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('sample_request_items') && ! Schema::hasColumn('sample_request_items', 'svodka_point_id')) {
            Schema::table('sample_request_items', function (Blueprint $table) {
                $table->unsignedBigInteger('svodka_point_id')->nullable()->index();
            });
        }
        if (Schema::hasTable('sample_requests') && ! Schema::hasColumn('sample_requests', 'svodka_date')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->date('svodka_date')->nullable();
            });
        }
        if (Schema::hasTable('sample_requests') && ! Schema::hasColumn('sample_requests', 'svodka_shift')) {
            Schema::table('sample_requests', function (Blueprint $table) {
                $table->unsignedTinyInteger('svodka_shift')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['svodka_releases', 'svodka_assignments', 'svodka_points', 'svodka_sections', 'svodka_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('sample_request_items', 'svodka_point_id')) {
            Schema::table('sample_request_items', function (Blueprint $t) {
                $t->dropIndex(['svodka_point_id']);
                $t->dropColumn('svodka_point_id');
            });
        }
        foreach (['svodka_date', 'svodka_shift'] as $c) {
            if (Schema::hasColumn('sample_requests', $c)) {
                Schema::table('sample_requests', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};

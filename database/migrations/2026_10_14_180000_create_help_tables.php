<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yordamchi (lokal "AI"): savol-javoblar, javobsiz savollar, baho.
 * SQL Server uchun: tashqi kalitlar yo'q (cascade yo'llari bilan muammo bo'lmasin), uzun matnlar — nvarchar(max).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('help_entries')) {
            Schema::create('help_entries', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 120)->unique();          // masalan: samples.register
                $table->string('section', 120)->nullable();      // bo'lim nomi (guruhlash uchun)
                $table->string('route', 80)->nullable();         // «Sahifani ochish» uchun marshrut nomi
                $table->string('permission', 80)->nullable();    // bo'sh — hamma ko'radi
                $table->string('type', 20)->default('howto');    // howto, field, modal, status, rule, role, trouble, info
                $table->boolean('is_top')->default(false);       // boshlang'ich takliflarda chiqadi
                $table->text('keywords')->nullable();            // JSON: ["parol","password",...]
                $table->string('q_uz', 400);
                $table->string('q_ru', 400);
                $table->string('q_en', 400);
                $table->text('alt_uz')->nullable();              // JSON: qo'shimcha so'rovlar
                $table->text('alt_ru')->nullable();
                $table->text('alt_en')->nullable();
                $table->text('a_uz');
                $table->text('a_ru');
                $table->text('a_en');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_custom')->default(false);    // administrator qo'shgan
                $table->boolean('is_edited')->default(false);    // administrator o'zgartirgan (import ustidan yozmaydi)
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'is_top']);
            });
        }

        if (! Schema::hasTable('help_unanswered')) {
            Schema::create('help_unanswered', function (Blueprint $table) {
                $table->id();
                $table->char('question_hash', 40)->unique();     // sha1(normallashtirilgan savol)
                $table->string('question', 400);                 // foydalanuvchi yozganidek (oxirgisi)
                $table->string('locale', 5)->nullable();
                $table->string('route', 80)->nullable();         // qaysi sahifada turib so'ragan
                $table->unsignedBigInteger('user_id')->nullable(); // oxirgi so'ragan
                $table->unsignedBigInteger('best_entry_id')->nullable();
                $table->decimal('best_score', 5, 4)->default(0);
                $table->unsignedInteger('asked_count')->default(1);
                $table->string('status', 12)->default('new');    // new | resolved | ignored
                $table->unsignedBigInteger('resolved_entry_id')->nullable();
                $table->timestamp('last_asked_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_asked_at']);
            });
        }

        if (! Schema::hasTable('help_feedback')) {
            Schema::create('help_feedback', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entry_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->boolean('helpful');
                $table->string('question', 400)->nullable();
                $table->string('locale', 5)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_unanswered');
        Schema::dropIfExists('help_entries');
    }
};

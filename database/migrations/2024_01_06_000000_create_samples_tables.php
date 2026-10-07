<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Probalar (Пробы) — TZ: proba harakati sxemasi
        // Texnologik laboratoriya qabul qiladi → shifr beradi → Analitik lab guruhiga uzatadi
        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // Noyob shifr (avtomatik)
            $table->string('sample_number');           // Proba raqami

            // Qayerdan kelgani — hujjat raqami / joylashuv (podrazdelenie)
            $table->string('source_document')->nullable();
            $table->string('source_division')->nullable();

            // Buyurtmachi guruh (Гравитация, Цианирование, Геологическая)
            DbCompat::foreignId($table, 'customer_group_id', on: 'laboratory_groups');

            // Ro'yxatga oluvchi lab/guruh (Texnologik) — yagona yo'l, FK xavfsiz
            DbCompat::foreignId($table, 'registering_lab_id', on: 'laboratories', safeForSqlServer: true);
            DbCompat::foreignId($table, 'registering_group_id', on: 'laboratory_groups');

            // Kimga uzatiladi (Спектральная / Химическая guruh)
            DbCompat::foreignId($table, 'assigned_group_id', on: 'laboratory_groups');

            // Proba holati: suyuq / qattiq
            $table->enum('state_of_matter', ['liquid', 'solid'])->default('solid');

            // Umumiy status
            $table->enum('status', [
                'registered',   // Ro'yxatga olingan
                'assigned',     // Guruhga biriktirilgan
                'in_progress',  // Ishlanmoqda
                'completed',    // Natijalar chiqdi
                'archived'      // Arxivlangan
            ])->default('registered');

            // Oylar bo'yicha ajratish uchun (TZ: oylar bo'yicha alohida)
            $table->string('period_month', 7)->index(); // YYYY-MM

            DbCompat::foreignId($table, 'registered_by', on: 'users', safeForSqlServer: true);
            $table->timestamp('registered_at')->nullable(); // avtomatik
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();   // avtomatik

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('sample_number');
            $table->index(['assigned_group_id', 'status']);
        });

        // Probada aniqlanadigan elementlar + natijalar
        // (Bitta probada bir yoki bir nechta element bo'lishi mumkin)
        Schema::create('sample_elements', function (Blueprint $table) {
            $table->id();
            // sample_id — asosiy cascade (yagona, xavfsiz)
            DbCompat::foreignId($table, 'sample_id', on: 'samples', onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            // element_id — SQL Server'da ikkinchi cascade yo'liga ruxsat yo'q, indeks
            DbCompat::foreignId($table, 'element_id', on: 'elements', onDelete: 'cascadeOnDelete', nullable: false);

            // Natija
            $table->decimal('result_value', 20, 6)->nullable();
            $table->string('result_unit')->nullable();
            $table->enum('status', ['pending', 'done'])->default('pending');
            DbCompat::foreignId($table, 'result_entered_by', on: 'users', safeForSqlServer: true);
            $table->timestamp('result_entered_at')->nullable(); // avtomatik
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sample_id', 'element_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_elements');
        Schema::dropIfExists('samples');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
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
            $table->unsignedBigInteger('customer_group_id')->nullable()->index();

            // Ro'yxatga oluvchi lab/guruh (Texnologik)
            $table->foreignId('registering_lab_id')->nullable()->constrained('laboratories')->nullOnDelete();
            $table->unsignedBigInteger('registering_group_id')->nullable()->index();

            // Kimga uzatiladi (Спектральная / Химическая guruh)
            $table->unsignedBigInteger('assigned_group_id')->nullable()->index();

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

            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
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
            $table->foreignId('sample_id')->constrained('samples')->cascadeOnDelete();
            $table->unsignedBigInteger('element_id');
            $table->index('element_id');

            // Natija
            $table->decimal('result_value', 20, 6)->nullable();
            $table->string('result_unit')->nullable();
            $table->enum('status', ['pending', 'done'])->default('pending');
            $table->foreignId('result_entered_by')->nullable()->constrained('users')->nullOnDelete();
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

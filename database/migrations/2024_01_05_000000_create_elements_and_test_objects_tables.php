<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kimyoviy elementlar / aniqlanadigan ko'rsatkichlar
        Schema::create('elements', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->nullable();   // Au, Ag, Cu...
            $table->string('name_ru');
            $table->string('name_uz');
            $table->string('name_en');
            $table->string('default_unit')->nullable(); // g/t, %, ppm, mg/l
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Sinov obyektlari (Объекты испытания) — TZ 2-bo'lim
        Schema::create('test_objects', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique();   // 2.1 Noyob identifikator
            // 2.2 Obyekt turi
            $table->enum('type', [
                'raw_material', // siryo
                'product',      // produktsiya
                'water',        // suv
                'air',          // havo
                'biomaterial',  // biomaterial
                'other'
            ])->default('other');
            $table->string('name');                    // 2.3 Nomi
            $table->string('code')->nullable();        // kod
            $table->string('batch')->nullable();       // partiya/seriya
            $table->text('description')->nullable();   // 2.4 Tavsif
            $table->text('physical_characteristics')->nullable();
            $table->text('chemical_composition')->nullable();
            $table->string('source_supplier')->nullable(); // 2.5 Manba/yetkazib beruvchi
            $table->date('received_at')->nullable();        // 2.6 Kelib tushgan sana
            $table->text('storage_conditions')->nullable(); // 2.7 Saqlash sharoiti
            $table->text('transport_conditions')->nullable();
            $table->text('accompanying_documents')->nullable(); // 2.8 Hujjatlar
            // 2.9 Holati
            $table->enum('status', [
                'new',         // Yangi
                'in_testing',  // Sinovda
                'tested',      // Sinovdan o'tgan
                'defect',      // Brak
                'disposed'     // Utilizatsiya qilingan
            ])->default('new');
            $table->string('storage_location')->nullable(); // 2.11 Saqlash joyi
            $table->date('storage_expiry')->nullable();      // 2.12 Saqlash muddati
            $table->date('disposed_at')->nullable();         // 2.14 Utilizatsiya sanasi
            $table->string('disposal_act')->nullable();      // Utilizatsiya akti

            $table->foreignId('laboratory_id')->nullable()->constrained('laboratories')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('status');
            $table->index('storage_expiry');
        });

        // Obyektga belgilangan sinov turlari — TZ 2.10
        Schema::create('test_object_element', function (Blueprint $table) {
            $table->foreignId('test_object_id')->constrained('test_objects')->cascadeOnDelete();
            $table->foreignId('element_id')->constrained('elements')->cascadeOnDelete();
            $table->primary(['test_object_id', 'element_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_object_element');
        Schema::dropIfExists('test_objects');
        Schema::dropIfExists('elements');
    }
};

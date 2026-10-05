<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laboratoriyalar (4 ta: ТЛ, Геологическая, Аналитическая, Водная)
        Schema::create('laboratories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();   // TL, GEO, AN, WATER
            $table->string('name_ru');
            $table->string('name_uz');
            $table->string('name_en');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Laboratoriya guruhlari / xizmatlari
        Schema::create('laboratory_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_id')->constrained('laboratories')->cascadeOnDelete();
            $table->string('code')->unique();   // GRAV, CYAN, SPECTR, CHEM, WATER_RES, AIR, RAD
            $table->string('name_ru');
            $table->string('name_uz');
            $table->string('name_en');
            // Guruh roli: customer (zakazchik) yoki executor (bajaruvchi)
            $table->enum('kind', ['customer', 'executor', 'both'])->default('both');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_groups');
        Schema::dropIfExists('laboratories');
    }
};

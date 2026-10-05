<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TZ 2.10 — obyekt kategoriyasiga qarab sinov turlarini avtomatik belgilash
        Schema::create('test_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ru');
            $table->string('name_uz');
            $table->string('name_en');
            // Qaysi obyekt turiga mos: null — barchasiga
            $table->string('object_type')->nullable();
            $table->foreignId('laboratory_id')->nullable()->constrained('laboratories')->nullOnDelete();
            $table->boolean('is_default')->default(false); // tur bo'yicha standart profil
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('object_type');
        });

        Schema::create('test_profile_element', function (Blueprint $table) {
            $table->foreignId('test_profile_id')->constrained('test_profiles')->cascadeOnDelete();
            $table->foreignId('element_id')->constrained('elements')->cascadeOnDelete();
            $table->primary(['test_profile_id', 'element_id']);
        });

        // Monitoring nuqtasi bo'yicha standart ko'rsatkichlar va me'yorlar
        Schema::create('monitoring_point_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitoring_point_id')->constrained('monitoring_points')->cascadeOnDelete();
            $table->string('parameter');
            $table->string('unit')->nullable();
            $table->decimal('norm_min', 20, 6)->nullable();
            $table->decimal('norm_max', 20, 6)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['monitoring_point_id', 'parameter']);
        });

        // Sinov obyektiga profil bog'lash
        Schema::table('test_objects', function (Blueprint $table) {
            $table->foreignId('test_profile_id')->nullable()->after('laboratory_id')
                  ->constrained('test_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('test_objects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('test_profile_id');
        });
        Schema::dropIfExists('monitoring_point_parameters');
        Schema::dropIfExists('test_profile_element');
        Schema::dropIfExists('test_profiles');
    }
};

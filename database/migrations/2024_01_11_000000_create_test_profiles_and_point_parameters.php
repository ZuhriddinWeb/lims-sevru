<?php

use App\Support\DbCompat;
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
            DbCompat::foreignId($table, 'laboratory_id', on: 'laboratories', safeForSqlServer: true);
            $table->boolean('is_default')->default(false); // tur bo'yicha standart profil
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('object_type');
        });

        Schema::create('test_profile_element', function (Blueprint $table) {
            DbCompat::foreignId($table, 'test_profile_id', on: 'test_profiles', onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            DbCompat::foreignId($table, 'element_id', on: 'elements', onDelete: 'cascadeOnDelete', nullable: false);
            $table->primary(['test_profile_id', 'element_id']);
        });

        // Monitoring nuqtasi bo'yicha standart ko'rsatkichlar va me'yorlar
        Schema::create('monitoring_point_parameters', function (Blueprint $table) {
            $table->id();
            DbCompat::foreignId($table, 'monitoring_point_id', on: 'monitoring_points', onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
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
            DbCompat::foreignId($table, 'test_profile_id', on: 'test_profiles');
        });
    }

    public function down(): void
    {
        Schema::table('test_objects', function (Blueprint $table) {
            if (! DbCompat::isSqlServer()) { $table->dropForeign(['test_profile_id']); }
            $table->dropColumn('test_profile_id');
        });
        Schema::dropIfExists('monitoring_point_parameters');
        Schema::dropIfExists('test_profile_element');
        Schema::dropIfExists('test_profiles');
    }
};

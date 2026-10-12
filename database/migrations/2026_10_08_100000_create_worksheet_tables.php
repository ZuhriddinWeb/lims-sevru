<?php

use App\Support\DbCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ishchi varaqlar moduli (sinov uchun alohida modul).
 *
 * Olib tashlash:
 *   php artisan migrate:rollback --path=database/migrations/2026_10_08_100000_create_worksheet_tables.php
 *
 * MySQL va SQL Server: DbCompat orqali — har jadvalda bitta CASCADE yo'li,
 * qolgan bog'lanishlar SQL Server'da indeks bilan (Eloquent darajasida).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Shablonlar (konstruktor) ----
        Schema::create('worksheet_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);                    // forma kodi: 385-2026
            $table->string('version', 20)->nullable();
            $table->date('valid_from')->nullable();
            $table->string('name_uz');
            $table->string('name_ru')->nullable();
            $table->string('name_uz_cyrl')->nullable();
            DbCompat::foreignId($table, 'test_profile_id', on: 'test_profiles', onDelete: 'nullOnDelete');
            $table->string('number_suffix', 20)->nullable(); // 501k → "k"
            $table->unsignedInteger('number_start')->default(1);
            $table->unsignedSmallInteger('default_rows')->default(2);
            $table->string('print_locale', 10)->default('uz');    // uz | uz_cyrl | ru
            $table->string('orientation', 10)->default('landscape');
            $table->json('stages')->nullable();
            $table->json('params')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
            $table->index('is_active');
        });

        Schema::create('worksheet_template_columns', function (Blueprint $table) {
            $table->id();
            DbCompat::foreignId($table, 'template_id', on: 'worksheet_templates',
                onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            $table->string('scope', 10)->default('row');     // row | summary
            $table->string('key', 40);
            $table->string('type', 12)->default('input');    // input | computed | text
            $table->string('stage', 40)->nullable();
            $table->string('label_uz');
            $table->string('label_ru')->nullable();
            $table->string('label_uz_cyrl')->nullable();
            $table->string('unit', 30)->nullable();
            $table->unsignedTinyInteger('decimals')->nullable();
            $table->string('rounding', 8)->default('round'); // round | trunc | none
            $table->text('formula')->nullable();
            $table->string('default_value', 50)->nullable();
            $table->boolean('required')->default(false);
            DbCompat::foreignId($table, 'element_id', on: 'elements', onDelete: 'nullOnDelete');
            $table->decimal('tolerance_pct', 8, 3)->nullable();
            $table->string('tolerance_source', 40)->nullable();
            $table->string('tolerance_mode', 10)->default('mean'); // mean | range
            $table->unsignedSmallInteger('width')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'key']);
        });

        // ---- Ishchi varaqlar (har namunaga alohida) ----
        Schema::create('worksheets', function (Blueprint $table) {
            $table->id();

            // template_id / sample_id — "restrict": varaqli shablon yoki probani
            // butunlay o'chirib bo'lmasin. SQL Server «ON DELETE restrict» so'zini
            // qabul qilmaydi, shuning uchun ON DELETE bandisiz yaratamiz — ikkala
            // bazada ham standart NO ACTION, bu aynan restrict ma'nosini beradi.
            // (NO ACTION — hech qanday cascade yo'li ochmaydi, SQL Server uchun xavfsiz.)
            $table->unsignedBigInteger('template_id');
            $table->index('template_id');
            $table->foreign('template_id')->references('id')->on('worksheet_templates');

            $table->unsignedBigInteger('sample_id');
            $table->index('sample_id');
            $table->foreign('sample_id')->references('id')->on('samples');

            $table->string('series', 40);                  // raqam qo'shimchasi yoki forma kodi
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('seq');
            $table->string('number', 60);                  // 502k

            $table->json('template_snapshot');             // yaratilgan paytdagi shablon nusxasi (ISO 17025)
            $table->date('worksheet_date');
            $table->string('status', 20)->default('draft'); // draft | printed | in_progress | completed | approved
            $table->json('header_values')->nullable();
            $table->json('results')->nullable();           // serverdagi oxirgi hisob
            $table->boolean('has_warnings')->default(false);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('results_pushed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['series', 'year', 'seq']);
            $table->index('status');
            $table->index('worksheet_date');
            $table->index('number');
        });

        Schema::create('worksheet_rows', function (Blueprint $table) {
            $table->id();
            DbCompat::foreignId($table, 'worksheet_id', on: 'worksheets',
                onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            $table->unsignedSmallInteger('row_no');
            $table->json('inputs')->nullable();            // kiritilgan qiymatlar (yozilganidek)
            $table->json('computed')->nullable();
            $table->boolean('excluded')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('worksheet_signoffs', function (Blueprint $table) {
            $table->id();
            DbCompat::foreignId($table, 'worksheet_id', on: 'worksheets',
                onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            $table->string('stage', 40);
            $table->unsignedTinyInteger('slot')->default(1);
            $table->unsignedBigInteger('performer_id')->nullable()->index();
            $table->string('performer_name')->nullable();
            $table->date('performed_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['worksheet_id', 'stage', 'slot']);
        });

        Schema::create('worksheet_scans', function (Blueprint $table) {
            $table->id();
            DbCompat::foreignId($table, 'worksheet_id', on: 'worksheets',
                onDelete: 'cascadeOnDelete', nullable: false, safeForSqlServer: true);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_scans');
        Schema::dropIfExists('worksheet_signoffs');
        Schema::dropIfExists('worksheet_rows');
        Schema::dropIfExists('worksheets');
        Schema::dropIfExists('worksheet_template_columns');
        Schema::dropIfExists('worksheet_templates');
    }
};
